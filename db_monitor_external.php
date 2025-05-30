#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 *
 * Versi Terbaru: 2.1.1
 * Fitur:
 * - Konfigurasi terpusat via environment variables (dari db_monitor_env.sh)
 * - Notifikasi Telegram dengan nama domain/server
 * - Auto–update dari GitHub (backup + replace + restart)
 * - Monitoring:
 * - MySQL (koneksi, auto-restart TANPA pre-backup)
 * - Performa MySQL (threads running, slow query log check)
 * - Disk Usage & SMART Status
 * - CPU Load
 * - Memory Usage (via /proc/meminfo)
 * - Keamanan (brute-force SSH via journalctl/auth.log & auto-blokir via Fail2Ban)
 * - WordPress Debug Log
 * - Lockfile agar tidak tumpang-tindih
 * - Exit codes yang lebih informatif
 * - Notifikasi normal jika semua OK (opsional)
 */

define('LOCAL_VERSION', '2.1.1'); // Versi diperbarui

define('EXIT_SUCCESS', 0);
define('EXIT_UPDATED', 0);
define('EXIT_GENERIC_ERROR', 1);
define('EXIT_LOCK_FAILED', 2);
define('EXIT_CONFIG_ERROR', 3);
define('EXIT_PHP_VERSION_LOW', 4);
define('EXIT_DEPENDENCY_ERROR', 5);

// Global variables (diinisialisasi setelah Config)
$configGlobal = null;
$loggerGlobal = null;
$notifierGlobal = null;
$wpParserGlobal = null;
$serverNameGlobal = 'unknown_server';

if (php_sapi_name() !== 'cli') {
    die("Error: Script hanya bisa dijalankan via command line\n");
}

class Config {
    // --- Bagian Utama ---
    public $logDir, $logFile, $lockFilePath, $wpConfigPathForParser;
    public $minPhpVersion;
    public $telegramTokenEnv, $telegramChatIdEnv;
    public $telegramConfigJsonPath;
    public $debugMode;
    public $normalNotification;

    // --- Bagian Monitoring ---
    public $maxRetries;
    public $retryDelaySeconds;
    public $mysqlAutoRestart;
    public $diskThresholdPercent;
    public $cpuThresholdLoadAvg;
    public $memThresholdPercent;

    // --- Bagian Keamanan ---
    public $loginFailThreshold;
    public $autoBlockIp;
    public $fail2banJailName, $fail2banClientPath, $journalctlPath, $authLogPath;
    public $securityTimestampFile;

    // --- Bagian MySQL Lanjutan ---
    public $enableMysqlPerformanceCheck;
    public $mysqlThreadsRunningThreshold;
    public $mysqlSlowQueryLogPath, $mysqlCheckSlowQueryMinutes;
    // Properti terkait backup MySQL dihilangkan: mysqlEnablePreRestartBackup, mysqlBackupDir, mysqldumpPath
    public $mysqldumpPath; // Tetap ada jika diperlukan untuk fitur lain, tapi tidak untuk pre-restart backup

    // --- Bagian Disk Lanjutan ---
    public $enableSmartCheck, $smartctlPath, $diskDevicesToCheck;
    
    // --- Bagian WordPress Lanjutan ---
    public $enableWpDebugLogCheck, $wpDebugLogPath, $wpCheckDebugLogMinutes;

    // --- Bagian Auto Update ---
    public $githubRepo, $githubBranch;

    public $restartLogFile, $lastRestartFile, $lastErrorFile;

    private function getEnv(string $varName, $defaultValue = null) {
        $value = getenv($varName);
        if ($value === false && isset($GLOBALS['loggerGlobal']) && $GLOBALS['loggerGlobal'] instanceof Logger) {
             $GLOBALS['loggerGlobal']->debug("Environment variable '{$varName}' tidak diset, menggunakan nilai default: '{$defaultValue}'");
        }
        return ($value !== false) ? $value : $defaultValue;
    }

