#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 * 
 * Script monitoring MySQL dan lingkungan server dengan:
 * - Notifikasi Telegram
 * - Auto-update dari GitHub
 * - Monitoring resource server
 * - Auto-restart MySQL
 */

define('LOCAL_VERSION', '1.0.4');
define('GITHUB_REPO', 'krisdwiantara12/db-monitor');
define('GITHUB_BRANCH', 'main');

if (php_sapi_name() !== 'cli') {
    die("Error: Script hanya bisa dijalankan via command line\n");
}

// Konfigurasi error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

class Config {
    public $logDir, $logFile, $restartLogFile, $lastRestartFile, $lastErrorFile;
    public $maxRetries = 3;
    public $retryDelay = 5;
    public $autoRestart = true;
    public $debug = false;
    public $normalNotification = false;
    public $diskThreshold = 90;
    public $connPoolThreshold = 80;
    public $cpuThreshold = 1.0;
    public $memThreshold = 90;
    public $loginFailThreshold = 5;
    public $dependenciesToCheck = ['cron'];
    public $mysqlConfigPath = '/etc/mysql/my.cnf';
    public $maxRestarts = 3;
    public $restartPeriod = 600;
    public $telegramConfigPath;
    public $wpConfigPath;

    public function __construct() {
        $this->logDir = __DIR__ . '/log_db_monitor';
        $this->logFile = "$this->logDir/db-monitor-log.txt";
        $this->restartLogFile = "$this->logDir/restart-history.log";
        $this->lastRestartFile = "$this->logDir/last_restart.txt";
        $this->lastErrorFile = "$this->logDir/last_error.json";
        $this->telegramConfigPath = __DIR__ . '/telegram_config.json';
        $this->wpConfigPath = __DIR__ . '/wp-config.php';

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
        
        $config = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Format konfigurasi Telegram tidak valid");
        }
        
        $this->token = $config['telegram_token'] ?? '';
        $this->chatId = $config['telegram_chat_id'] ?? '';
        $this->logger = $logger;
        
