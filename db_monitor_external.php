#!/usr/bin/php
<?php
/**
 * db_monitor_external.php
 *
 * Versi Revisi: 2.2.2 (Optimal Tanpa Pre-Backup)
 * Fitur Unggulan Revisi:
 * - [DIHILANGKAN] Fitur backup database otomatis sebelum restart MySQL dihilangkan sesuai permintaan.
 * - [BARU] Pengecekan keamanan tambahan via command `lastb`.
 * - [BARU] Pengecekan performa MySQL tambahan: monitoring 'Aborted_connects'.
 * - [BARU] Pemantauan path disk yang dinamis dan bisa dikonfigurasi.
 * - Menggunakan typed properties pada class untuk kualitas kode yang lebih baik (PHP 7.4+).
 *
 * Versi Sebelumnya:
 * - Konfigurasi terpusat via environment variables
 * - Notifikasi Telegram, Auto–update dari GitHub
 * - Monitoring: MySQL (koneksi, auto-restart), Performa, Disk, CPU, Memori, Keamanan, WordPress
 */

define('LOCAL_VERSION', '2.2.2'); // Versi diperbarui

define('EXIT_SUCCESS', 0);
define('EXIT_UPDATED', 0);
define('EXIT_GENERIC_ERROR', 1);
define('EXIT_LOCK_FAILED', 2);
define('EXIT_CONFIG_ERROR', 3);
define('EXIT_PHP_VERSION_LOW', 4);
define('EXIT_DEPENDENCY_ERROR', 5);

// Global variables
$configGlobal = null;
$loggerGlobal = null;
$notifierGlobal = null;
$wpParserGlobal = null;
$serverNameGlobal = 'unknown_server';

if (php_sapi_name() !== 'cli') {
    die("Error: Script hanya bisa dijalankan via command line\n");
}

class Config {
    // Typed properties (PHP 7.4+)
    public string $logDir, $logFile, $lockFilePath;
    public ?string $wpConfigPathForParser;
    public string $minPhpVersion;
    public bool $debugMode, $normalNotification;
    public ?string $telegramTokenEnv, $telegramChatIdEnv;
    public string $telegramConfigJsonPath;
    public int $maxRetries, $retryDelaySeconds;
    public bool $mysqlAutoRestart;
    public int $diskThresholdPercent;
    public float $cpuThresholdLoadAvg;
    public int $memThresholdPercent;
    public int $loginFailThreshold;
    public bool $autoBlockIp;
    public string $fail2banJailName, $fail2banClientPath, $journalctlPath, $authLogPath, $securityTimestampFile;
    public bool $enableMysqlPerformanceCheck;
    public int $mysqlThreadsRunningThreshold;
    public ?string $mysqlSlowQueryLogPath;
    public int $mysqlCheckSlowQueryMinutes;
    public bool $enableSmartCheck;
    public string $smartctlPath, $diskDevicesToCheck;
    public bool $enableWpDebugLogCheck;
    public ?string $wpDebugLogPath;
    public int $wpCheckDebugLogMinutes;
    public string $githubRepo, $githubBranch;
    public string $restartLogFile, $lastRestartFile, $lastErrorFile;
    public string $extraDiskPathsToCheck;
    public bool $enableLastbCheck;
    public int $mysqlAbortedConnectsThreshold;
    
    // Properti terkait backup MySQL dihilangkan
    
    private function getEnv(string $varName, $defaultValue = null) {
        $value = getenv($varName);
        if ($value === false && isset($GLOBALS['loggerGlobal']) && $GLOBALS['loggerGlobal'] instanceof Logger) {
             $GLOBALS['loggerGlobal']->debug("ENV '{$varName}' tidak diset, menggunakan default: '{$defaultValue}'");
        }
        return ($value !== false) ? $value : $defaultValue;
    }