    private function getEnvBool(string $varName, bool $defaultValue): bool {
        $valueStr = $this->getEnv($varName, $defaultValue ? 'true' : 'false');
        return filter_var(strtolower($valueStr), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $defaultValue;
    }

    public function __construct() {
        // [utama]
        $logDirName                 = $this->getEnv('ENV_LOG_DIR_NAME', 'log_db_monitor');
        $this->lockFilePath         = $this->getEnv('ENV_LOCK_FILE_PATH', '/tmp/db_monitor_external.lock');
        $this->wpConfigPathForParser= $this->getEnv('ENV_WP_CONFIG_PATH', null);
        $this->minPhpVersion        = $this->getEnv('ENV_MIN_PHP_VERSION', '7.2.0');
        $this->debugMode            = $this->getEnvBool('ENV_DEBUG_MODE', false);
        $this->normalNotification   = $this->getEnvBool('ENV_NORMAL_NOTIFICATION', false);

        $this->telegramTokenEnv        = $this->getEnv('ENV_TELEGRAM_TOKEN', null);
        $this->telegramChatIdEnv       = $this->getEnv('ENV_TELEGRAM_CHAT_ID', null);
        $this->telegramConfigJsonPath  = $this->getEnv('ENV_TELEGRAM_CONFIG_JSON_PATH', 'telegram_config.json');
        
        // [monitoring]
        $this->maxRetries           = (int) $this->getEnv('ENV_DB_MAX_RETRIES', 3);
        $this->retryDelaySeconds    = (int) $this->getEnv('ENV_DB_RETRY_DELAY', 5);
        $this->mysqlAutoRestart     = $this->getEnvBool('ENV_MYSQL_AUTO_RESTART', true);
        $this->diskThresholdPercent = (int) $this->getEnv('ENV_DISK_THRESHOLD_PERCENT', 90);
        $this->cpuThresholdLoadAvg  = (float) $this->getEnv('ENV_CPU_THRESHOLD_LOAD_AVG', 1.0);
        $this->memThresholdPercent  = (int) $this->getEnv('ENV_MEM_THRESHOLD_PERCENT', 90);

        // [keamanan]
        $this->loginFailThreshold   = (int) $this->getEnv('ENV_LOGIN_FAIL_THRESHOLD', 5);
        $this->autoBlockIp          = $this->getEnvBool('ENV_AUTO_BLOCK_IP', true);
        $this->fail2banJailName     = $this->getEnv('ENV_FAIL2BAN_JAIL_NAME', 'sshd');
        $this->fail2banClientPath   = $this->getEnv('ENV_FAIL2BAN_CLIENT_PATH', 'fail2ban-client');
        $this->journalctlPath       = $this->getEnv('ENV_JOURNALCTL_PATH', 'journalctl');
        $this->authLogPath          = $this->getEnv('ENV_AUTH_LOG_PATH', '/var/log/auth.log');
        $lastSecCheckFileName       = $this->getEnv('ENV_LAST_SECURITY_CHECK_FILE_NAME', 'last_security_check.txt');

        // [mysql_lanjutan]
        $this->enableMysqlPerformanceCheck = $this->getEnvBool('ENV_ENABLE_MYSQL_PERFORMANCE_CHECK', true);
        $this->mysqlThreadsRunningThreshold= (int) $this->getEnv('ENV_MYSQL_THREADS_RUNNING_THRESHOLD', 50);
        $this->mysqlSlowQueryLogPath     = $this->getEnv('ENV_MYSQL_SLOW_QUERY_LOG_PATH', null);
        $this->mysqlCheckSlowQueryMinutes= (int) $this->getEnv('ENV_MYSQL_CHECK_SLOW_QUERY_MINUTES', 60);
        // Konfigurasi untuk pre-restart backup dihilangkan
        $this->mysqldumpPath        = $this->getEnv('ENV_MYSQLDUMP_PATH', 'mysqldump'); // Tetap jaga jika mysqldump dipakai untuk hal lain

        // [disk_lanjutan]
        $this->enableSmartCheck     = $this->getEnvBool('ENV_ENABLE_SMART_CHECK', true);
        $this->smartctlPath         = $this->getEnv('ENV_SMARTCTL_PATH', 'smartctl');
        $this->diskDevicesToCheck   = $this->getEnv('ENV_DISK_DEVICES_TO_CHECK', '/dev/sda');

        // [wordpress_lanjutan]
        $this->enableWpDebugLogCheck = $this->getEnvBool('ENV_ENABLE_WP_DEBUG_LOG_CHECK', true);
        $wpDebugLogPathEnv           = $this->getEnv('ENV_WP_DEBUG_LOG_PATH', null);
        if (empty($wpDebugLogPathEnv) && !empty($this->wpConfigPathForParser) && file_exists(dirname($this->wpConfigPathForParser) . '/wp-content/')) {
            $this->wpDebugLogPath = dirname($this->wpConfigPathForParser) . '/wp-content/debug.log';
        } else {
            $this->wpDebugLogPath = $wpDebugLogPathEnv;
        }
        $this->wpCheckDebugLogMinutes  = (int) $this->getEnv('ENV_WP_CHECK_DEBUG_LOG_MINUTES', 60);

        // [auto_update]
        $this->githubRepo           = $this->getEnv('ENV_GITHUB_REPO', 'krisdwiantara12/db-monitor');
        $this->githubBranch         = $this->getEnv('ENV_GITHUB_BRANCH', 'main');

        if (version_compare(PHP_VERSION, $this->minPhpVersion, '<')) {
            throw new Exception("Versi PHP minimal yang dibutuhkan adalah {$this->minPhpVersion}. Versi Anda: " . PHP_VERSION, EXIT_PHP_VERSION_LOW);
        }

        $this->logDir = __DIR__ . '/' . $logDirName;
        if (!is_dir($this->logDir)) {
            if (!@mkdir($this->logDir, 0755, true)) {
                if (!is_dir($this->logDir)) {
                    throw new Exception("Gagal membuat direktori log: {$this->logDir}", EXIT_GENERIC_ERROR);
                }
            }
        }
        $this->logFile = "{$this->logDir}/db-monitor-log.txt";
        $this->restartLogFile = "{$this->logDir}/restart-history.log";
        $this->lastRestartFile = "{$this->logDir}/last_restart.txt";
        $this->lastErrorFile = "{$this->logDir}/last_error.json";
        $this->securityTimestampFile = "{$this->logDir}/{$lastSecCheckFileName}";

        if ($this->wpConfigPathForParser && !preg_match('/^\//', $this->wpConfigPathForParser) && $this->wpConfigPathForParser !== "") {
             $this->wpConfigPathForParser = __DIR__ . '/' . $this->wpConfigPathForParser;
        }
        if ($this->wpConfigPathForParser === "") $this->wpConfigPathForParser = null;

        if ($this->telegramConfigJsonPath && !preg_match('/^\//', $this->telegramConfigJsonPath)) {
            $this->telegramConfigJsonPath = __DIR__ . '/' . $this->telegramConfigJsonPath;
        }
    }
}

class Logger {
    private $file;
    private $debugMode = false;

    public function __construct(string $file, bool $debugMode = false) {
        $this->file = $file;
        $this->debugMode = $debugMode;
        if (!file_exists($this->file) && !touch($this->file)) {
             throw new Exception("Gagal membuat file log: {$this->file}", EXIT_GENERIC_ERROR);
        }
        if (!is_writable($this->file)) {
            throw new Exception("File log tidak bisa ditulis: {$this->file}", EXIT_GENERIC_ERROR);
        }
    }

