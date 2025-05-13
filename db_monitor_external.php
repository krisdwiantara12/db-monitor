#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 *
 * Versi Terbaru: 1.0.5
 * Fitur:
 * - Notifikasi Telegram dengan nama domain
 * - Auto–update dari GitHub (backup + replace + restart)
 * - Monitoring: MySQL, disk, CPU, memori, keamanan (brute-force SSH)
 * - Auto–restart MySQL
 * - Auto–blokir IP menyerang SSH
 * - Lockfile agar tidak tumpang-tindih
 */

define('LOCAL_VERSION', '1.0.5');
define('GITHUB_REPO',   'krisdwiantara12/db-monitor');
define('GITHUB_BRANCH', 'main');

if (php_sapi_name() !== 'cli') {
    die("Error: Script hanya bisa dijalankan via command line\n");
}

class Config {
    public $logDir, $logFile, $restartLogFile, $lastRestartFile, $lastErrorFile;
    public $securityTimestampFile;
    public $maxRetries    = 3;
    public $retryDelay    = 5;      // detik
    public $autoRestart   = true;
    public $debug         = false;
    public $diskThreshold = 90;     // %
    public $cpuThreshold  = 1.0;    // load average
    public $memThreshold  = 90;     // %
    public $loginFailThreshold = 5; // percobaan per IP
    public $autoBlockIP   = true;
    public $telegramConfigPath;
    public $wpConfigPath;

    public function __construct() {
        $this->logDir               = __DIR__ . '/log_db_monitor';
        $this->logFile              = "{$this->logDir}/db-monitor-log.txt";
        $this->restartLogFile       = "{$this->logDir}/restart-history.log";
        $this->lastRestartFile      = "{$this->logDir}/last_restart.txt";
        $this->lastErrorFile        = "{$this->logDir}/last_error.json";
        $this->securityTimestampFile= "{$this->logDir}/last_security_check.txt";
        $this->telegramConfigPath   = __DIR__ . '/telegram_config.json';
        $this->wpConfigPath         = __DIR__ . '/wp-config.php';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}

class Logger {
    private $file;
    public function __construct($file) {
        $this->file = $file;
    }
    public function log($msg) {
        $entry = "[" . date('Y-m-d H:i:s') . "] $msg\n";
        file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX);
    }
}

class TelegramNotifier {
    private $token, $chatId, $logger;
    public function __construct($configPath, Logger $logger) {
        if (!file_exists($configPath)) {
            throw new Exception("File konfigurasi Telegram tidak ditemukan");
        }
        $cfg = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE
            || empty($cfg['telegram_token'])
            || empty($cfg['telegram_chat_id'])
        ) {
            throw new Exception("Token/Chat ID Telegram belum diatur dengan benar");
        }
        $this->token  = $cfg['telegram_token'];
        $this->chatId = $cfg['telegram_chat_id'];
        $this->logger = $logger;
    }
    public function send($message, $serverName) {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = [
            'chat_id'    => $this->chatId,
            'text'       => "[{$serverName}]\n{$message}",
            'parse_mode' => 'HTML'
        ];
        $opts = ['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ]];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $this->logger->log("Gagal mengirim notifikasi Telegram");
            return false;
        }
        return true;
    }
}

class WPConfigParser {
    private $filePath;
    public function __construct($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("wp-config.php tidak ditemukan di: $filePath");
        }
        $this->filePath = $filePath;
    }
    public function getConfigValue($key) {
        $content = file_get_contents($this->filePath);
        if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }
        return null;
    }
    public function getSiteDomain() {
        $home = $this->getConfigValue('WP_HOME') ?: $this->getConfigValue('WP_SITEURL');
        if ($home) {
            $parsed = parse_url($home);
            return $parsed['host'] ?? gethostname();
        }
        return gethostname() ?: 'server';
    }
}

class ProcessLock {
    private $lockFile;
    public function __construct($lockFile) {
        $this->lockFile = $lockFile;
    }
    public function acquire() {
        $fp = fopen($this->lockFile, 'c+');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new Exception("Script sudah berjalan di proses lain");
        }
        ftruncate($fp, 0);
        fwrite($fp, getmypid());
        fflush($fp);
    }
    public function release() {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }
}