    private function getEnvBool(string $varName, bool $defaultValue): bool {
        $valueStr = $this->getEnv($varName, $defaultValue ? 'true' : 'false');
        return filter_var(strtolower($valueStr), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $defaultValue;
    }

    public function __construct() {
        $logDirName                 = $this->getEnv('ENV_LOG_DIR_NAME', 'log_db_monitor');
        $this->lockFilePath         = $this->getEnv('ENV_LOCK_FILE_PATH', '/tmp/db_monitor_external.lock');
        $this->wpConfigPathForParser= $this->getEnv('ENV_WP_CONFIG_PATH', null);
        $this->minPhpVersion        = $this->getEnv('ENV_MIN_PHP_VERSION', '7.4.0');
        $this->debugMode            = $this->getEnvBool('ENV_DEBUG_MODE', false);
        $this->normalNotification   = $this->getEnvBool('ENV_NORMAL_NOTIFICATION', false);
        $this->telegramTokenEnv        = $this->getEnv('ENV_TELEGRAM_TOKEN', null);
        $this->telegramChatIdEnv       = $this->getEnv('ENV_TELEGRAM_CHAT_ID', null);
        $this->telegramConfigJsonPath  = $this->getEnv('ENV_TELEGRAM_CONFIG_JSON_PATH', 'telegram_config.json');
        
        $this->maxRetries           = (int) $this->getEnv('ENV_DB_MAX_RETRIES', 3);
        $this->retryDelaySeconds    = (int) $this->getEnv('ENV_DB_RETRY_DELAY', 5);
        $this->mysqlAutoRestart     = $this->getEnvBool('ENV_MYSQL_AUTO_RESTART', true);
        $this->diskThresholdPercent = (int) $this->getEnv('ENV_DISK_THRESHOLD_PERCENT', 90);
        $this->cpuThresholdLoadAvg  = (float) $this->getEnv('ENV_CPU_THRESHOLD_LOAD_AVG', 1.5);
        $this->memThresholdPercent  = (int) $this->getEnv('ENV_MEM_THRESHOLD_PERCENT', 90);
        $this->extraDiskPathsToCheck = $this->getEnv('ENV_EXTRA_PATHS_TO_CHECK', '/home,/var/log');

        $this->loginFailThreshold   = (int) $this->getEnv('ENV_LOGIN_FAIL_THRESHOLD', 5);
        $this->autoBlockIp          = $this->getEnvBool('ENV_AUTO_BLOCK_IP', true);
        $this->fail2banJailName     = $this->getEnv('ENV_FAIL2BAN_JAIL_NAME', 'sshd');
        $this->fail2banClientPath   = $this->getEnv('ENV_FAIL2BAN_CLIENT_PATH', 'fail2ban-client');
        $this->journalctlPath       = $this->getEnv('ENV_JOURNALCTL_PATH', 'journalctl');
        $this->authLogPath          = $this->getEnv('ENV_AUTH_LOG_PATH', '/var/log/auth.log');
        $lastSecCheckFileName       = $this->getEnv('ENV_LAST_SECURITY_CHECK_FILE_NAME', 'last_security_check.txt');
        $this->enableLastbCheck     = $this->getEnvBool('ENV_ENABLE_LASTB_CHECK', true);

        $this->enableMysqlPerformanceCheck = $this->getEnvBool('ENV_ENABLE_MYSQL_PERFORMANCE_CHECK', true);
        $this->mysqlThreadsRunningThreshold= (int) $this->getEnv('ENV_MYSQL_THREADS_RUNNING_THRESHOLD', 80);
        $this->mysqlSlowQueryLogPath     = $this->getEnv('ENV_MYSQL_SLOW_QUERY_LOG_PATH', null);
        $this->mysqlCheckSlowQueryMinutes= (int) $this->getEnv('ENV_MYSQL_CHECK_SLOW_QUERY_MINUTES', 60);
        $this->mysqlAbortedConnectsThreshold = (int) $this->getEnv('ENV_MYSQL_ABORTED_CONNECTS_THRESHOLD', 10);
        
        // Konfigurasi untuk pre-restart backup dihilangkan
        
        $this->enableSmartCheck     = $this->getEnvBool('ENV_ENABLE_SMART_CHECK', true);
        $this->smartctlPath         = $this->getEnv('ENV_SMARTCTL_PATH', 'smartctl');
        $this->diskDevicesToCheck   = $this->getEnv('ENV_DISK_DEVICES_TO_CHECK', '/dev/sda');

        $this->enableWpDebugLogCheck = $this->getEnvBool('ENV_ENABLE_WP_DEBUG_LOG_CHECK', true);
        $wpDebugLogPathEnv           = $this->getEnv('ENV_WP_DEBUG_LOG_PATH', null);
        if (empty($wpDebugLogPathEnv) && !empty($this->wpConfigPathForParser) && file_exists(dirname($this->wpConfigPathForParser) . '/wp-content/')) {
            $this->wpDebugLogPath = dirname($this->wpConfigPathForParser) . '/wp-content/debug.log';
        } else {
            $this->wpDebugLogPath = $wpDebugLogPathEnv;
        }
        $this->wpCheckDebugLogMinutes  = (int) $this->getEnv('ENV_WP_CHECK_DEBUG_LOG_MINUTES', 60);

        $this->githubRepo           = $this->getEnv('ENV_GITHUB_REPO', 'krisdwiantara12/db-monitor');
        $this->githubBranch         = $this->getEnv('ENV_GITHUB_BRANCH', 'main');

        if (version_compare(PHP_VERSION, $this->minPhpVersion, '<')) {
            throw new Exception("Versi PHP minimal {$this->minPhpVersion} dibutuhkan. Versi Anda: " . PHP_VERSION, EXIT_PHP_VERSION_LOW);
        }

        $this->logDir = __DIR__ . '/' . $logDirName;
        if (!is_dir($this->logDir)) {
            if (!@mkdir($this->logDir, 0755, true) && !is_dir($this->logDir)) {
                throw new Exception("Gagal membuat direktori log: {$this->logDir}", EXIT_GENERIC_ERROR);
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

// ... (Class Logger, TelegramNotifier, WPConfigParser, ProcessLock, autoUpdateScript tidak berubah)
class Logger {
    private string $file;
    private bool $debugMode;

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

    public function log(string $msg, string $level = 'INFO'): void {
        $entry = "[" . date('Y-m-d H:i:s') . "] [$level] $msg\n";
        file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX);
        if ($this->debugMode && php_sapi_name() === 'cli') {
            echo $entry;
        }
    }

    public function debug(string $msg): void {
        $this->log($msg, 'DEBUG');
    }
}

class TelegramNotifier {
    private ?string $token;
    private ?string $chatId;
    private Logger $logger;
    private bool $enabled = true;

    public function __construct(Config $config, Logger $logger) {
        $this->logger = $logger;
        $this->token = $config->telegramTokenEnv;
        $this->chatId = $config->telegramChatIdEnv;

        if (empty($this->token) || empty($this->chatId)) {
            $this->logger->log("Token/Chat ID Telegram tidak diset via ENV. Mencoba dari file JSON...", "INFO");
            $configJsonPath = $config->telegramConfigJsonPath;
            if (empty($configJsonPath) || !file_exists($configJsonPath)) {
                $this->logger->log("Path file konfigurasi Telegram JSON tidak diset atau file '{$configJsonPath}' tidak ditemukan. Notifikasi dinonaktifkan.", "WARNING");
                $this->enabled = false;
                return;
            }
            $cfg = json_decode(file_get_contents($configJsonPath), true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($cfg['telegram_token']) || empty($cfg['telegram_chat_id'])) {
                $this->logger->log("Token/Chat ID Telegram di '{$configJsonPath}' tidak valid. Notifikasi dinonaktifkan.", "WARNING");
                $this->enabled = false;
                return;
            }
            $this->token = $cfg['telegram_token'];
            $this->chatId = $cfg['telegram_chat_id'];
        }
        
        if (empty($this->token) || empty($this->chatId)) {
             $this->logger->log("Token/Chat ID Telegram tetap tidak ditemukan. Notifikasi dinonaktifkan.", "WARNING");
             $this->enabled = false;
        } else {
            $this->logger->log("Konfigurasi Telegram berhasil dimuat.", "INFO");
        }
    }

    public function send(string $message, string $serverName): bool {
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
            $this->logger->log("Gagal mengirim notifikasi Telegram: " . ($error['message'] ?? 'Unknown error'), "ERROR");
            return false;
        }
        $responseData = json_decode($response, true);
        if (!$responseData || !$responseData['ok']) {
            $errorCode = $responseData['error_code'] ?? 'N/A';
            $description = $responseData['description'] ?? 'No description';
            $this->logger->log("Gagal mengirim notifikasi Telegram: API Error {$errorCode} - {$description}", "ERROR");
            return false;
        }
        $this->logger->log("Notifikasi Telegram berhasil dikirim.");
        return true;
    }
}

class WPConfigParser {
    private ?string $filePath;
    private array $dbCredentials = [];
    public ?string $siteDomain = null;

    public function __construct(?string $filePath, Logger $logger) {
        if (empty($filePath)) {
            $logger->debug("Path wp-config.php tidak diset.");
            $this->filePath = null;
            $this->determineSiteDomain();
            return;
        }
        if (!file_exists($filePath)) {
            $logger->log("wp-config.php tidak ditemukan di: {$filePath}.", "WARNING");
            $this->filePath = null;
            $this->determineSiteDomain();
            return;
        }
        $this->filePath = $filePath;
        $this->parseConfig($logger);
        $this->determineSiteDomain();
    }

    private function parseConfig(Logger $logger): void {
        if (!$this->filePath) return;
        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            $logger->log("Gagal membaca file wp-config.php di: {$this->filePath}", "ERROR");
            return;
        }
        $keys = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'WP_HOME', 'WP_SITEURL'];
        foreach ($keys as $key) {
            if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $content, $m)) {
                $this->dbCredentials[$key] = $m[1];
            }
        }
    }
    
    private function determineSiteDomain(): void {
        $home = $this->dbCredentials['WP_HOME'] ?? $this->dbCredentials['WP_SITEURL'] ?? null;
        if ($home) {
            $parsed = parse_url($home);
            $this->siteDomain = $parsed['host'] ?? null;
        }
        if (!$this->siteDomain) {
            $this->siteDomain = gethostname() ?: 'server_default';
        }
    }

    public function getConfigValue(string $key): ?string {
        return $this->dbCredentials[$key] ?? null;
    }
    public function getSiteDomain(): ?string { return $this->siteDomain; }
    public function getDbCredentials(): array {
        return ['host' => $this->getConfigValue('DB_HOST'), 'user' => $this->getConfigValue('DB_USER'), 'pass' => $this->getConfigValue('DB_PASSWORD'), 'name' => $this->getConfigValue('DB_NAME')];
    }
}