    public function log($msg, $level = 'INFO') {
        $entry = "[" . date('Y-m-d H:i:s') . "] [$level] $msg\n";
        file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX);
        if ($this->debugMode && php_sapi_name() === 'cli') {
            echo $entry;
        }
    }

    public function debug($msg) {
        $this->log($msg, 'DEBUG');
    }
}

class TelegramNotifier {
    private $token, $chatId, $logger;
    private $enabled = true;

    public function __construct(Config $config, Logger $logger) {
        $this->logger = $logger;
        $this->token = $config->telegramTokenEnv;
        $this->chatId = $config->telegramChatIdEnv;

        if (empty($this->token) || empty($this->chatId)) {
            $this->logger->log("Token/Chat ID Telegram tidak diset via ENV_TELEGRAM_TOKEN/ENV_TELEGRAM_CHAT_ID. Mencoba dari file JSON...", "INFO");
            $configJsonPath = $config->telegramConfigJsonPath;
            if (empty($configJsonPath) || !file_exists($configJsonPath)) {
                $this->logger->log("Path file konfigurasi Telegram JSON tidak diset atau file '{$configJsonPath}' tidak ditemukan. Notifikasi Telegram dinonaktifkan.", "WARNING");
                $this->enabled = false;
                return;
            }
            $cfg = json_decode(file_get_contents($configJsonPath), true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($cfg['telegram_token']) || empty($cfg['telegram_chat_id'])) {
                $this->logger->log("Token/Chat ID Telegram di '{$configJsonPath}' belum diatur dengan benar atau file JSON tidak valid. Notifikasi Telegram dinonaktifkan.", "WARNING");
                $this->enabled = false;
                return;
            }
            $this->token = $cfg['telegram_token'];
            $this->chatId = $cfg['telegram_chat_id'];
        }
        
        if (empty($this->token) || empty($this->chatId)) {
             $this->logger->log("Token/Chat ID Telegram tetap tidak ditemukan setelah semua upaya. Notifikasi Telegram dinonaktifkan.", "WARNING");
             $this->enabled = false;
        } else {
            $this->logger->log("Konfigurasi Telegram berhasil dimuat.", "INFO");
        }
    }

    public function send($message, $serverName) {
        if (!$this->enabled) {
            $this->logger->log("Notifikasi Telegram dinonaktifkan, pesan tidak dikirim: {$message}");
            return false;
        }
        $fullMessage = "[{$serverName}]\n{$message}";
        if (mb_strlen($fullMessage, 'UTF-8') > 4096) {
            $fullMessage = mb_substr($fullMessage, 0, 4090, 'UTF-8') . "\n[...]";
            $this->logger->log("Pesan Telegram dipotong karena melebihi 4096 karakter.", "WARNING");
        }
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = ['chat_id' => $this->chatId, 'text' => $fullMessage, 'parse_mode' => 'HTML'];
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data), 'timeout' => 10, 'ignore_errors' => true]];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $this->logger->log("Gagal mengirim notifikasi Telegram: Request failed. Error: " . ($error['message'] ?? 'Unknown error'), "ERROR");
            return false;
        }
        $responseData = json_decode($response, true);
        if (!$responseData || !$responseData['ok']) {
            $errorCode = $responseData['error_code'] ?? 'N/A';
            $description = $responseData['description'] ?? 'No description';
            $this->logger->log("Gagal mengirim notifikasi Telegram: API Error Code {$errorCode} - {$description}", "ERROR");
            return false;
        }
        $this->logger->log("Notifikasi Telegram berhasil dikirim.");
        return true;
    }
}

class WPConfigParser {
    private $filePath;
    private $dbCredentials = [];
    public $siteDomain = null;

    public function __construct(?string $filePath, Logger $logger) {
        if (empty($filePath)) {
            $logger->log("Path wp-config.php tidak diset atau kosong. Fitur yang bergantung pada WP tidak akan aktif.", "INFO");
            $this->filePath = null;
            $this->determineSiteDomain();
            return;
        }
        if (!file_exists($filePath)) {
            $logger->log("wp-config.php tidak ditemukan di: {$filePath}. Fitur yang bergantung pada WP tidak akan aktif.", "WARNING");
            $this->filePath = null;
            $this->determineSiteDomain();
            return;
        }
        $this->filePath = $filePath;
        $this->parseConfig();
        $this->determineSiteDomain();
    }

    private function parseConfig() {
        if (!$this->filePath) return;
        $content = file_get_contents($this->filePath);
        $keys = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'WP_HOME', 'WP_SITEURL'];
        foreach ($keys as $key) {
            if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.+?)['\"]\s*\)/", $content, $m)) {
                $this->dbCredentials[$key] = $m[1];
            }
        }
    }
    
    private function determineSiteDomain() {
        $home = $this->dbCredentials['WP_HOME'] ?? $this->dbCredentials['WP_SITEURL'] ?? null;
        if ($home) {
            $parsed = parse_url($home);
            $this->siteDomain = $parsed['host'] ?? null;
        }
        if (!$this->siteDomain) {
            $this->siteDomain = gethostname() ?: 'server_default';
        }
    }

    public function getConfigValue($key) {
        return $this->dbCredentials[$key] ?? null;
    }
    public function getSiteDomain() { return $this->siteDomain; }
    public function getDbCredentials() {
        return ['host' => $this->getConfigValue('DB_HOST'), 'user' => $this->getConfigValue('DB_USER'), 'pass' => $this->getConfigValue('DB_PASSWORD'), 'name' => $this->getConfigValue('DB_NAME')];
    }
}

class ProcessLock {
    private $lockFile;
    private $fp;

