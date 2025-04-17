#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 *
 * Versi Terbaru: 1.0.4 → Auto–update via GitHub
 * Fitur:
 * - Notifikasi Telegram dengan solusi
 * - Auto–update dari GitHub (backup + replace + restart)
 * - Monitoring: MySQL, disk, CPU, memori, keamanan
 * - Auto–restart MySQL
 * - Lockfile agar tidak tumpang-tindih
 */

define('LOCAL_VERSION', '1.0.4');
define('GITHUB_REPO', 'krisdwiantara12/db-monitor');
define('GITHUB_BRANCH', 'main');

if (php_sapi_name() !== 'cli') {
    die("Error: Script hanya bisa dijalankan via command line\n");
}

// -------------------------------------------------------------------
// Kelas Konfigurasi & Utility
// -------------------------------------------------------------------
class Config {
    public $logDir, $logFile, $restartLogFile, $lastRestartFile, $lastErrorFile;
    public $maxRetries = 3, $retryDelay = 5, $autoRestart = true, $debug = false;
    public $normalNotification = false, $diskThreshold = 90, $connPoolThreshold = 80;
    public $cpuThreshold = 1.0, $memThreshold = 90, $loginFailThreshold = 5;
    public $dependenciesToCheck = ['cron'];
    public $mysqlConfigPath = '/etc/mysql/my.cnf';
    public $maxRestarts = 3, $restartPeriod = 600;
    public $telegramConfigPath, $wpConfigPath;

    public function __construct() {
        $this->logDir           = __DIR__ . '/log_db_monitor';
        $this->logFile          = "$this->logDir/db-monitor-log.txt";
        $this->restartLogFile   = "$this->logDir/restart-history.log";
        $this->lastRestartFile  = "$this->logDir/last_restart.txt";
        $this->lastErrorFile    = "$this->logDir/last_error.json";
        $this->telegramConfigPath = __DIR__ . '/telegram_config.json';
        $this->wpConfigPath       = __DIR__ . '/wp-config.php';
        if (!is_dir($this->logDir)) mkdir($this->logDir, 0755, true);
    }
}

class Logger {
    private $file;
    public function __construct($file) { $this->file = $file; }
    public function log($msg) {
        $entry = "[".date('Y-m-d H:i:s')."] $msg\n";
        file_put_contents($this->file, $entry, FILE_APPEND|LOCK_EX);
    }
}

class TelegramNotifier {
    private $token, $chatId, $logger;
    public function __construct($configPath, Logger $logger) {
        if (!file_exists($configPath)) throw new Exception("File konfigurasi Telegram tidak ditemukan");
        $cfg = json_decode(file_get_contents($configPath), true);
        if (json_last_error())    throw new Exception("Format konfigurasi Telegram tidak valid");
        if (empty($cfg['telegram_token'])||empty($cfg['telegram_chat_id'])) {
            throw new Exception("Token/Chat ID Telegram belum diatur");
        }
        $this->token  = $cfg['telegram_token'];
        $this->chatId = $cfg['telegram_chat_id'];
        $this->logger = $logger;
    }
    public function send($message, $serverName) {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = [
            'chat_id'    => $this->chatId,
            'text'       => "[$serverName]\n$message",
            'parse_mode' => 'HTML'
        ];
        $opts = ['http'=>[
            'method'=>"POST",
            'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
            'content'=>http_build_query($data),
            'timeout'=>10
        ]];
        $ctx    = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);
        if (!$result) $this->logger->log("Gagal mengirim notifikasi Telegram");
        return $result !== false;
    }
}

