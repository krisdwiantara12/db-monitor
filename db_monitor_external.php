#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 *
 * Memonitor MySQL & lingkungan, mengirim notifikasi Telegram (dengan emoji), restart otomatis,
 * auto-update dari GitHub untuk skrip PHP & env shell, dan pengecekan kondisi lain (disk, pool, integritas, dependency "cron"),
 * CPU, memory, security, dan update package.
 *
 * Untuk pakai:
 * 1. Simpan sebagai db_monitor_external.php dan `chmod +x db_monitor_external.php`
 * 2. Buat file telegram_config.json di folder yang sama dengan format:
 *    {
 *      "token": "TELEGRAM_BOT_TOKEN",
 *      "chat_id": "CHAT_ID"
 *    }
 * 3. Buat file db_monitor_env.sh di folder yang sama (atau diatur melalui ENV) untuk variabel lingkungan.
 * 4. Cronjob: `* * * * * source /path/to/db_monitor_env.sh && /usr/bin/php /path/to/db_monitor_external.php`
 */

define('LOCAL_VERSION', '1.0.0');

if (php_sapi_name() !== 'cli') {
    die("? Skrip hanya dapat dijalankan dari command line.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Auto-update script PHP & shell dari GitHub jika versi remote lebih baru.
 */
function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, $localVersion) {
    $verUrl    = "https://raw.githubusercontent.com/krisdwiantara12/db-monitor/refs/heads/main/version.txt";
    $scriptUrl = "https://raw.githubusercontent.com/krisdwiantara12/db-monitor/refs/heads/main/db_monitor_external.php";
    $envUrl    = "https://raw.githubusercontent.com/krisdwiantara12/db-monitor/refs/heads/main/db_monitor_env.sh";
    $envFile   = __DIR__ . '/db_monitor_env.sh';

    // Ambil versi remote
    $ch = curl_init($verUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $remoteVer = trim(curl_exec($ch));
    $err       = curl_error($ch);
    curl_close($ch);

    if (!$remoteVer) {
        $logger->log("? AutoUpdate: gagal ambil versi remote: $err");
        return;
    }

    if (version_compare($remoteVer, $localVersion, '>')) {
        $logger->log("?? AutoUpdate: v$localVersion -> v$remoteVer");
        // Update PHP script
        $newScript = @file_get_contents($scriptUrl);
        if ($newScript) {
            copy(__FILE__, __FILE__ . '.bak.' . time());
            file_put_contents(__FILE__, $newScript);
            $notifier->send("?? <b>PHP script</b> diperbarui ke versi <b>$remoteVer</b>");
        } else {
            $logger->log("? AutoUpdate: gagal ambil PHP script baru");
        }
        // Update env shell script
        $newEnv = @file_get_contents($envUrl);
        if ($newEnv) {
            file_put_contents($envFile, $newEnv);
            $logger->log("?? AutoUpdate: env shell diperbarui");
            $notifier->send("?? <b>Env shell</b> diperbarui dari GitHub");
        } else {
            $logger->log("? AutoUpdate: gagal ambil env shell baru");
        }
        exit("?? Skrip dan env diperbarui ke v$remoteVer. Jalankan ulang.\n");
    } else {
        $logger->log("? AutoUpdate: sudah versi terbaru ($localVersion)");
    }
}

/**
 * Config dan thresholds
 */
class Config {
    public $logDir, $logFile, $restartLogFile, $lastRestartFile, $lastErrorFile;
    public $maxRetries, $retryDelay, $autoRestart, $debug, $normalNotification;
    public $diskThreshold, $connPoolThreshold, $cpuThreshold, $memThreshold, $loginFailThreshold;
    public $dependenciesToCheck, $mysqlConfigPath, $hashFile, $maxRestarts, $restartPeriod;
    public $telegramConfigPath;

    public function __construct() {
        $this->logDir            = __DIR__ . '/log_db_monitor';
        $this->logFile           = "$this->logDir/db-monitor-log.txt";
        $this->restartLogFile    = "$this->logDir/restart-history.log";
        $this->lastRestartFile   = "$this->logDir/last_restart.txt";
        $this->lastErrorFile     = "$this->logDir/last_error.json";
        $this->hashFile          = "$this->logDir/config_hashes.json";

        $this->maxRetries        = getenv('DB_MAX_RETRIES')     ? (int)getenv('DB_MAX_RETRIES')    : 3;
        $this->retryDelay        = getenv('DB_RETRY_DELAY')     ? (int)getenv('DB_RETRY_DELAY')    : 5;
        $this->autoRestart       = getenv('AUTO_RESTART')       ? filter_var(getenv('AUTO_RESTART'), FILTER_VALIDATE_BOOLEAN) : true;
        $this->debug             = getenv('DEBUG_MODE')         ? filter_var(getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN)    : false;
        $this->normalNotification= getenv('NORMAL_NOTIFICATION')? filter_var(getenv('NORMAL_NOTIFICATION'), FILTER_VALIDATE_BOOLEAN) : false;

        $this->diskThreshold     = getenv('DISK_THRESHOLD')     ? (int)getenv('DISK_THRESHOLD')    : 90;
        $this->connPoolThreshold = getenv('CONN_POOL_THRESHOLD') ? (int)getenv('CONN_POOL_THRESHOLD'): 80;
        $this->cpuThreshold      = getenv('CPU_THRESHOLD')      ? (float)getenv('CPU_THRESHOLD')   : 1.0;
        $this->memThreshold      = getenv('MEM_THRESHOLD')      ? (int)getenv('MEM_THRESHOLD')     : 90;
        $this->loginFailThreshold= getenv('LOGIN_FAIL_THRESHOLD')? (int)getenv('LOGIN_FAIL_THRESHOLD'): 5;

        $this->dependenciesToCheck = explode(',', getenv('DEPENDENCIES') ?: 'cron');
        $this->mysqlConfigPath   = getenv('MYSQL_CONFIG')       ?: '/etc/mysql/my.cnf';

        $this->maxRestarts       = 3;
        $this->restartPeriod     = 600; // detik

        $this->telegramConfigPath= __DIR__ . '/telegram_config.json';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}

/**
 * Logger sederhana
 */
class Logger {
    private $file;
    public function __construct($file) { $this->file = $file; }
    public function log($msg) {
        $entry = "[" . date('Y-m-d H:i:s') . "] $msg\n";
        file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Telegram Notifier, baca config dari JSON
 */
class TelegramNotifier {
    private $token, $chatId, $logger;
    public function __construct($configPath, Logger $logger) {
        if (!file_exists($configPath)) throw new Exception("telegram_config.json tidak ditemukan");
        $cfg = json_decode(file_get_contents($configPath), true);
        if (empty($cfg['token']) || empty($cfg['chat_id'])) {
            throw new Exception("Isi telegram_config.json tidak lengkap");
        }
        $this->token  = $cfg['token'];
        $this->chatId = $cfg['chat_id'];
        $this->logger = $logger;
    }
    public function send($text) {
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id'    => $this->chatId,
                'text'       => $text,
                'parse_mode' => 'HTML'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if (!$res) $this->logger->log("? Telegram gagal: $err");
        return (bool)$res;
    }
}

/**
 * WP Config Parser
 */
class WPConfigParser {
    private $file;
    public function __construct($f) {
        if (!file_exists($f)) throw new Exception("wp-config.php tidak ditemukan");
        $this->file = $f;
    }
    public function getConfigValue($key) {
        $d = file_get_contents($this->file);
        if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $d, $m)) {
            return $m[1];
        }
        return null;
    }
    public function getSiteDomain() {
        $h = $this->getConfigValue('WP_HOME') ?: $this->getConfigValue('WP_SITEURL');
        if ($h && ($p = parse_url($h)) && !empty($p['host'])) return $p['host'];
        return gethostname() ?: 'localhost';
    }
}

/**
 * Single-instance lock
 */
class ProcessLock {
    private $file, $h;
    public function __construct($f) { $this->file = $f; }
    public function acquire() {
        $this->h = fopen($this->file, 'c');
        if (!$this->h || !flock($this->h, LOCK_EX | LOCK_NB)) throw new Exception("?? Skrip sedang berjalan");
        ftruncate($this->h, 0);
        fwrite($this->h, getmypid());
    }
    public function release() {
        if ($this->h) { flock($this->h, LOCK_UN); fclose($this->h); }
    }
}

/**
 * Core Monitor
 */
class DatabaseMonitor {
    private $cfg, $log, $note, $wp;
    private $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $site, $ip;

    public function __construct($cfg, $log, $note, $wp) {
        $this->cfg  = $cfg;
        $this->log  = $log;
        $this->note = $note;
        $this->wp   = $wp;
    }
    private function writeErrorJson($data) {
        file_put_contents($this->cfg->lastErrorFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    private function restartMySQL() {
        $out = shell_exec("sudo systemctl restart mysql 2>&1");
        $this->log->log("?? Restart: " . trim($out));
        file_put_contents($this->cfg->restartLogFile, "[" . date('Y-m-d H:i:s') . "] $out\n", FILE_APPEND);
        return trim($out) ?: 'Restart dikirim.';
    }
    private function checkRestartAttempts() {
        if (!file_exists($this->cfg->restartLogFile)) return false;
        $lines = file($this->cfg->restartLogFile, FILE_IGNORE_NEW_LINES);
        $cnt   = 0;
        $now   = time();
        foreach ($lines as $l) {
            if (preg_match('/\[([^\]]+)\]/', $l, $m) && ($now - strtotime($m[1]) < $this->cfg->restartPeriod)) {
                $cnt++;
            }
        }
        if ($cnt >= $this->cfg->maxRestarts) {
            $this->note->send("? <b>{$this->site}</b> Gagal restart MySQL {$cnt}x dalam {$this->cfg->restartPeriod}s!");
            return true;
        }
        return false;
    }
    private function handleError($type, $msg, $ctx) {
        $data = [
            'site'  => $this->site,
            'ip'    => $this->ip,
            'time'  => date('Y-m-d H:i:s'),
            'type'  => $type,
            'error' => $msg
        ];
        $this->writeErrorJson($data);
        $this->log->log("? ERROR($type): $msg");
        $text = "? <b>{$this->site}</b> GAGAL konek MySQL {$ctx}\n" .
                "<pre>{$data['time']}\nServer: {$this->ip}\nError: $msg</pre>";
        $this->note->send($text);
        if ($this->cfg->autoRestart && ! $this->checkRestartAttempts()) {
            $out = $this->restartMySQL();
            touch($this->cfg->lastRestartFile);
            $this->note->send("?? <b>{$this->site}</b> MySQL di-restart.\n<pre>$out</pre>");
        }
    }
    public function testFsockopen($host, $port) {
        $t0 = microtime(true);
        $fp = @fsockopen($host, $port, $errNo, $errStr, 3);
        $dt = round((microtime(true) - $t0) * 1000);
        if (! $fp) {
            $this->handleError('fsockopen', $errStr, "({$host}:{$port}) {$dt}ms");
            throw new Exception("FSOCKOPEN gagal: $errStr");
        }
        fclose($fp);
        return $dt;
    }
    public function testMysqli() {
        $attempt = 0;
        while ($attempt < $this->cfg->maxRetries) {
            $attempt++;
            try {
                $mysqli = new mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
                if ($mysqli->connect_errno) throw new Exception($mysqli->connect_error);
                $mysqli->close();
                $this->log->log("? <b>{$this->site}</b> Koneksi MySQL normal. Percobaan: $attempt");
                return $attempt;
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $this->log->log("?? Percobaan $attempt: $msg");
                if ($attempt < $this->cfg->maxRetries) {
                    sleep(pow($this->cfg->retryDelay, $attempt - 1));
                }
            }
        }
        $this->handleError('mysqli', $msg, "setelah {$attempt} percobaan");
        throw new Exception("MySQL gagal konek setelah {$attempt} percobaan.");
    }
    private function notifyRecovery() {
        if (file_exists($this->cfg->lastRestartFile) && time() - filemtime($this->cfg->lastRestartFile) <= 300) {
            $this->note->send("? <b>{$this->site}</b> MySQL pulih pasca restart");
            unlink($this->cfg->lastRestartFile);
        }
    }
    private function checkDiskUsage() {
        $out = shell_exec("df -h /var/lib/mysql | tail -1");
        if (preg_match('/\s(\d+)%\s/', $out, $m)) {
            $usage = (int)$m[1];
            if ($usage > $this->cfg->diskThreshold) {
                $this->note->send("?? <b>{$this->site}</b> Disk /var/lib/mysql {$usage}% penuh");
                $this->log->log("Disk usage: {$usage}%");
            }
        }
    }
    private function checkConnectionPool() {
        try {
            $mysqli = new mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
            $maxConn = $mysqli->query("SHOW VARIABLES LIKE 'max_connections'")->fetch_assoc()['Value'];
            $used    = $mysqli->query("SHOW STATUS LIKE 'Threads_connected'")->fetch_assoc()['Value'];
            $mysqli->close();
            $pct = round($used / $maxConn * 100, 1);
            if ($pct > $this->cfg->connPoolThreshold) {
                $this->note->send("?? <b>{$this->site}</b> ConnPool {$used}/{$maxConn} ({$pct}% digunakan)");
                $this->log->log("ConnPool usage: {$pct}%");
            }
        } catch (Exception $e) {}
    }
    private function checkConfigIntegrity() {
        $files = [$this->cfg->wpConfigPath, $this->cfg->mysqlConfigPath];
        $hashes = file_exists($this->cfg->hashFile) ? json_decode(file_get_contents($this->cfg->hashFile), true) : [];
        foreach ($files as $f) {
            if (!file_exists($f)) continue;
            $current = md5_file($f);
            $name    = basename($f);
            if (isset($hashes[$name]) && $hashes[$name] !== $current) {
                $this->note->send("?? <b>{$this->site}</b> Config file {$name} berubah");
                $this->log->log("Config integrity: {$name} changed");
            }
            $hashes[$name] = $current;
        }
        file_put_contents($this->cfg->hashFile, json_encode($hashes));
    }
    private function checkDependencies() {
        foreach ($this->cfg->dependenciesToCheck as $svc) {
            $status = trim(shell_exec("systemctl is-active {$svc} 2>/dev/null"));
            if ($status !== 'active') {
                $this->note->send("??? <b>{$this->site}</b> Service {$svc} not active ({$status})");
                $this->log->log("Dependency check: {$svc} is {$status}");
            }
        }
    }
    private function checkCpuLoad() {
        $load = sys_getloadavg()[0];
        if ($load > $this->cfg->cpuThreshold) {
            $this->note->send("?? <b>{$this->site}</b> High load average: {$load}");
            $this->log->log("CPU load: {$load}");
        }
    }
    private function checkMemoryUsage() {
        $mem = shell_exec('free -m');
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $mem, $m)) {
            $total = (int)$m[1];
            $used  = (int)$m[2];
            $pct   = round($used / $total * 100, 1);
            if ($pct > $this->cfg->memThreshold) {
                $this->note->send("?? <b>{$this->site}</b> High memory usage: {$used}MB/{$total}MB ({$pct}% used)");
                $this->log->log("Memory usage: {$pct}%");
            }
        }
    }
    private function checkSecurity() {
        $count = (int)trim(shell_exec("journalctl -u ssh --since '5 minutes ago' | grep 'Failed password' | wc -l"));
        if ($count > $this->cfg->loginFailThreshold) {
            $this->note->send("?? <b>{$this->site}</b> Detected {$count} failed SSH logins in 5 minutes");
            $this->log->log("Security check: {$count} failed SSH logins");
        }
    }
    private function checkPackageUpdates() {
        $pending = (int)trim(shell_exec("apt-get -s upgrade | grep '^Inst' | wc -l"));
        if ($pending > 0) {
            $this->note->send("?? <b>{$this->site}</b> {$pending} packages can be updated");
            $this->log->log("Package updates: {$pending}");
        }
    }
    public function run() {
        // Ambil konfigurasi DB dari wp-config
        $raw = $this->wp->getConfigValue('DB_HOST') ?: 'localhost';
        if (strpos($raw, ':') !== false) {
            list($host, $port) = explode(':', $raw, 2);
            $this->dbHost = $host;
            $this->dbPort = (int)$port;
        } else {
            $this->dbHost = $raw;
            $this->dbPort = 3306;
        }
        $this->dbUser = $this->wp->getConfigValue('DB_USER')     ?: 'user';
        $this->dbPass = $this->wp->getConfigValue('DB_PASSWORD') ?: 'pass';
        $this->dbName = $this->wp->getConfigValue('DB_NAME')     ?: 'db';

        $this->site = $this->wp->getSiteDomain();
        $this->ip   = gethostbyname(gethostname());

        // Auto-update
        autoUpdateScript($this->log, $this->note, LOCAL_VERSION);

        // Cek koneksi jaringan & MySQL
        $this->testFsockopen($this->dbHost, $this->dbPort);
        try {
            $attempts = $this->testMysqli();
            $msg = "? <b>{$this->site}</b> Koneksi normal. Percobaan: {$attempts}";
            echo $msg . "\n";
            if ($this->cfg->normalNotification) {
                $this->note->send($msg);
            }
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }

        // Pengecekan tambahan
        $this->notifyRecovery();
        $this->checkDiskUsage();
        $this->checkConnectionPool();
        $this->checkConfigIntegrity();
        $this->checkDependencies();
        $this->checkCpuLoad();
        $this->checkMemoryUsage();
        $this->checkSecurity();
        $this->checkPackageUpdates();

        if ($this->cfg->debug) {
            echo $this->getSystemInfo() . "\n";
        }
    }
    public function getSystemInfo() {
        $load = sys_getloadavg()[0];
        $memInfo = stripos(PHP_OS, 'WIN') === false ? shell_exec('free -m') : 'Mem info N/A';
        return "Load: {$load} | Mem:\n{$memInfo}";
    }
}

/* ===== EKSEKUSI ===== */
try {
    $lock = new ProcessLock('/tmp/db_monitor_external.lock');
    $lock->acquire();
    register_shutdown_function(function() use ($lock) { $lock->release(); });

    $cfg  = new Config();
    $log  = new Logger($cfg->logFile);
    $note = new TelegramNotifier($cfg->telegramConfigPath, $log);
    $wp   = new WPConfigParser($cfg->wpConfigPath);

    $mon = new DatabaseMonitor($cfg, $log, $note, $wp);
    $mon->run();

} catch (Exception $ex) {
    error_log($ex->getMessage());
    echo $ex->getMessage() . "\n";
    exit(1);
}