    public function __construct(string $lockFile) { $this->lockFile = $lockFile; }
    public function acquire() {
        $this->fp = fopen($this->lockFile, 'c+');
        if (!$this->fp) { throw new Exception("Tidak dapat membuka atau membuat lockfile: {$this->lockFile}", EXIT_LOCK_FAILED); }
        if (!flock($this->fp, LOCK_EX | LOCK_NB)) {
            $pid = fgets($this->fp); fclose($this->fp);
            throw new Exception("Script sudah berjalan di proses lain (PID: " . ($pid ?: 'unknown') . "). Lockfile: {$this->lockFile}", EXIT_LOCK_FAILED);
        }
        ftruncate($this->fp, 0); fwrite($this->fp, getmypid() . "\n"); fflush($this->fp);
    }
    public function release() {
        if ($this->fp) { flock($this->fp, LOCK_UN); fclose($this->fp); @unlink($this->lockFile); $this->fp = null; }
    }
    public function __destruct() { $this->release(); }
}

function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, Config $config, $currentVersion, $serverName) {
    $baseUrl = 'https://raw.githubusercontent.com/' . $config->githubRepo . '/' . $config->githubBranch;
    $versionUrl = $baseUrl . '/version.txt';
    $scriptUrl = $baseUrl . '/' . basename(__FILE__);

    try {
        $logger->log("Mengecek update dari {$config->githubRepo} cabang {$config->githubBranch}...");
        $remoteVersion = trim(@file_get_contents($versionUrl));
        if (!$remoteVersion) { $logger->log("Gagal mengambil versi remote dari {$versionUrl}. Auto-update dilewati."); return; }
        $logger->log("Versi lokal: {$currentVersion}, Versi remote: {$remoteVersion}");

        if (version_compare($remoteVersion, $currentVersion, '>')) {
            $logger->log("Auto–update: Memulai update dari v{$currentVersion} ke v{$remoteVersion}");
            $notifier->send("🔔 Memulai auto-update script ke v{$remoteVersion}...", $serverName);
            $backupFile = __FILE__ . '.bak.' . time();
            if (!copy(__FILE__, $backupFile)) { throw new Exception("Gagal membuat backup script ke {$backupFile}"); }
            $logger->log("Backup script lama disimpan di: {$backupFile}");
            $newScript = @file_get_contents($scriptUrl);
            if ($newScript) {
                if (file_put_contents(__FILE__, $newScript) === false) { throw new Exception("Gagal menulis script baru ke " . __FILE__); }
                $logger->log("Script berhasil di-update ke v{$remoteVersion}. Skrip akan di-restart.");
                $notifier->send("✅ Script berhasil di-update ke v{$remoteVersion}! Skrip akan dijalankan ulang oleh cron/scheduler.", $serverName);
                exit(EXIT_UPDATED); 
            }
            throw new Exception("Gagal mengambil konten script baru dari {$scriptUrl}");
        } else { $logger->log("Script sudah versi terbaru (v{$currentVersion}). Tidak ada update."); }
    } catch (Exception $e) {
        $logger->log("Auto–update error: " . $e->getMessage(), "ERROR");
        $notifier->send("❌ Auto-update GAGAL: " . $e->getMessage(), $serverName);
    }
}

class DatabaseMonitor {
    private Config $config;
    private Logger $logger;
    private TelegramNotifier $notifier;
    private ?WPConfigParser $wpParser;
    private string $serverName;
    private bool $anErrorOccurred = false;

    public function __construct(Config $config, Logger $logger, TelegramNotifier $notifier, ?WPConfigParser $wpParser) {
        $this->config = $config; $this->logger = $logger; $this->notifier = $notifier; $this->wpParser = $wpParser;
        $this->serverName = ($this->wpParser && $this->wpParser->getSiteDomain()) ? $this->wpParser->getSiteDomain() : (gethostname() ?: 'server_default');
        global $serverNameGlobal; $serverNameGlobal = $this->serverName;
        autoUpdateScript($this->logger, $this->notifier, $this->config, LOCAL_VERSION, $this->serverName);
    }

    private function sendAlert($emoji, $title, $problem, $solution = "", $level = "ERROR") {
        $this->anErrorOccurred = true;
        $message = "{$emoji} <b>{$title}</b>\nProblem: {$problem}\n" . ($solution ? "Solusi: {$solution}" : "");
        $this->logger->log("{$title}: {$problem}" . ($solution ? " Solusi: {$solution}" : ""), $level);
        $this->notifier->send($message, $this->serverName);
    }
    
    private function executeCommand($command, $errorMessage = "Error executing command") {
        $this->logger->debug("Executing command: {$command}");
        $output = @shell_exec("{$command} 2>&1");
        if ($output === null) {
            $this->logger->log("{$errorMessage}: Command `{$command}` failed or returned null.", "ERROR");
            return null;
        }
        $this->logger->debug("Output for `{$command}`: {$output}");
        return trim($output);
    }

    public function run() {
        try {
            $this->logger->log("Monitoring dimulai untuk {$this->serverName} (v" . LOCAL_VERSION . ")");
            $this->checkDatabaseConnection();
            $this->checkDiskUsage();
            $this->checkSystemLoad();
            $this->checkMemoryUsage();
            $this->checkSecurity();
            if ($this->config->enableMysqlPerformanceCheck) { $this->checkMySQLPerformance(); }
            if ($this->config->enableSmartCheck) { $this->checkDiskSMART(); }
            if ($this->config->enableWpDebugLogCheck && $this->wpParser && $this->config->wpDebugLogPath) { $this->checkWPDebugLog(); }
            $this->logger->log("Monitoring selesai untuk {$this->serverName}");
            if ($this->config->normalNotification && !$this->anErrorOccurred) {
                $this->notifier->send("✅ Semua sistem terpantau normal pada {$this->serverName}.", $this->serverName);
                $this->logger->log("Notifikasi normal dikirim: Semua sistem OK.");
            }
        } catch (Exception $e) {
            $this->anErrorOccurred = true;
            $errorMessage = "Error utama DBMonitor: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
            $this->logger->log($errorMessage, "ERROR");
            $this->notifier->send("❌ Fatal Error Monitoring: " . $e->getMessage(), $this->serverName);
        }
    }