class WPConfigParser {
    private $filePath;
    public function __construct($filePath) {
        if (!file_exists($filePath)) throw new Exception("wp-config.php tidak ditemukan di: $filePath");
        $this->filePath = $filePath;
    }
    public function getConfigValue($key) {
        $content = file_get_contents($this->filePath);
        if (preg_match("/define\s*\(\s*['\"]".preg_quote($key,'/")."['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }
        return null;
    }
    public function getSiteDomain() {
        $home = $this->getConfigValue('WP_HOME') ?: $this->getConfigValue('WP_SITEURL');
        if ($home) {
            $p = parse_url($home);
            return $p['host'] ?? gethostname();
        }
        return gethostname() ?: 'server';
    }
}

class ProcessLock {
    private $lockFile;
    public function __construct($lockFile) { $this->lockFile = $lockFile; }
    public function acquire() {
        $fp = fopen($this->lockFile, 'c+');
        if (!flock($fp, LOCK_EX|LOCK_NB)) {
            fclose($fp);
            throw new Exception("Script sudah berjalan di proses lain");
        }
        ftruncate($fp,0);
        fwrite($fp,getmypid());
        fflush($fp);
    }
    public function release() {
        if (file_exists($this->lockFile)) unlink($this->lockFile);
    }
}

// -------------------------------------------------------------------
// Auto–Update dari GitHub
// -------------------------------------------------------------------
function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, $localVersion, $serverName) {
    $verUrl    = "https://raw.githubusercontent.com/".GITHUB_REPO."/".GITHUB_BRANCH."/version.txt";
    $scriptUrl = "https://raw.githubusercontent.com/".GITHUB_REPO."/".GITHUB_BRANCH."/db_monitor_external.php";
    $envUrl    = "https://raw.githubusercontent.com/".GITHUB_REPO."/".GITHUB_BRANCH."/db_monitor_env.sh";
    $envFile   = __DIR__ . '/db_monitor_env.sh';

    try {
        $remoteVer = trim(@file_get_contents($verUrl));
        if (!$remoteVer) throw new Exception("Gagal mengambil versi terbaru");
        if (version_compare($remoteVer, $localVersion, '>')) {
            $logger->log("Update tersedia: v$localVersion → v$remoteVer");
            // Backup
            $backup = __FILE__.'.bak.'.time();
            copy(__FILE__, $backup) && $logger->log("Backup dibuat: $backup");
            // Update script
            $new = @file_get_contents($scriptUrl);
            if ($new && file_put_contents(__FILE__, $new)) {
                $logger->log("Script diupdate ke v$remoteVer");
                $notifier->send("✅ Auto–update: v$localVersion → v$remoteVer", $serverName);
                // Update env jika ada
                if (file_exists($envFile)) {
                    $ne = @file_get_contents($envUrl);
                    if ($ne) file_put_contents($envFile, $ne) && $logger->log("Env diupdate");
                }
                exit(0);
            }
            throw new Exception("Gagal menulis file script baru");
        }
    } catch (Exception $e) {
        $logger->log("Auto–update error: ".$e->getMessage());
    }
}

// -------------------------------------------------------------------
// Monitoring & Peringatan dengan Solusi
// -------------------------------------------------------------------
class DatabaseMonitor {
    private $cfg, $log, $not, $wp, $srv;
    public function __construct(Config $cfg, Logger $log, TelegramNotifier $not, WPConfigParser $wp) {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->not = $not;
        $this->wp  = $wp;
        $this->srv = $wp->getSiteDomain();
        autoUpdateScript($log, $not, LOCAL_VERSION, $this->srv);
    }
    public function run() {
        try {
            $this->checkDatabaseConnection();
            $this->checkDiskUsage();
            $this->checkSystemLoad();
            $this->checkMemoryUsage();
            $this->checkSecurity();
            $this->log->log("✅ Monitoring selesai untuk {$this->srv}");
        } catch (Exception $e) {
            $this->log->log("Error: ".$e->getMessage());
            $this->not->send("❌ Error utama: ".$e->getMessage(), $this->srv);
        }
    }