function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, $localVersion, $serverName) {
    $baseUrl   = 'https://raw.githubusercontent.com/' . GITHUB_REPO . '/' . GITHUB_BRANCH;
    $versionUrl= $baseUrl . '/version.txt';
    $scriptUrl = $baseUrl . '/db_monitor_external.php';
    $envUrl    = $baseUrl . '/db_monitor_env.sh';
    $envFile   = __DIR__ . '/db_monitor_env.sh';

    try {
        $remoteVersion = trim(@file_get_contents($versionUrl));
        if ($remoteVersion && version_compare($remoteVersion, $localVersion, '>')) {
            $logger->log("Auto–update: v{$localVersion} → v{$remoteVersion}");
            $backup = __FILE__ . '.bak.' . time();
            copy(__FILE__, $backup);

            $newScript = @file_get_contents($scriptUrl);
            if ($newScript) {
                file_put_contents(__FILE__, $newScript);
                $logger->log("Script di-update ke v{$remoteVersion}");
                $notifier->send("🔄 Script updated: v{$localVersion} → v{$remoteVersion}", $serverName);

                if (file_exists($envFile)) {
                    $newEnv = @file_get_contents($envUrl);
                    if ($newEnv) {
                        file_put_contents($envFile, $newEnv);
                        $logger->log("Env file di-update");
                    }
                }

                exit(0);
            }
            throw new Exception("Gagal mengambil versi baru");
        }
    } catch (Exception $e) {
        $logger->log("Auto–update error: " . $e->getMessage());
    }
}

class DatabaseMonitor {
    private $config, $logger, $notifier, $wpParser, $serverName;

    public function __construct(Config $config, Logger $logger, TelegramNotifier $notifier, WPConfigParser $wpParser) {
        $this->config     = $config;
        $this->logger     = $logger;
        $this->notifier   = $notifier;
        $this->wpParser   = $wpParser;
        $this->serverName = $wpParser->getSiteDomain();
        autoUpdateScript($logger, $notifier, LOCAL_VERSION, $this->serverName);
    }

    public function run() {
        try {
            $this->checkDatabaseConnection();
            $this->checkDiskUsage();
            $this->checkSystemLoad();
            $this->checkMemoryUsage();
            $this->checkSecurity();   // cek brute-force SSH
            $this->logger->log("Monitoring selesai untuk {$this->serverName}");
        } catch (Exception $e) {
            $this->logger->log("Error utama: " . $e->getMessage());
            $this->notifier->send("❌ Fatal Error: " . $e->getMessage(), $this->serverName);
        }
    }

    private function checkDatabaseConnection() {
        $host = $this->wpParser->getConfigValue('DB_HOST') ?: 'localhost';
        $port = 3306;
        if (strpos($host, ':') !== false) list($host, $port) = explode(':', $host, 2);
        $user = $this->wpParser->getConfigValue('DB_USER') ?: 'root';
        $pass = $this->wpParser->getConfigValue('DB_PASSWORD') ?: '';
        $db   = $this->wpParser->getConfigValue('DB_NAME') ?: '';
        $attempts = 0;

        while ($attempts < $this->config->maxRetries) {
            $attempts++;
            $mysqli = new mysqli($host, $user, $pass, $db, $port);
            if ($mysqli->connect_errno) {
                $err = $mysqli->connect_error;
            } elseif (!$mysqli->query('SELECT 1')) {
                $err = $mysqli->error;
            } else {
                $mysqli->close();
                $this->logger->log("DB connection successful (Attempt {$attempts})");
                return;
            }
            $this->logger->log("DB attempt {$attempts} failed: {$err}");
            if ($attempts < $this->config->maxRetries) {
                sleep($this->config->retryDelay);
            } else {
                $msg      = "Gagal koneksi DB setelah {$attempts} attempts: {$err}";
                $solution = "Solusi: Periksa kredensial di wp-config.php, `sudo systemctl status mysql`.";
                $this->notifier->send("{$msg}\n{$solution}", $this->serverName);
                if ($this->config->autoRestart) {
                    $this->restartMySQL();
                }
                throw new Exception($msg);
            }
        }
    }

    private function restartMySQL() {
        $output = shell_exec('sudo systemctl restart mysql 2>&1');
        $this->logger->log("MySQL restart output: {$output}");
        $this->notifier->send("🔄 MySQL di-restart. Output: {$output}", $this->serverName);
    }