    private function checkDatabaseConnection() {
        if (!$this->wpParser || empty($this->wpParser->getDbCredentials()['host'])) {
            $this->logger->log("WPConfigParser tidak init atau DB host kosong, cek koneksi DB dilewati.", "INFO"); return;
        }
        $dbCreds = $this->wpParser->getDbCredentials();
        if (empty($dbCreds['user']) || empty($dbCreds['name'])) {
            $this->logger->log("Kredensial DB (user/nama) tidak lengkap, cek koneksi DB dilewati.", "WARNING"); return;
        }
        $host = $dbCreds['host']; $port = 3306;
        if (strpos($host, ':') !== false) { list($host, $port) = explode(':', $host, 2); $port = (int)$port; }
        $user = $dbCreds['user']; $pass = $dbCreds['pass']; $db = $dbCreds['name'];
        $attempts = 0; $maxRetries = $this->config->maxRetries; $retryDelay = $this->config->retryDelaySeconds;

        while ($attempts < $maxRetries) {
            $attempts++;
            $this->logger->log("Mencoba koneksi ke DB (Percobaan {$attempts}/{$maxRetries})...");
            $mysqli = @new mysqli($host, $user, $pass, $db, $port);
            if ($mysqli->connect_errno) { $err = $mysqli->connect_error; }
            elseif (!$mysqli->query('SELECT 1')) { $err = $mysqli->error; }
            else {
                $mysqli->close(); $this->logger->log("Koneksi DB berhasil (Percobaan {$attempts})");
                if (file_exists($this->config->lastErrorFile)) { @unlink($this->config->lastErrorFile); }
                return;
            }
            $this->logger->log("Koneksi DB percobaan {$attempts} gagal: {$err}", "WARNING");
            if ($attempts < $maxRetries) { sleep($retryDelay); }
            else {
                $problem = "Gagal koneksi DB setelah {$attempts} percobaan: {$err}";
                $solution = "Periksa kredensial wp-config.php. Cek status MySQL: `sudo systemctl status mysql`.";
                $this->sendAlert("🚨", "MySQL Connection Failed", $problem, $solution);
                $errorData = ['time' => date('Y-m-d H:i:s'), 'error' => $err, 'attempts' => $attempts];
                file_put_contents($this->config->lastErrorFile, json_encode($errorData));
                if ($this->config->mysqlAutoRestart) { // Langsung restart tanpa backup
                    $this->restartMySQL();
                }
                return; 
            }
        }
    }

    // Metode backupMySQLDatabase() telah dihilangkan

    private function restartMySQL() {
        $this->logger->log("Mencoba me-restart MySQL service...");
        $outputMysql = $this->executeCommand('sudo systemctl restart mysql', "Gagal me-restart MySQL (systemctl restart mysql)");
        $lastErrorExists = file_exists($this->config->lastErrorFile); $finalOutput = $outputMysql;
        if ( ($outputMysql === null || stripos($outputMysql, 'fail') !== false || stripos($outputMysql, 'error') !== false) && $lastErrorExists ) {
             $this->logger->log("Restart mysql gagal. Mencoba mariadb... Output mysql: {$outputMysql}", "WARNING");
             $outputMariadb = $this->executeCommand('sudo systemctl restart mariadb', "Gagal me-restart MariaDB (systemctl restart mariadb)");
             $finalOutput = "MySQL: {$outputMysql}\nMariaDB: {$outputMariadb}";
        }
        $this->logger->log("Output restart MySQL/MariaDB: {$finalOutput}");
        $this->notifier->send("🔄 MySQL/MariaDB di-restart. Output: " . substr($finalOutput, 0, 1000), $this->serverName);
        file_put_contents($this->config->lastRestartFile, date('Y-m-d H:i:s'));
        $restartLogger = new Logger($this->config->restartLogFile, $this->config->debugMode);
        $restartLogger->log("MySQL/MariaDB di-restart. Output: {$finalOutput}", "INFO");
        if ($lastErrorExists) { @unlink($this->config->lastErrorFile); }
    }

    private function checkDiskUsage() { 
        $threshold = $this->config->diskThresholdPercent;
        $paths_to_check = ['/' => 'Root Filesystem'];
        if (is_dir('/var/lib/mysql')) { $paths_to_check['/var/lib/mysql'] = 'MySQL Data Directory'; }
        
        foreach($paths_to_check as $path => $description) {
            $output = $this->executeCommand("df -P " . escapeshellarg($path), "Gagal menjalankan df untuk {$path}");
            if ($output && preg_match('/(\d+)%\s+' . preg_quote($path, '/') . '$/m', $output, $matches)) {
                $usage = (int)$matches[1];
                $this->logger->log("Penggunaan disk {$description} ({$path}): {$usage}%");
                if ($usage > $threshold) {
                    $problem = "Penggunaan disk {$description} ({$path}) {$usage}%, > threshold {$threshold}%.";
                    $solution = "Segera bersihkan disk. Cek log besar, `sudo apt clean`, atau upgrade disk.";
                    $this->sendAlert("⚠️", "Disk Usage Critical", $problem, $solution);
                }
            } else { $this->logger->log("Gagal parsing df {$path}. Output: " . substr($output, 0, 200), "WARNING"); }
        }
    }

    private function checkSystemLoad() { 
        $threshold = $this->config->cpuThresholdLoadAvg; $load = sys_getloadavg(); $load1Min = $load[0];
        $this->logger->log("CPU load average (1 min): {$load1Min}");
        if ($load1Min > $threshold) {
            $problem = "CPU load average (1 menit) {$load1Min}, > threshold {$threshold}.";
            $solution = "Gunakan `top` atau `htop` untuk cek proses berat.";
            $this->sendAlert("🔥", "CPU Load High", $problem, $solution);
        }
    }