    private function checkDatabaseConnection() {
        // Ambil kredensial dari wp-config.php
        $host = $this->wp->getConfigValue('DB_HOST') ?: 'localhost';
        $port = 3306;
        if (strpos($host,':')!==false) list($host,$port)=explode(':',$host,2);
        $user = $this->wp->getConfigValue('DB_USER') ?: 'root';
        $pass = $this->wp->getConfigValue('DB_PASSWORD') ?: '';
        $name = $this->wp->getConfigValue('DB_NAME') ?: '';
        $attempt = 0; $lastErr = '';
        while ($attempt < $this->cfg->maxRetries) {
            $attempt++;
            try {
                $m = new mysqli($host,$user,$pass,$name,$port);
                if ($m->connect_error) throw new Exception($m->connect_error);
                if (!$m->query("SELECT 1")) throw new Exception($m->error);
                $m->close();
                $this->log->log("DB OK (Percobaan $attempt)");
                return true;
            } catch (Exception $e) {
                $lastErr = $e->getMessage();
                $this->log->log("DB gagal (Percobaan $attempt): $lastErr");
                if ($attempt < $this->cfg->maxRetries) sleep($this->cfg->retryDelay);
            }
        }
        $msg  = "Gagal koneksi DB setelah {$this->cfg->maxRetries} percobaan\nError: $lastErr";
        $sol  = "Solusi: Periksa kredensial di wp-config.php, jalankan `sudo systemctl status mysql`, dan cek port/host.";
        $this->not->send("$msg\n$sol", $this->srv);
        if ($this->cfg->autoRestart) $this->restartMySQL();
        throw new Exception($msg);
    }

    private function restartMySQL() {
        $out = shell_exec('sudo systemctl restart mysql 2>&1');
        $this->log->log("Restart MySQL: $out");
        $this->not->send("🔄 MySQL di-restart\nOutput: $out", $this->srv);
    }

    private function checkDiskUsage() {
        $out = shell_exec('df -h /var/lib/mysql');
        if (preg_match('/(\d+)%/', $out, $m)) {
            $u = (int)$m[1];
            if ($u > $this->cfg->diskThreshold) {
                $msg = "Peringatan: Disk MySQL usage $u% > {$this->cfg->diskThreshold}%";
                $sol = "Solusi: Hapus log lama di {$this->cfg->logDir}, `sudo apt clean`, atau tambah kapasitas disk.";
                $this->log->log($msg);
                $this->not->send("$msg\n$sol", $this->srv);
            }
        }
    }

    private function checkSystemLoad() {
        list($load) = sys_getloadavg();
        if ($load > $this->cfg->cpuThreshold) {
            $msg = "Peringatan: Load CPU tinggi $load > {$this->cfg->cpuThreshold}";
            $sol = "Solusi: Identifikasi proses via `top`, hentikan proses berat, atau upgrade CPU.";
            $this->log->log($msg);
            $this->not->send("$msg\n$sol", $this->srv);
        }
    }

    private function checkMemoryUsage() {
        $free = shell_exec('free -m');
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/',$free,$m)) {
            $tot=$m[1]; $used=$m[2]; $pc=round($used/$tot*100);
            if ($pc > $this->cfg->memThreshold) {
                $msg = "Peringatan: Memory usage $pc% > {$this->cfg->memThreshold}%";
                $sol = "Solusi: Matikan service tak perlu, tambah swap, atau upgrade RAM.";
                $this->log->log($msg);
                $this->not->send("$msg\n$sol", $this->srv);
            }
        }
    }

    private function checkSecurity() {
        $fails = (int) shell_exec("grep 'Failed password' /var/log/auth.log | wc -l");
        if ($fails > $this->cfg->loginFailThreshold) {
            $msg = "Peringatan: $fails login gagal > {$this->cfg->loginFailThreshold}";
            $sol = "Solusi: Periksa & blok IP mencurigakan, gunakan fail2ban.";
            $this->log->log($msg);
            $this->not->send("$msg\n$sol", $this->srv);
        }
    }
}

// -------------------------------------------------------------------
// Eksekusi Utama
// -------------------------------------------------------------------
try {
    $lock = new ProcessLock('/tmp/db_monitor.lock');
    $lock->acquire();

    $cfg = new Config();
    $log = new Logger($cfg->logFile);
    $not = new TelegramNotifier($cfg->telegramConfigPath, $log);
    $wp  = new WPConfigParser($cfg->wpConfigPath);

    $mon = new DatabaseMonitor($cfg, $log, $not, $wp);
    $mon->run();

} catch (Exception $e) {
    if (isset($log)) $log->log("Fatal: ".$e->getMessage());
    die($e->getMessage()."\n");
} finally {
    if (isset($lock)) $lock->release();
}