class ProcessLock {
    private string $lockFile;
    private $fp = null;

    public function __construct(string $lockFile) { $this->lockFile = $lockFile; }
    public function acquire(): void {
        $this->fp = @fopen($this->lockFile, 'c+');
        if (!$this->fp) { throw new Exception("Tidak dapat membuka atau membuat lockfile: {$this->lockFile}", EXIT_LOCK_FAILED); }
        if (!flock($this->fp, LOCK_EX | LOCK_NB)) {
            $pid = fgets($this->fp); fclose($this->fp); $this->fp = null;
            throw new Exception("Script sudah berjalan (PID: " . ($pid ?: 'unknown') . "). Lockfile: {$this->lockFile}", EXIT_LOCK_FAILED);
        }
        ftruncate($this->fp, 0); fwrite($this->fp, getmypid() . "\n"); fflush($this->fp);
    }
    public function release(): void {
        if ($this->fp) { flock($this->fp, LOCK_UN); fclose($this->fp); @unlink($this->lockFile); $this->fp = null; }
    }
    public function __destruct() { $this->release(); }
}

function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, Config $config, string $currentVersion, string $serverName): void {
    $baseUrl = 'https://raw.githubusercontent.com/' . $config->githubRepo . '/' . $config->githubBranch;
    $versionUrl = $baseUrl . '/version.txt';
    $scriptUrl = $baseUrl . '/' . basename(__FILE__);

    try {
        $logger->log("Mengecek update dari {$config->githubRepo} cabang {$config->githubBranch}...");
        $remoteVersion = trim(@file_get_contents($versionUrl));
        if (!$remoteVersion) { $logger->log("Gagal mengambil versi remote. Auto-update dilewati."); return; }
        $logger->log("Versi lokal: {$currentVersion}, Versi remote: {$remoteVersion}");

        if (version_compare($remoteVersion, $currentVersion, '>')) {
            $logger->log("Memulai auto-update dari v{$currentVersion} ke v{$remoteVersion}");
            $notifier->send("🔔 Memulai auto-update script ke v{$remoteVersion}...", $serverName);
            $backupFile = __FILE__ . '.bak.' . time();
            if (!copy(__FILE__, $backupFile)) { throw new Exception("Gagal membuat backup script ke {$backupFile}"); }
            $logger->log("Backup script lama disimpan di: {$backupFile}");
            $newScript = @file_get_contents($scriptUrl);
            if ($newScript) {
                if (file_put_contents(__FILE__, $newScript) === false) { throw new Exception("Gagal menulis script baru ke " . __FILE__); }
                $logger->log("Script berhasil di-update ke v{$remoteVersion}. Skrip akan dijalankan ulang.");
                $notifier->send("✅ Script berhasil di-update ke v{$remoteVersion}! Akan dijalankan ulang oleh scheduler.", $serverName);
                exit(EXIT_UPDATED); 
            }
            throw new Exception("Gagal mengambil konten script baru dari {$scriptUrl}");
        } else { $logger->log("Script sudah versi terbaru."); }
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

    private function sendAlert(string $emoji, string $title, string $problem, string $solution = "", string $level = "ERROR") {
        $this->anErrorOccurred = true;
        $message = "{$emoji} <b>{$title}</b>\nProblem: {$problem}\n" . ($solution ? "Solusi: {$solution}" : "");
        $this->logger->log("{$title}: {$problem}" . ($solution ? " Solusi: {$solution}" : ""), $level);
        $this->notifier->send($message, $this->serverName);
    }
    
    private function executeCommand(string $command, string $errorMessage = "Error executing command"): ?string {
        $this->logger->debug("Executing: {$command}");
        $output = @shell_exec("{$command} 2>&1");
        if ($output === null) {
            $this->logger->log("{$errorMessage}: `{$command}` gagal atau null.", "ERROR");
            return null;
        }
        $this->logger->debug("Output `{$command}`: {$output}");
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
            }
        } catch (Exception $e) {
            $this->anErrorOccurred = true;
            $errorMessage = "Error utama DBMonitor: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}";
            $this->logger->log($errorMessage, "ERROR");
            $this->notifier->send("❌ Fatal Error Monitoring: " . $e->getMessage(), $this->serverName);
        }
    }

    private function checkDatabaseConnection() {
        if (!$this->wpParser || empty($this->wpParser->getDbCredentials()['host'])) {
            $this->logger->log("DB host kosong, cek koneksi DB dilewati.", "INFO"); return;
        }
        $dbCreds = $this->wpParser->getDbCredentials();
        if (empty($dbCreds['user']) || empty($dbCreds['name'])) {
            $this->logger->log("Kredensial DB tidak lengkap, cek koneksi DB dilewati.", "WARNING"); return;
        }
        $host = $dbCreds['host']; $port = 3306;
        if (strpos($host, ':') !== false) { list($host, $port) = explode(':', $host, 2); $port = (int)$port; }
        $user = $dbCreds['user']; $pass = $dbCreds['pass']; $db = $dbCreds['name'];
        $attempts = 0; $maxRetries = $this->config->maxRetries; $retryDelay = $this->config->retryDelaySeconds;
        $err = '';

        while ($attempts < $maxRetries) {
            $attempts++;
            $this->logger->log("Mencoba koneksi DB (Percobaan {$attempts}/{$maxRetries})...");
            $mysqli = @new mysqli($host, $user, $pass, $db, $port);
            if (!$mysqli->connect_errno && $mysqli->query('SELECT 1')) {
                $mysqli->close(); $this->logger->log("Koneksi DB berhasil.");
                if (file_exists($this->config->lastErrorFile)) { @unlink($this->config->lastErrorFile); }
                return;
            }
            $err = $mysqli->connect_errno ? $mysqli->connect_error : $mysqli->error;
            $this->logger->log("Koneksi DB percobaan {$attempts} gagal: {$err}", "WARNING");
            if ($attempts < $maxRetries) { sleep($retryDelay); }
        }
        
        $problem = "Gagal koneksi DB setelah {$attempts} percobaan: {$err}";
        $solution = "Periksa kredensial & status MySQL. Mencoba restart...";
        $this->sendAlert("🚨", "MySQL Connection Failed", $problem, $solution);
        file_put_contents($this->config->lastErrorFile, json_encode(['time' => date('Y-m-d H:i:s'), 'error' => $err, 'attempts' => $attempts]));

        if ($this->config->mysqlAutoRestart) {
            $this->restartMySQL();
        }
    }

    // Metode backupMySQLDatabase() telah dihilangkan

    private function restartMySQL() {
        $this->logger->log("Mencoba me-restart MySQL service (tanpa backup)...");
        $finalOutput = '';
        $outputMysql = $this->executeCommand('sudo systemctl restart mysql', "Gagal me-restart MySQL");
        
        if ($outputMysql === null || stripos($outputMysql, 'fail') !== false || stripos($outputMysql, 'error') !== false) {
             $this->logger->log("Restart mysql gagal. Mencoba mariadb... Output mysql: {$outputMysql}", "WARNING");
             $outputMariadb = $this->executeCommand('sudo systemctl restart mariadb', "Gagal me-restart MariaDB");
             $finalOutput = "MySQL attempt: {$outputMysql}\nMariaDB attempt: {$outputMariadb}";
        } else {
            $finalOutput = "OK";
        }
        
        $this->logger->log("Output restart MySQL/MariaDB: {$finalOutput}");
        $this->notifier->send("🔄 Layanan MySQL/MariaDB telah di-restart.", $this->serverName);
        file_put_contents($this->config->lastRestartFile, date('Y-m-d H:i:s'));
        $restartLogger = new Logger($this->config->restartLogFile, $this->config->debugMode);
        $restartLogger->log("MySQL/MariaDB di-restart. Output: {$finalOutput}", "INFO");
        if (file_exists($this->config->lastErrorFile)) { @unlink($this->config->lastErrorFile); }
    }

    private function checkDiskUsage() { 
        $threshold = $this->config->diskThresholdPercent;
        $paths_to_check = ['/' => 'Root Filesystem', '/var/lib/mysql' => 'MySQL Data Directory'];
        
        $extraPaths = array_filter(explode(',', $this->config->extraDiskPathsToCheck));
        foreach ($extraPaths as $path) {
            $path = trim($path);
            if (!isset($paths_to_check[$path])) {
                $paths_to_check[$path] = "Configured Path ({$path})";
            }
        }
        
        foreach($paths_to_check as $path => $description) {
            if (!is_dir($path)) continue;
            $output = $this->executeCommand("df -P " . escapeshellarg($path), "Gagal df {$path}");
            if ($output && preg_match('/(\d+)%\s+' . preg_quote($path, '/') . '$/m', $output, $matches)) {
                $usage = (int)$matches[1];
                $this->logger->log("Penggunaan disk {$description}: {$usage}%");
                if ($usage > $threshold) {
                    $problem = "Disk {$description} ({$path}) {$usage}%, > threshold {$threshold}%.";
                    $solution = "Segera bersihkan disk. Cek log besar, `sudo apt-get clean`.";
                    $this->sendAlert("⚠️", "Disk Usage Critical", $problem, $solution);
                }
            } else { $this->logger->log("Gagal parsing df {$path}. Output: " . substr($output, 0, 200), "WARNING"); }
        }
    }
    
    // ... (checkSystemLoad, getMemoryUsageInfo, checkMemoryUsage, commandExists, checkSecurity, checkMySQLPerformance, checkDiskSMART, checkWPDebugLog sama persis dengan versi sebelumnya yang optimal)
    private function checkSystemLoad() { 
        $threshold = $this->config->cpuThresholdLoadAvg; $load = sys_getloadavg(); $load1Min = $load[0];
        $this->logger->log("CPU load average (1 min): {$load1Min}");
        if ($load1Min > $threshold) {
            $problem = "CPU load average (1 menit) {$load1Min}, > threshold {$threshold}.";
            $solution = "Gunakan `top` atau `htop` untuk cek proses berat.";
            $this->sendAlert("🔥", "CPU Load High", $problem, $solution);
        }
    }

    private function getMemoryUsageInfo(): ?array { 
        $meminfoPath = '/proc/meminfo';
        if (!is_readable($meminfoPath)) {
            $this->logger->log("/proc/meminfo tidak terbaca. Fallback ke 'free -m'.", "WARNING");
            $output = $this->executeCommand("free -m", "Gagal 'free -m'");
            if ($output && preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $output, $matches)) {
                $total = (int)$matches[1]; $used = (int)$matches[2]; 
                $available = (int)($matches[6] ?? ($matches[3] + $matches[5]));
                $actualUsed = $total - $available; $percent = ($total > 0) ? round(($actualUsed / $total) * 100) : 0;
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
                $solution = "Matikan service tidak perlu, optimasi aplikasi, atau upgrade RAM.";
                $this->sendAlert("🧠", "Memory Usage High", $problem, $solution);
            }
        } else { $this->logger->log("Tidak dapat ambil data penggunaan memori.", "WARNING"); }
    }

    private function commandExists(string $commandName): bool {
        if (strpos($commandName, '/') !== false) { return is_executable($commandName); }
        return !empty($this->executeCommand("command -v " . escapeshellarg($commandName)));
    }

    private function checkSecurity() { 
        $tsFile = $this->config->securityTimestampFile; 
        $lastTs = is_file($tsFile) ? (int)@file_get_contents($tsFile) : (time() - 3600);
        $since = date('Y-m-d H:i:s', $lastTs); 
        $fails = [];

        $logs = "";
        if ($this->config->journalctlPath && $this->commandExists($this->config->journalctlPath)) {
            $this->logger->log("Membaca log SSHD via journalctl sejak {$since}...");
            $logs = $this->executeCommand("{$this->config->journalctlPath} -u sshd --since=\"{$since}\" --no-pager", "Gagal baca log SSHD");
        } else {
            if (is_readable($this->config->authLogPath)) {
                $this->logger->log("journalctl tidak ada. Mencoba {$this->config->authLogPath}...", "WARNING");
                $logContent = $this->executeCommand("tail -n 1000 " . escapeshellarg($this->config->authLogPath));
                if ($logContent) {
                    foreach (explode("\n", $logContent) as $line) { 
                        if (preg_match('/^([A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2})/', $line, $m) && @strtotime($m[1]) > $lastTs) {
                            $logs .= $line . "\n"; 
                        }
                    }
                }
            }
        }
        if ($logs && preg_match_all("/Failed password for .* from (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/i", $logs, $matches)) {
            foreach ($matches[1] as $ip) { $fails[$ip] = ($fails[$ip] ?? 0) + 1; }
        }
        
        if ($this->config->enableLastbCheck && $this->commandExists('lastb')) {
            $this->logger->log("Membaca log login gagal via `lastb`...");
            $lastbOutput = $this->executeCommand("sudo lastb -s " . date('YmdHis', $lastTs) . " -F");
            if ($lastbOutput && preg_match_all('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\s+.*$/m', $lastbOutput, $matches)) {
                foreach ($matches[1] as $ip) { $fails[$ip] = ($fails[$ip] ?? 0) + 1; }
            }
        }

        file_put_contents($tsFile, time());
        $failThreshold = $this->config->loginFailThreshold; $autoBlock = $this->config->autoBlockIp;
        $jailName = $this->config->fail2banJailName; $fail2banCmd = $this->config->fail2banClientPath;
        if ($autoBlock && !$this->commandExists($fail2banCmd)) { 
            $this->logger->log("Fail2Ban client ('{$fail2banCmd}') tidak ada. Auto-block dinonaktifkan.", "ERROR"); $autoBlock = false; 
        }
        
        foreach ($fails as $ip => $count) {
            if ($count >= $failThreshold) {
                $problem = "{$count} percobaan login SSH gagal dari IP {$ip} (> threshold {$failThreshold})."; $solution = "";
                if ($autoBlock) {
                    $blockOutput = $this->executeCommand("sudo {$fail2banCmd} set {$jailName} banip " . escapeshellarg($ip));
                    if ($blockOutput !== null && (stripos($blockOutput, $ip) !== false || stripos($blockOutput, 'already banned') !== false || $blockOutput === "1")) {
                        $solution = "IP {$ip} telah diblokir via Fail2Ban.";
                    } else { $solution = "Gagal blokir IP {$ip}. Cek log & Fail2Ban."; }
                } else { $solution = "Auto-block dinonaktifkan. Periksa IP {$ip} manual."; }
                $this->sendAlert("🛡️", "SSH Brute-Force Attempt", $problem, $solution);
            }
        }
    }
    
    private function checkMySQLPerformance() { 
        if (!$this->wpParser || empty($this->wpParser->getDbCredentials()['host'])) { return; }
        $dbCreds = $this->wpParser->getDbCredentials();
        if (empty($dbCreds['user']) || empty($dbCreds['name'])) { return; }
        $host = $dbCreds['host']; $port = 3306; if (strpos($host, ':') !== false) list($host, $port) = explode(':', $host, 2);
        $mysqli = @new mysqli($host, $dbCreds['user'], $dbCreds['pass'], $dbCreds['name'], (int)$port);
        if ($mysqli->connect_errno) { $this->logger->log("Tidak dapat konek MySQL untuk cek performa: {$mysqli->connect_error}", "WARNING"); return; }

        $result = $mysqli->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_running', 'Aborted_connects')");
        if ($result) {
            $status = []; while ($row = $result->fetch_assoc()) { $status[$row['Variable_name']] = (int)$row['Value']; }
            $result->free();

            if (($status['Threads_running'] ?? 0) > $this->config->mysqlThreadsRunningThreshold) {
                $this->sendAlert("📈", "MySQL High Threads Running", "Threads_running ({$status['Threads_running']}) > threshold ({$this->config->mysqlThreadsRunningThreshold}).", "Periksa `SHOW PROCESSLIST;`.");
            }
            if (($status['Aborted_connects'] ?? 0) > $this->config->mysqlAbortedConnectsThreshold) {
                $this->sendAlert("🔗", "MySQL High Aborted Connects", "Aborted_connects ({$status['Aborted_connects']}) > threshold ({$this->config->mysqlAbortedConnectsThreshold}).", "Cek masalah jaringan atau kredensial salah.", "WARNING");
            }
        }

        $slowLog = $this->config->mysqlSlowQueryLogPath;
        if ($slowLog && is_readable($slowLog) && filemtime($slowLog) > (time() - ($this->config->mysqlCheckSlowQueryMinutes * 60))) {
            $tail = $this->executeCommand("tail -n 5 " . escapeshellarg($slowLog));
            $this->sendAlert("🐢", "MySQL Slow Queries", "Slow query log termodifikasi baru-baru ini.", "Baris terakhir:\n<pre>" . htmlspecialchars($tail) . "</pre>", "WARNING");
        }
        $mysqli->close();
    }
    
    private function checkDiskSMART() { 
        $smartctlCmd = $this->config->smartctlPath;
        if (!$this->commandExists($smartctlCmd)) { $this->logger->log("smartctl tidak ada. Cek SMART dilewati.", "WARNING"); return; }
        if (empty($this->config->diskDevicesToCheck)) { return; }
        foreach (explode(',', $this->config->diskDevicesToCheck) as $device) {
            $device = trim($device); if (empty($device)) continue;
            $output = $this->executeCommand("sudo {$smartctlCmd} -H " . escapeshellarg($device));
            if ($output) {
                if (preg_match("/SMART overall-health self-assessment test result: PASSED/i", $output)) { $this->logger->log("SMART {$device}: PASSED"); }
                elseif (preg_match("/(FAILED|FAILING_NOW|PRE-FAIL_NOW)/i", $output)) {
                    $this->sendAlert("💥", "Disk SMART Failure", "Status SMART {$device} menunjukkan FAILED/FAILING.", "Disk mungkin segera rusak! Segera backup & ganti.");
                } elseif (stripos($output, "SMART support is: Disabled") !== false) { 
                    $this->sendAlert("❓", "Disk SMART Disabled", "SMART support disabled untuk {$device}.", "Enable SMART jika didukung.", "WARNING"); 
                }
            }
        }
    }
    
    private function checkWPDebugLog() { 
        $debugLog = $this->config->wpDebugLogPath;
        if (empty($debugLog)) { return; }
        if (is_readable($debugLog) && filemtime($debugLog) > (time() - ($this->config->wpCheckDebugLogMinutes * 60))) {
            $logContent = $this->executeCommand("tail -n 20 " . escapeshellarg($debugLog)); 
            $errors = [];
            if ($logContent) {
                foreach(explode("\n", $logContent) as $line) { 
                    if (preg_match('/(PHP Fatal error|PHP Warning|WordPress database error)/i', $line)) {
                         if(preg_match('/^\[([^\]]+)\]/', $line, $m) && @strtotime($m[1]) < (time() - 3600)) { continue; }
                         $errors[] = htmlspecialchars(trim($line)); 
                    } 
                }
            }
            if (!empty($errors)) {
                $this->sendAlert("🐞", "WordPress Debug Log Error", "Error baru di WP debug.log.", "Error terakhir:\n<pre>" . implode("\n", array_slice($errors, -5)) . "</pre>", "WARNING");
            }
        }
    }
}