    private function getMemoryUsageInfo() { 
        $meminfoPath = '/proc/meminfo';
        if (!is_readable($meminfoPath)) {
            $this->logger->log("/proc/meminfo tidak terbaca. Cek memori via /proc/meminfo dilewati.", "WARNING");
            $output = $this->executeCommand("free -m", "Gagal 'free -m'");
            if ($output && preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $output, $matches)) {
                $total = (int)$matches[1]; $used = (int)$matches[2]; 
                $available = isset($matches[6]) ? (int)$matches[6] : ($total - $used);
                $actualUsed = $total - $available; $percent = round(($actualUsed / $total) * 100);
                return ['total_mb' => $total, 'used_mb' => $actualUsed, 'percent' => $percent];
            } return null;
        }
        $meminfo = file($meminfoPath); $mem = [];
        foreach ($meminfo as $line) { if (strpos($line, ':') !== false) { list($key, $val) = explode(':', $line, 2); $mem[trim($key)] = intval(trim($val));}}
        if (isset($mem['MemTotal'], $mem['MemAvailable'])) {
            $total_kb = $mem['MemTotal']; $available_kb = $mem['MemAvailable']; $used_kb = $total_kb - $available_kb;
            $percent = ($total_kb > 0) ? round(($used_kb / $total_kb) * 100) : 0;
            return ['total_mb' => round($total_kb / 1024), 'used_mb' => round($used_kb / 1024), 'percent' => $percent];
        } $this->logger->log("Gagal dapat MemTotal/MemAvailable dari /proc/meminfo.", "WARNING"); return null;
    }

    private function checkMemoryUsage() { 
        $threshold = $this->config->memThresholdPercent; $memUsage = $this->getMemoryUsageInfo();
        if ($memUsage) {
            $this->logger->log("Memori: {$memUsage['used_mb']}MB / {$memUsage['total_mb']}MB ({$memUsage['percent']}%)");
            if ($memUsage['percent'] > $threshold) {
                $problem = "Memori {$memUsage['percent']}%, > threshold {$threshold}%. ({$memUsage['used_mb']}MB dari {$memUsage['total_mb']}MB).";
                $solution = "Matikan service tidak perlu, optimasi aplikasi, tambah swap, atau upgrade RAM.";
                $this->sendAlert("🧠", "Memory Usage High", $problem, $solution);
            }
        } else { $this->logger->log("Tidak dapat ambil data penggunaan memori.", "WARNING"); }
    }

    private function commandExists(string $commandName): bool {
        if (strpos($commandName, '/') !== false) { return is_executable($commandName); }
        $output = $this->executeCommand("command -v " . escapeshellarg($commandName));
        return !empty($output);
    }

    private function checkSecurity() { 
        $tsFile = $this->config->securityTimestampFile; $lastTs = is_file($tsFile) ? (int)file_get_contents($tsFile) : (time() - 300);
        $since = date('Y-m-d H:i:s', $lastTs); $logs = ""; $journalctlCmd = $this->config->journalctlPath;
        if ($journalctlCmd && $this->commandExists($journalctlCmd)) {
            $this->logger->log("Membaca log SSHD via journalctl ({$journalctlCmd}) sejak {$since}...");
            $logs = $this->executeCommand("{$journalctlCmd} -u sshd --since=\"{$since}\" --no-pager", "Gagal baca log SSHD via journalctl");
        } else {
            $authLog = $this->config->authLogPath;
            if (is_readable($authLog)) {
                $this->logger->log("journalctl tidak ada/dikonfigurasi. Mencoba {$authLog}...", "WARNING");
                $logContent = $this->executeCommand("tail -n 1000 " . escapeshellarg($authLog));
                if ($logContent) {
                    $lines = explode("\n", $logContent);
                    foreach ($lines as $line) { if (preg_match('/^([A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2})/', $line, $dateMatch)) {
                        $logTime = @strtotime($dateMatch[1]); if ($logTime && $logTime > $lastTs) { $logs .= $line . "\n"; } } }
                    $this->logger->log("Dapat ".count(explode("\n", $logs))." baris relevan dari {$authLog}");
                }
            } else { $this->logger->log("journalctl & {$authLog} tidak diakses. Cek keamanan SSH dilewati.", "ERROR"); file_put_contents($tsFile, time()); return; }
        }
        $fails = [];
        if ($logs && preg_match_all("/Failed password for .* from (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}) port \d+ ssh2/i", $logs, $matches)) { foreach ($matches[1] as $ip) { $fails[$ip] = ($fails[$ip] ?? 0) + 1; }
        } elseif ($logs && preg_match_all("/Connection closed by authenticating user .* (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}) port \d+ \[preauth\]/i", $logs, $matches)) { foreach ($matches[1] as $ip) { $fails[$ip] = ($fails[$ip] ?? 0) + 1; } }
        file_put_contents($tsFile, time());
        $failThreshold = $this->config->loginFailThreshold; $autoBlock = $this->config->autoBlockIp;
        $jailName = $this->config->fail2banJailName; $fail2banClientCmd = $this->config->fail2banClientPath;
        if ($autoBlock && !$this->commandExists($fail2banClientCmd)) { $this->logger->log("Fail2Ban client ('{$fail2banClientCmd}') tidak ada. Auto-block dinonaktifkan.", "ERROR"); $autoBlock = false; }
        foreach ($fails as $ip => $count) {
            if ($count >= $failThreshold) {
                $problem = "{$count} percobaan login SSH gagal dari IP {$ip} (> threshold {$failThreshold})."; $solution = "";
                if ($autoBlock) {
                    $statusOutput = $this->executeCommand("sudo {$fail2banClientCmd} status {$jailName}", "Gagal cek status Fail2Ban jail {$jailName}");
                    if ($statusOutput && strpos($statusOutput, $ip) !== false) { $this->logger->log("IP {$ip} sudah diblokir di Fail2Ban jail {$jailName}."); $solution = "IP {$ip} sudah terblokir di Fail2Ban."; }
                    else {
                        $blockOutput = $this->executeCommand("sudo {$fail2banClientCmd} set {$jailName} banip " . escapeshellarg($ip), "Gagal blokir IP {$ip} via Fail2Ban");
                        if ($blockOutput !== null && (stripos($blockOutput, $ip) !== false || stripos($blockOutput, '1 already banned') !== false || $blockOutput === "1" || stripos($blockOutput, 'banned') !== false)) {
                            $this->logger->log("IP {$ip} berhasil diblokir via Fail2Ban jail {$jailName}. Output: {$blockOutput}"); $solution = "IP {$ip} telah diblokir via Fail2Ban.";
                        } else { $this->logger->log("Gagal blokir IP {$ip} via Fail2Ban. Output: {$blockOutput}", "ERROR"); $solution = "Gagal blokir IP {$ip}. Cek log & Fail2Ban."; }
                    }
                } else { $solution = "Auto-block IP dinonaktifkan. Periksa IP {$ip} manual."; }
                $this->sendAlert("🛡️", "SSH Brute-Force Attempt", $problem, $solution);
            }
        }
    }

    private function checkMySQLPerformance() { 
        if (!$this->wpParser || empty($this->wpParser->getDbCredentials()['host'])) { $this->logger->log("WPConfigParser tidak init atau DB host kosong, cek performa MySQL dilewati.", "INFO"); return; }
        $dbCreds = $this->wpParser->getDbCredentials();
        if (empty($dbCreds['user']) || empty($dbCreds['name'])) { $this->logger->log("Kredensial DB (user/nama) tidak lengkap, cek performa MySQL dilewati.", "WARNING"); return; }
        $host = $dbCreds['host']; $port = 3306; if (strpos($host, ':') !== false) list($host, $port) = explode(':', $host, 2);
        $mysqli = @new mysqli($host, $dbCreds['user'], $dbCreds['pass'], $dbCreds['name'], (int)$port);
        if ($mysqli->connect_errno) { $this->logger->log("Tidak dapat konek MySQL untuk cek performa: {$mysqli->connect_error}", "WARNING"); return; }
        $threadsThreshold = $this->config->mysqlThreadsRunningThreshold;
        if ($result = $mysqli->query("SHOW GLOBAL STATUS LIKE 'Threads_running'")) {
            $row = $result->fetch_assoc(); $threadsRunning = (int)$row['Value']; $this->logger->log("MySQL Threads_running: {$threadsRunning}");
            if ($threadsRunning > $threadsThreshold) {
                $problem = "MySQL Threads_running ({$threadsRunning}) > threshold ({$threadsThreshold}).";
                $solution = "Periksa query lama atau koneksi tinggi. Gunakan `SHOW PROCESSLIST;`.";
                $this->sendAlert("📈", "MySQL High Threads Running", $problem, $solution);
            } $result->free();
        }
        $slowQueryLog = $this->config->mysqlSlowQueryLogPath; $checkSQMinutes = $this->config->mysqlCheckSlowQueryMinutes;
        if ($slowQueryLog && is_readable($slowQueryLog)) {
            $lastModified = filemtime($slowQueryLog);
            if ($lastModified > (time() - ($checkSQMinutes * 60))) {
                $tailOutput = $this->executeCommand("tail -n 5 " . escapeshellarg($slowQueryLog));
                $problem = "Slow query log MySQL ({$slowQueryLog}) termodifikasi dalam {$checkSQMinutes} menit terakhir.";
                $solution = "Kemungkinan ada query lambat baru. Baris terakhir:\n<pre>" . htmlspecialchars($tailOutput) . "</pre>";
                $this->sendAlert("🐢", "MySQL Slow Queries Detected", $problem, $solution, "WARNING");
            } else { $this->logger->log("Slow query log ({$slowQueryLog}) tidak termodifikasi baru-baru ini."); }
        } elseif ($slowQueryLog) { $this->logger->log("Slow query log path '{$slowQueryLog}' tidak terbaca.", "WARNING"); }
        $mysqli->close();
    }

    private function checkDiskSMART() { 
        $smartctlCmd = $this->config->smartctlPath;
        if (!$this->commandExists($smartctlCmd)) { $this->logger->log("smartctl ('{$smartctlCmd}') tidak ada/dikonfigurasi. Cek SMART dilewati.", "WARNING"); return; }
        $devicesString = $this->config->diskDevicesToCheck;
        if (empty($devicesString)) { $this->logger->log("Tidak ada disk dikonfigurasi untuk cek SMART.", "INFO"); return; }
        $devices = explode(',', $devicesString);
        foreach ($devices as $device) {
            $device = trim($device); if (empty($device)) continue;
            $this->logger->log("Mengecek status SMART untuk disk {$device}...");
            $output = $this->executeCommand("sudo {$smartctlCmd} -H " . escapeshellarg($device), "Gagal menjalankan smartctl untuk {$device}");
            if ($output) {
                if (preg_match("/SMART overall-health self-assessment test result: PASSED/i", $output)) { $this->logger->log("Status SMART {$device}: PASSED"); }
                elseif (preg_match("/SMART overall-health self-assessment test result: FAILED/i", $output) || preg_match("/FAILING_NOW/i", $output) || preg_match("/PRE-FAIL_NOW/i", $output) ) {
                    $problem = "Status SMART disk {$device} menunjukkan: FAILED atau FAILING/PRE-FAIL.";
                    $solution = "Disk mungkin segera rusak! Segera backup & ganti. Output:\n<pre>" . substr(htmlspecialchars($output), 0, 1000) . "</pre>";
                    $this->sendAlert("💥", "Disk SMART Failure Predicted", $problem, $solution);
                } else {
                     $this->logger->log("Status SMART {$device} tidak PASSED/FAILED. Output: " . substr($output,0,200), "WARNING");
                     if (stripos($output, "SMART support is: Disabled") !== false) { $this->sendAlert("❓", "Disk SMART Disabled", "SMART support disabled for {$device}.", "Enable SMART untuk {$device}.", "WARNING"); }
                }
            } else { $this->logger->log("Tidak ada output smartctl untuk {$device}.", "WARNING"); }
        }
    }
    
    private function checkWPDebugLog() { 
        $debugLog = $this->config->wpDebugLogPath; $checkMinutes = $this->config->wpCheckDebugLogMinutes;
        if (empty($debugLog)) { $this->logger->log("Path WP debug.log tidak diset. Pengecekan dilewati.", "INFO"); return; }
        if (is_readable($debugLog)) {
            $lastModified = filemtime($debugLog);
            if ($lastModified > (time() - ($checkMinutes * 60))) {
                $this->logger->log("WP debug.log ({$debugLog}) termodifikasi dalam {$checkMinutes} menit terakhir.");
                $logContent = $this->executeCommand("tail -n 20 " . escapeshellarg($debugLog)); $relevantErrors = [];
                if ($logContent) {
                    $lines = explode("\n", $logContent);
                    foreach($lines as $line) { if (preg_match('/(PHP Fatal error|PHP Warning|PHP Parse error|PHP Notice|WordPress database error)/i', $line)) {
                             if(preg_match('/^\[([^\]]+)\]/', $line, $tsMatch)) { $logEntryTime = @strtotime($tsMatch[1]); if ($logEntryTime && $logEntryTime < (time() - ($checkMinutes * 60 * 2))) { continue; } }
                             $relevantErrors[] = htmlspecialchars(trim($line)); } }
                }
                if (!empty($relevantErrors)) {
                    $problem = "Error baru di WP debug.log ({$debugLog}) dalam {$checkMinutes} menit terakhir.";
                    $solution = "Periksa debug.log. Error terakhir:\n<pre>" . implode("\n", array_slice($relevantErrors, -5)) . "</pre>";
                    $this->sendAlert("🐞", "WordPress Debug Log Error", $problem, $solution, "WARNING");
                } else { $this->logger->log("WP debug.log termodifikasi, tapi tidak ditemukan error signifikan baru."); }
            } else { $this->logger->log("WP debug.log ({$debugLog}) tidak termodifikasi baru-baru ini."); }
        } else { $this->logger->log("WP debug.log path '{$debugLog}' tidak terbaca/ditemukan.", "INFO"); }
    }
}