    private function checkDiskUsage() {
        $out = shell_exec('df -h /var/lib/mysql');
        if (preg_match('/(\d+)%/', $out, $m)) {
            $usage = (int)$m[1];
            if ($usage > $this->config->diskThreshold) {
                $msg      = "⚠️ Disk MySQL usage {$usage}% > {$this->config->diskThreshold}%";
                $solution = "Solusi: Bersihkan log, `sudo apt clean`, atau upgrade disk.";
                $this->logger->log($msg);
                $this->notifier->send("{$msg}\n{$solution}", $this->serverName);
            }
        }
    }

    private function checkSystemLoad() {
        $load = sys_getloadavg()[0];
        if ($load > $this->config->cpuThreshold) {
            $msg      = "⚠️ CPU load tinggi {$load} > {$this->config->cpuThreshold}";
            $solution = "Solusi: `top` untuk cek proses, hentikan proses berat, upgrade CPU.";
            $this->logger->log($msg);
            $this->notifier->send("{$msg}\n{$solution}", $this->serverName);
        }
    }

    private function checkMemoryUsage() {
        $free = shell_exec('free -m');
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free, $m)) {
            $total   = $m[1];
            $used    = $m[2];
            $percent = round(($used/$total)*100);
            if ($percent > $this->config->memThreshold) {
                $msg      = "⚠️ Memory usage {$percent}% > {$this->config->memThreshold}%";
                $solution = "Solusi: Matikan service tidak perlu, tambah swap, atau upgrade RAM.";
                $this->logger->log($msg);
                $this->notifier->send("{$msg}\n{$solution}", $this->serverName);
            }
        }
    }

    private function checkSecurity() {
        // Baca timestamp terakhir atau default 5 menit lalu
        $tsFile = $this->config->securityTimestampFile;
        $lastTs = is_file($tsFile) ? (int)file_get_contents($tsFile) : (time() - 300);
        $since  = date('Y-m-d H:i:s', $lastTs);

        // Ambil log SSHD sejak $since
        $logs = shell_exec("journalctl -u sshd --since=\"{$since}\" --no-pager 2>/dev/null");

        // Parse IP dan hitung kegagalan per IP
        $fails = [];
        if (preg_match_all("/Failed password for .* from (\d+\.\d+\.\d+\.\d+)/", $logs, $matches)) {
            foreach ($matches[1] as $ip) {
                $fails[$ip] = ($fails[$ip] ?? 0) + 1;
            }
        }

        // Simpan timestamp sekarang
        file_put_contents($tsFile, time());

        // Untuk setiap IP yang melebihi threshold, blok dan notifikasi
        foreach ($fails as $ip => $count) {
            if ($count > $this->config->loginFailThreshold) {
                $msg      = "⚠️ {$count} percobaan login gagal dari IP {$ip} > {$this->config->loginFailThreshold}";
                $solution = "Solusi: IP diblokir otomatis.";
                $this->logger->log($msg);

                if ($this->config->autoBlockIP) {
                    // Cek apakah sudah diblok
                    $check = shell_exec("iptables -C INPUT -s {$ip} -j DROP 2>&1");
                    if (strpos($check, "No chain/target/match") !== false || $check === null) {
                        shell_exec("iptables -A INPUT -s {$ip} -j DROP");
                        $this->logger->log("IP {$ip} diblokir via iptables");
                        $solution .= " IP {$ip} telah diblokir.";
                    }
                }

                $this->notifier->send("{$msg}\n{$solution}", $this->serverName);
            }
        }
    }
}

// === Eksekusi Utama ===
try {
    $lock   = new ProcessLock('/tmp/db_monitor.lock');
    $lock->acquire();

    $config = new Config();
    $logger = new Logger($config->logFile);
    $notifier = new TelegramNotifier($config->telegramConfigPath, $logger);
    $wpParser = new WPConfigParser($config->wpConfigPath);

    $monitor = new DatabaseMonitor($config, $logger, $notifier, $wpParser);
    $monitor->run();

} catch (Exception $e) {
    if (isset($logger)) {
        $logger->log("Fatal: " . $e->getMessage());
    }
    die($e->getMessage() . "\n");

} finally {
    if (isset($lock)) {
        $lock->release();
    }
}