// === Eksekusi Utama ===
$exitCode = EXIT_SUCCESS; $lock = null; 
try {
    $configGlobal = new Config();
    $loggerGlobal = new Logger($configGlobal->logFile, $configGlobal->debugMode);
    $GLOBALS['loggerGlobal'] = $loggerGlobal; 

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
    $errorMessage = "Fatal Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}";
    if (isset($loggerGlobal)) { $loggerGlobal->log($errorMessage, "FATAL"); }
    else {
        $logDir = __DIR__ . '/' . (getenv('ENV_LOG_DIR_NAME') ?: 'log_db_monitor');
        @mkdir($logDir, 0755, true);
        @file_put_contents($logDir . '/db-monitor-fatal.log', "[" . date('Y-m-d H:i:s') . "] {$errorMessage}\n", FILE_APPEND);
    }
    if (isset($notifierGlobal) && $exitCode != EXIT_LOCK_FAILED) {
        $notifierGlobal->send("❌ Skrip monitoring error: " . substr($e->getMessage(), 0, 200), $serverNameGlobal ?: 'unknown_server');
    }
    fwrite(STDERR, $errorMessage . "\n");
} finally {
    if (isset($lock)) { $lock->release(); }
    if (isset($loggerGlobal)) { $loggerGlobal->log("Skrip selesai dengan exit code: {$exitCode}"); }
    exit($exitCode);
}
?>