// === Eksekusi Utama ===
$exitCode = EXIT_SUCCESS; $lock = null; 
try {
    $configGlobal = new Config();
    $loggerGlobal = new Logger($configGlobal->logFile, $configGlobal->debugMode);
    $GLOBALS['loggerGlobal'] = $loggerGlobal; // Supaya bisa diakses di Config::getEnv jika perlu log saat init awal
    // $configGlobal = new Config(); // Re-init tidak perlu, logger sudah bisa dipakai jika debug di getEnv

    $lock = new ProcessLock($configGlobal->lockFilePath); $lock->acquire();
    $notifierGlobal = new TelegramNotifier($configGlobal, $loggerGlobal);
    if ($configGlobal->wpConfigPathForParser) {
        $wpParserGlobal = new WPConfigParser($configGlobal->wpConfigPathForParser, $loggerGlobal);
        $serverNameGlobal = $wpParserGlobal->getSiteDomain() ?: (gethostname() ?: 'server_default');
    } else { $wpParserGlobal = null; $serverNameGlobal = gethostname() ?: 'server_default'; }
    $monitor = new DatabaseMonitor($configGlobal, $loggerGlobal, $notifierGlobal, $wpParserGlobal);
    $monitor->run();
} catch (Exception $e) {
    $exitCode = $e->getCode() ?: EXIT_GENERIC_ERROR;
    $errorMessage = "Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    if (isset($loggerGlobal) && $loggerGlobal instanceof Logger) { $loggerGlobal->log($errorMessage, "FATAL"); }
    else {
        $fallbackLogDir = __DIR__ . '/' . (getenv('ENV_LOG_DIR_NAME') ?: 'log_db_monitor');
        if (!is_dir($fallbackLogDir)) @mkdir($fallbackLogDir, 0755, true);
        $fallbackLogFile = $fallbackLogDir . '/db-monitor-fatal.log';
        @file_put_contents($fallbackLogFile, "[" . date('Y-m-d H:i:s') . "] FATAL: {$errorMessage}\n", FILE_APPEND);
    }
    if (isset($notifierGlobal) && $notifierGlobal instanceof TelegramNotifier && $exitCode != EXIT_LOCK_FAILED) {
        $telegramMessage = "❌ Skrip monitoring error: " . $e->getMessage();
        if(strlen($telegramMessage) > 200) $telegramMessage = substr($telegramMessage, 0, 200) . "...";
        $notifierGlobal->send($telegramMessage, $serverNameGlobal ?: (gethostname() ?: 'unknown_server'));
    }
    fwrite(STDERR, $errorMessage . "\n");
} finally {
    if (isset($lock) && $lock instanceof ProcessLock) { $lock->release(); }
    if (isset($loggerGlobal)) { $loggerGlobal->log("Skrip selesai dengan exit code: {$exitCode}"); }
    exit($exitCode);
}
?>