        if (empty($this->token) || empty($this->chatId)) {
            throw new Exception("Token atau Chat ID Telegram tidak valid");
        }
    }
    
    public function send($message, $serverName) {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        
        $data = [
            'chat_id' => $this->chatId,
            'text' => "[$serverName] $message",
            'parse_mode' => 'HTML'
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
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
            throw new Exception("File wp-config.php tidak ditemukan di: $filePath");
        }
        $this->filePath = $filePath;
    }
    
    public function getConfigValue($key) {
        $content = file_get_contents($this->filePath);
        
        if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $content, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    public function getSiteDomain() {
        $home = $this->getConfigValue('WP_HOME') ?: $this->getConfigValue('WP_SITEURL');
        if ($home) {
            $parsed = parse_url($home);
            if ($parsed && isset($parsed['host'])) {
                return $parsed['host'];
            }
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
    $verUrl = "https://raw.githubusercontent.com/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/version.txt";
    $scriptUrl = "https://raw.githubusercontent.com/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/db_monitor_external.php";
    $envUrl = "https://raw.githubusercontent.com/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/db_monitor_env.sh";
    $envFile = __DIR__ . '/db_monitor_env.sh';

    try {
        // Ambil versi terbaru
        $remoteVer = trim(@file_get_contents($verUrl));
        if (empty($remoteVer)) {
            throw new Exception("Gagal mengambil versi terbaru");
        }

        if (version_compare($remoteVer, $localVersion, '>')) {
            $logger->log("Memulai update dari v$localVersion ke v$remoteVer");
            
            // Update script utama
            $newScript = @file_get_contents($scriptUrl);
            if ($newScript) {
                $backupFile = __FILE__ . '.bak.' . time();
                if (copy(__FILE__, $backupFile)) {
                    if (file_put_contents(__FILE__, $newScript)) {
                        $logger->log("Script berhasil diupdate ke v$remoteVer");
                        $notifier->send("Script monitoring berhasil diupdate dari v$localVersion ke v$remoteVer", $serverName);
                        
                        // Update env file jika ada
                        if (file_exists($envFile)) {
                            $newEnv = @file_get_contents($envUrl);
                            if ($newEnv) {
                                file_put_contents($envFile, $newEnv);
                                $logger->log("File env berhasil diupdate");
                            }
                        }
                        
                        exit(0); // Exit untuk menjalankan versi baru
                    }
                }
            }
            throw new Exception("Gagal mengupdate script");
        }
    } catch (Exception $e) {
        $logger->log("Auto-update gagal: " . $e->getMessage());
    }
}

class DatabaseMonitor {
    private $config;
    private $logger;
    private $notifier;
    private $wpConfig;
    private $serverName;
    
    public function __construct(Config $config, Logger $logger, TelegramNotifier $notifier, WPConfigParser $wpConfig) {
        $this->config = $config;
        $this->logger = $logger;
        $this->notifier = $notifier;
        $this->wpConfig = $wpConfig;
        $this->serverName = $wpConfig->getSiteDomain();
        
        // Jalankan auto-update
        autoUpdateScript($logger, $notifier, LOCAL_VERSION, $this->serverName);
    }
    
    public function run() {
        try {
            $this->checkDatabaseConnection();
            $this->checkDiskUsage();
            $this->checkSystemLoad();
            $this->checkMemoryUsage();
            $this->checkSecurity();
            
            $this->logger->log("Monitoring selesai untuk $this->serverName");
            
        } catch (Exception $e) {
            $this->logger->log("Error: " . $e->getMessage());
            $this->notifier->send("Error: " . $e->getMessage(), $this->serverName);
        }
    }
    
    private function checkDatabaseConnection() {
        $dbHost = $this->wpConfig->getConfigValue('DB_HOST') ?: 'localhost';
        $dbPort = 3306;
        
        if (strpos($dbHost, ':') !== false) {
            list($dbHost, $dbPort) = explode(':', $dbHost, 2);
        }
        
        $dbUser = $this->wpConfig->getConfigValue('DB_USER') ?: 'root';
        $dbPass = $this->wpConfig->getConfigValue('DB_PASSWORD') ?: '';
        $dbName = $this->wpConfig->getConfigValue('DB_NAME') ?: '';
        
        $attempt = 0;
        $maxAttempts = $this->config->maxRetries;
        $lastError = '';
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
                
                if ($mysqli->connect_error) {
                    throw new Exception($mysqli->connect_error, $mysqli->connect_errno);
                }
                
                if (!$mysqli->query("SELECT 1")) {
                    throw new Exception($mysqli->error, $mysqli->errno);
                }
                
                $mysqli->close();
                $this->logger->log("Koneksi database berhasil (Percobaan $attempt)");
                return true;
                
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $this->logger->log("Percobaan $attempt gagal: $lastError");
                
                if ($attempt < $maxAttempts) {
                    sleep($this->config->retryDelay);
                }
            }
        }
        
        $errorMsg = "Gagal terkoneksi ke database setelah $maxAttempts percobaan. Error: $lastError";
        
        $this->notifier->send($errorMsg, $this->serverName);
        
        if ($this->config->autoRestart) {
            $this->restartMySQL();
        }
        
        throw new Exception($errorMsg);
    }
    
    private function restartMySQL() {
        $output = shell_exec('sudo systemctl restart mysql 2>&1');
        $this->logger->log("Restart MySQL: $output");
        $this->notifier->send("MySQL di-restart. Output: $output", $this->serverName);
    }
    
    private function checkDiskUsage() {
        $output = shell_exec('df -h /var/lib/mysql');
        if (preg_match('/(\d+)%/', $output, $matches)) {
            $usage = (int)$matches[1];
            if ($usage > $this->config->diskThreshold) {
                $msg = "Peringatan: Penggunaan disk mencapai $usage% (Threshold: {$this->config->diskThreshold}%)";
                $this->logger->log($msg);
                $this->notifier->send($msg, $this->serverName);
            }
        }
    }
    
    private function checkSystemLoad() {
        $load = sys_getloadavg();
        if ($load[0] > $this->config->cpuThreshold) {
            $msg = "Peringatan: Beban CPU tinggi: {$load[0]} (Threshold: {$this->config->cpuThreshold})";
            $this->logger->log($msg);
            $this->notifier->send($msg, $this->serverName);
        }
    }
    
    private function checkMemoryUsage() {
        $free = shell_exec('free -m');
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $free, $matches)) {
            $total = $matches[1];
            $used = $matches[2];
            $percent = round(($used / $total) * 100);
            
            if ($percent > $this->config->memThreshold) {
                $msg = "Peringatan: Penggunaan memory mencapai $percent% (Threshold: {$this->config->memThreshold}%)";
                $this->logger->log($msg);
                $this->notifier->send($msg, $this->serverName);
            }
        }
    }
    
    private function checkSecurity() {
        $failedLogins = shell_exec("grep 'Failed password' /var/log/auth.log | wc -l");
        if ($failedLogins > $this->config->loginFailThreshold) {
            $msg = "Peringatan: Terdeteksi $failedLogins percobaan login gagal (Threshold: {$this->config->loginFailThreshold})";
            $this->logger->log($msg);
            $this->notifier->send($msg, $this->serverName);
        }
    }
}

// Eksekusi utama
try {
    $lockFile = '/tmp/db_monitor.lock';
    $lock = new ProcessLock($lockFile);
    $lock->acquire();
    
    $config = new Config();
    $logger = new Logger($config->logFile);
    
    try {
        $notifier = new TelegramNotifier($config->telegramConfigPath, $logger);
    } catch (Exception $e) {
        $logger->log($e->getMessage());
        die($e->getMessage() . "\n");
    }
    
    try {
        $wpConfig = new WPConfigParser($config->wpConfigPath);
    } catch (Exception $e) {
        $logger->log($e->getMessage());
        if (isset($notifier)) {
            $notifier->send($e->getMessage(), gethostname());
        }
        die($e->getMessage() . "\n");
    }
    
    $monitor = new DatabaseMonitor($config, $logger, $notifier, $wpConfig);
    $monitor->run();
    
} catch (Exception $e) {
    if (isset($logger)) {
        $logger->log("Error utama: " . $e->getMessage());
    }
    die($e->getMessage() . "\n");
} finally {
    if (isset($lock)) {
        $lock->release();
    }
}
