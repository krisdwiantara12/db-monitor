#!/usr/bin/php
<?php
/**
 * Skrip ini berfungsi untuk memonitor koneksi MySQL dan melakukan notifikasi via Telegram serta
 * merestart MySQL secara otomatis jika terjadi masalah. Selain itu, skrip ini juga melakukan:
 *
 * 1. Pemulihan Pasca Restart
 *    - Mengirim notifikasi ketika MySQL telah kembali normal setelah restart.
 *
 * 2. Blokir Restart Berulang
 *    - Membatasi maksimal restart sebanyak 3x dalam 10 menit dan mengirim notifikasi darurat
 *      jika batas tersebut terlampaui.
 *
 * 3. Peringatan Disk Space
 *    - Mengecek penggunaan disk pada partisi /var/lib/mysql dan mengirim notifikasi jika penggunaan
 *      melebihi threshold (default 90%).
 *
 * 4. Connection Pool Monitoring
 *    - Memantau persentase koneksi MySQL aktif dibandingkan dengan nilai max_connections,
 *      mengirim notifikasi jika melebihi threshold (default 80%).
 *
 * 5. Integritas Konfigurasi
 *    - Memeriksa integritas file konfigurasi (wp-config.php dan file MySQL) menggunakan hash MD5.
 *
 * 6. Dependency Check
 *    - Memastikan layanan pendukung (misal: cron, php-fpm) aktif.
 *
 * Pastikan variabel sensitif dan parameter lainnya dapat diatur melalui environment variable,
 * walaupun untuk contoh ini nilai default diberikan.
 */

if (php_sapi_name() !== 'cli') {
    die("Skrip hanya dapat dijalankan dari command line.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Class Config
 * Mengatur konfigurasi dasar skrip serta parameter tambahan.
 */
class Config {
    public $logDir;
    public $logFile;
    public $restartLogFile;
    public $lastRestartFile;
    public $lastErrorFile;

    public $maxRetries;
    public $retryDelay;
    public $telegramToken;
    public $telegramChatId;
    public $autoRestart;
    public $debug;
    public $normalNotification;
    
    public $wpConfigPath;
    
    // Tambahan konfigurasi baru
    public $diskThreshold;
    public $connPoolThreshold;
    public $dependenciesToCheck;
    public $mysqlConfigPath;
    public $hashFile;
    public $maxRestarts;
    public $restartPeriod;

    public function __construct() {
        // Direktori dan file log
        $this->logDir          = __DIR__ . '/log_db_monitor';
        $this->logFile         = $this->logDir . '/db-monitor-log.txt';
        $this->restartLogFile  = $this->logDir . '/restart-history.log';
        $this->lastRestartFile = $this->logDir . '/last_restart.txt';
        $this->lastErrorFile   = $this->logDir . '/last_error.json';

        // Pengaturan koneksi dan retry
        $this->maxRetries  = getenv('DB_MAX_RETRIES') ? (int)getenv('DB_MAX_RETRIES') : 3;
        $this->retryDelay  = getenv('DB_RETRY_DELAY') ? (int)getenv('DB_RETRY_DELAY') : 5;
        $this->autoRestart = getenv('AUTO_RESTART') ? filter_var(getenv('AUTO_RESTART'), FILTER_VALIDATE_BOOLEAN) : true;
        $this->debug       = getenv('DEBUG_MODE') ? filter_var(getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN) : false;
        $this->normalNotification = getenv('NORMAL_NOTIFICATION') ? filter_var(getenv('NORMAL_NOTIFICATION'), FILTER_VALIDATE_BOOLEAN) : false;
        
        // Pengaturan Telegram
        $this->telegramToken  = getenv('TELEGRAM_TOKEN')  ?: '7943049250:AAHdnBwodrSmLqsETpZOgN89xERkpX-1Pkk';
        $this->telegramChatId = getenv('TELEGRAM_CHAT_ID') ?: '401856988';
        
        // Lokasi file konfigurasi WordPress (untuk ambil DB config dan URL situs)
        $this->wpConfigPath = __DIR__ . '/wp-config.php';
        
        // Threshold dan parameter tambahan
        $this->diskThreshold = getenv('DISK_THRESHOLD') ? (int)getenv('DISK_THRESHOLD') : 90; // persen
        $this->connPoolThreshold = getenv('CONN_POOL_THRESHOLD') ? (int)getenv('CONN_POOL_THRESHOLD') : 80; // persen
        $this->dependenciesToCheck = explode(',', getenv('DEPENDENCIES') ?: 'cron,php-fpm');
        $this->mysqlConfigPath = getenv('MYSQL_CONFIG') ?: '/etc/mysql/my.cnf';
        $this->hashFile = $this->logDir . '/config_hashes.json';
        $this->maxRestarts = 3;
        $this->restartPeriod = 600; // 10 menit dalam detik
        
        // Pastikan direktori log sudah ada dengan permission yang sesuai
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}

/**
 * Class Logger
 * Menulis pesan log ke file dengan mekanisme file locking.
 */
class Logger {
    private $logFile;
    
    public function __construct($logFile) {
        $this->logFile = $logFile;
    }
    
    public function log($message) {
        $fp = fopen($this->logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, "[" . date('Y-m-d H:i:s') . "] $message\n");
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }
}

/**
 * Class TelegramNotifier
 * Mengirim notifikasi via Telegram menggunakan cURL.
 */
class TelegramNotifier {
    private $token;
    private $chatId;
    private $logger;
    
    public function __construct($token, $chatId, Logger $logger) {
        $this->token  = $token;
        $this->chatId = $chatId;
        $this->logger = $logger;
    }
    
    public function send($text) {
        if (empty($this->token) || empty($this->chatId)) {
            $this->logger->log("Telegram token/chat ID kosong.");
            return false;
        }
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = [
            'chat_id'    => $this->chatId,
            'text'       => $text,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);
        if (!$result) {
            $this->logger->log("ERROR: Telegram gagal dikirim. $error");
            return false;
        }
        return true;
    }
}

/**
 * Class WPConfigParser
 * Membaca file wp-config.php untuk mengambil nilai konfigurasi.
 */
class WPConfigParser {
    private $filePath;
    
    public function __construct($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("wp-config.php tidak ditemukan di: " . $filePath);
        }
        $this->filePath = $filePath;
    }
    
    public function getConfigValue($key) {
        $data = file_get_contents($this->filePath);
        $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($key, '/') . "['\"]\\s*,\\s*['\"](.*?)['\"]\\s*\\)/";
        return preg_match($pattern, $data, $matches) ? $matches[1] : null;
    }
    
    public function getSiteDomain() {
        $home = $this->getConfigValue('WP_HOME') ?: $this->getConfigValue('WP_SITEURL');
        if ($home) {
            $parsed = parse_url($home);
            return isset($parsed['host']) ? $parsed['host'] : 'unknown-domain';
        }
        return 'unknown-domain';
    }
}

/**
 * Class ProcessLock
 * Mengelola mekanisme lock untuk mencegah skrip dijalankan bersamaan.
 */
class ProcessLock {
    private $lockFile;
    private $handle;
    
    public function __construct($lockFile) {
        $this->lockFile = $lockFile;
    }
    
    public function acquire() {
        $this->handle = fopen($this->lockFile, 'c');
        if (!$this->handle || !flock($this->handle, LOCK_EX | LOCK_NB)) {
            throw new Exception("Skrip sedang berjalan.");
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, getmypid());
    }
    
    public function release() {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}

/**
 * Class DatabaseMonitor
 * Melakukan pengecekan koneksi MySQL melalui fsockopen dan MySQLi, menangani restart, serta
 * melakukan notifikasi tambahan:
 *
 * - Pemulihan Pasca Restart
 * - Blokir Restart Berulang
 * - Peringatan Disk Space
 * - Connection Pool Monitoring
 * - Integritas Konfigurasi
 * - Dependency Check
 */
class DatabaseMonitor {
    private $config;
    private $logger;
    private $notifier;
    private $wpConfigParser;
    
    // Properti untuk menyimpan data koneksi dan informasi situs
    private $dbHost;
    private $dbPort;
    private $dbUser;
    private $dbPass;
    private $dbName;
    private $siteName;
    private $serverIP;

    public function __construct(Config $config, Logger $logger, TelegramNotifier $notifier, WPConfigParser $wpConfigParser) {
        $this->config         = $config;
        $this->logger         = $logger;
        $this->notifier       = $notifier;
        $this->wpConfigParser = $wpConfigParser;
    }
    
    public function getSystemInfo() {
        $load = sys_getloadavg();
        if (stripos(PHP_OS, 'win') === false) {
            $mem = shell_exec('free -m');
        } else {
            $mem = 'Informasi memori tidak tersedia';
        }
        return "Load: 1m={$load[0]} | Mem:\n" . $mem;
    }
    
    public function writeErrorJson($data) {
        file_put_contents($this->config->lastErrorFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    public function restartMySQL() {
        $output = shell_exec("sudo systemctl restart mysql 2>&1");
        $result = trim($output) ?: 'Perintah restart MySQL telah dikirim.';
        $this->logger->log("Restart: $result");
        file_put_contents($this->config->restartLogFile, "[" . date('Y-m-d H:i:s') . "] $result\n", FILE_APPEND);
        return $result;
    }
    
    /**
     * Fungsi penanganan error terpusat untuk koneksi.
     */
    private function handleError($errorType, $errorMessage, $contextData = []) {
        $siteName  = isset($contextData['site']) ? $contextData['site'] : 'Unknown Site';
        $serverIP  = isset($contextData['ip']) ? $contextData['ip'] : 'Unknown IP';
        $timestamp = isset($contextData['timestamp']) ? $contextData['timestamp'] : date('Y-m-d H:i:s');
        $extra     = isset($contextData['extra']) ? $contextData['extra'] : '';
        
        $jsonErr = [
            'site'  => $siteName,
            'ip'    => $serverIP,
            'time'  => $timestamp,
            'error' => $errorMessage,
            'type'  => $errorType
        ];
        $this->writeErrorJson($jsonErr);
        $this->logger->log("ERROR ($errorType): $errorMessage");
        $msg = "? <b>$siteName</b> GAGAL konek " . (($errorType == 'fsockopen') ? "MySQL" : "DB") . " $extra <pre>$timestamp\nServer: $serverIP\nError: $errorMessage</pre>";
        $this->notifier->send($msg);
        
        if ($this->config->autoRestart) {
            // Cek apakah restart berulang telah terjadi
            if ($this->checkRestartAttempts()) {
                // Jika restart berulang, notifikasi darurat sudah terkirim, tidak melakukan restart otomatis
            } else {
                $restartMsg = $this->restartMySQL();
                touch($this->config->lastRestartFile); // Update file timestamp restart
                $this->notifier->send("?? <b>$siteName</b> MySQL di-restart.\n<pre>$restartMsg</pre>");
            }
        }
    }
    
    public function testFsockopen($host, $port, $siteName, $serverIP) {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        $execTime = round((microtime(true) - $start) * 1000);
        
        if (!$fp) {
            $timestamp = date('Y-m-d H:i:s');
            $extra = "($host:$port) dengan response time: {$execTime} ms";
            $this->handleError('fsockopen', $errstr, [
                'site'      => $siteName, 
                'ip'        => $serverIP, 
                'timestamp' => $timestamp, 
                'extra'     => $extra
            ]);
            throw new Exception("FSOCKOPEN gagal: $errstr");
        } else {
            fclose($fp);
            return $execTime;
        }
    }
    
    private function exponentialBackoff($attempt) {
        // Menggunakan exponential backoff: delay * 2^(attempt-1)
        return $this->config->retryDelay * pow(2, $attempt - 1);
    }
    
    public function testMysqli($host, $user, $pass, $dbname, $port, $siteName, $serverIP) {
        $attempt = 0;
        $lastError = '';
        while ($attempt < $this->config->maxRetries) {
            $attempt++;
            try {
                $conn = new mysqli($host, $user, $pass, $dbname, $port);
                if ($conn->connect_errno) {
                    throw new Exception($conn->connect_error);
                }
                $conn->close();
                $this->logger->log("? <b>$siteName</b> Koneksi DB normal. Percobaan: $attempt");
                return $attempt;
            } catch (mysqli_sql_exception $e) {
                $lastError = $e->getMessage();
                $this->logger->log("Percobaan $attempt: " . $lastError);
                if ($attempt < $this->config->maxRetries) {
                    $delay = $this->exponentialBackoff($attempt);
                    sleep($delay);
                } else {
                    break;
                }
            }
        }
        $timestamp = date('Y-m-d H:i:s');
        $this->handleError('mysqli', "Gagal koneksi DB setelah {$this->config->maxRetries} percobaan. Error: $lastError", [
            'site'      => $siteName, 
            'ip'        => $serverIP, 
            'timestamp' => $timestamp, 
            'extra'     => "setelah {$this->config->maxRetries} percobaan"
        ]);
        throw new Exception("MySQL gagal konek setelah {$this->config->maxRetries} percobaan.");
    }
    
    /**
     * Mengirim notifikasi pemulihan pasca restart.
     */
    private function notifyRecovery() {
        if (!file_exists($this->config->lastRestartFile)) return;
        $lastRestart = filemtime($this->config->lastRestartFile);
        if (time() - $lastRestart > 300) return; // Hanya notifikasi dalam 5 menit pasca restart
        $msg = "✅ <b>".$this->getSiteName()."</b> MySQL berhasil pulih setelah restart";
        $this->notifier->send($msg);
        unlink($this->config->lastRestartFile);
    }
    
    /**
     * Cek penggunaan disk pada partisi /var/lib/mysql.
     */
    private function checkDiskUsage() {
        $mount = '/var/lib/mysql';
        $output = shell_exec("df -h $mount 2>/dev/null | tail -n1");
        if (!$output || !preg_match('/\s(\d+)%\s/', $output, $matches)) return;
        
        $usage = (int)$matches[1];
        if ($usage > $this->config->diskThreshold) {
            $msg = "⚠️ <b>".$this->getSiteName()."</b> Penggunaan disk $mount mencapai $usage%!";
            $this->notifier->send($msg);
            $this->logger->log("Notifikasi disk: penggunaan $usage% pada $mount");
        }
    }

    /**
     * Pantau koneksi MySQL yang aktif (connection pool).
     */
    private function checkConnectionPool() {
        try {
            $conn = new mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
            $vars = $conn->query("SHOW VARIABLES LIKE 'max_connections'")->fetch_assoc();
            $status = $conn->query("SHOW STATUS LIKE 'Threads_connected'")->fetch_assoc();
            $conn->close();
            
            $max = (int)$vars['Value'];
            $used = (int)$status['Value'];
            $percent = round(($used / $max) * 100, 1);
            
            if ($percent > $this->config->connPoolThreshold) {
                $msg = "🔄 <b>".$this->getSiteName()."</b> Koneksi MySQL: $used/$max ($percent%)";
                $this->notifier->send($msg);
                $this->logger->log("Notifikasi connection pool: $used/$max ($percent%)");
            }
        } catch (Exception $e) {
            // Abaikan jika gagal koneksi untuk cek connection pool
        }
    }
    
    /**
     * Validasi integritas file konfigurasi menggunakan MD5.
     */
    private function checkConfigIntegrity() {
        $files = [
            $this->config->wpConfigPath,
            $this->config->mysqlConfigPath
        ];
        
        $hashes = file_exists($this->config->hashFile) ? 
            json_decode(file_get_contents($this->config->hashFile), true) : [];
        
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            
            $current = md5_file($file);
            $previous = $hashes[basename($file)] ?? null;
            
            if ($previous && $current !== $previous) {
                $msg = "🔧 <b>".$this->getSiteName()."</b> Perubahan terdeteksi di ".basename($file);
                $this->notifier->send($msg);
                $this->logger->log("Integritas config: ".basename($file)." berubah.");
            }
            $hashes[basename($file)] = $current;
        }
        
        file_put_contents($this->config->hashFile, json_encode($hashes));
    }
    
    /**
     * Cek status layanan dependensi.
     */
    private function checkDependencies() {
        foreach ($this->config->dependenciesToCheck as $service) {
            $status = shell_exec("systemctl is-active $service 2>/dev/null");
            if (trim($status) !== 'active') {
                $msg = "⚙️ <b>".$this->getSiteName()."</b> Layanan $service tidak aktif!";
                $this->notifier->send($msg);
                $this->logger->log("Dependency check: Layanan $service tidak aktif.");
            }
        }
    }
    
    /**
     * Cek apakah restart MySQL sudah dilakukan terlalu sering (dalam periode tertentu).
     */
    private function checkRestartAttempts() {
        if (!file_exists($this->config->restartLogFile)) return false;
        
        $logs = file($this->config->restartLogFile, FILE_IGNORE_NEW_LINES);
        $recent = 0;
        $now = time();
        
        foreach ($logs as $log) {
            if (preg_match('/\[([^\]]+)\]/', $log, $match)) {
                $logTime = strtotime($match[1]);
                if ($now - $logTime < $this->config->restartPeriod) {
                    $recent++;
                }
            }
        }
        
        if ($recent >= $this->config->maxRestarts) {
            $msg = "🚨 <b>".$this->getSiteName()."</b> Gagal restart MySQL {$this->config->maxRestarts}x dalam 10 menit!";
            $this->notifier->send($msg);
            $this->logger->log("Restart attempts: $recent restart dalam periode {$this->config->restartPeriod} detik.");
            return true;
        }
        return false;
    }
    
    /**
     * Mengembalikan nama situs dari konfigurasi.
     */
    private function getSiteName() {
        return $this->siteName;
    }
    
    /**
     * Fungsi utama untuk menjalankan semua pengecekan.
     */
    public function run() {
        // Ambil konfigurasi database dari wp-config.php
        $dbHostRaw = $this->wpConfigParser->getConfigValue('DB_HOST') ?: 'localhost';
        if (strpos($dbHostRaw, ':') !== false) {
            list($this->dbHost, $port) = explode(':', $dbHostRaw, 2);
            $this->dbPort = (int)$port;
        } else {
            $this->dbHost = $dbHostRaw;
            $this->dbPort = 3306;
        }
        $this->dbUser = $this->wpConfigParser->getConfigValue('DB_USER') ?: 'user';
        $this->dbPass = $this->wpConfigParser->getConfigValue('DB_PASSWORD') ?: 'pass';
        $this->dbName = $this->wpConfigParser->getConfigValue('DB_NAME') ?: 'db';
        
        $this->siteName = $this->wpConfigParser->getSiteDomain();
        $this->serverIP = gethostbyname(gethostname());
        $timestamp = date('Y-m-d H:i:s');
        
        // Uji konektivitas menggunakan fsockopen
        $this->testFsockopen($this->dbHost, $this->dbPort, $this->siteName, $this->serverIP);
        
        // Uji koneksi MySQL menggunakan MySQLi dengan retry dan exponential backoff
        try {
            $attempts = $this->testMysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort, $this->siteName, $this->serverIP);
            $msg = "? <b>{$this->siteName}</b> Koneksi DB normal. Percobaan: $attempts";
            echo "$msg\n";
            if ($this->config->normalNotification) {
                $this->notifier->send($msg);
            }
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }
        
        // Notifikasi pemulihan pasca restart
        $this->notifyRecovery();
        
        // Pengecekan tambahan
        $this->checkDiskUsage();
        $this->checkConnectionPool();
        $this->checkConfigIntegrity();
        $this->checkDependencies();
        
        // Jika mode debug diaktifkan, tampilkan informasi sistem
        if ($this->config->debug) {
            echo $this->getSystemInfo();
        }
    }
}

/* EKSEKUSI UTAMA */
try {
    // Pastikan hanya satu instance skrip yang berjalan
    $lock = new ProcessLock('/tmp/db_monitor.lock');
    $lock->acquire();
    register_shutdown_function(function() use ($lock) {
        $lock->release();
    });
    
    $config   = new Config();
    $logger   = new Logger($config->logFile);
    $notifier = new TelegramNotifier($config->telegramToken, $config->telegramChatId, $logger);
    $wpParser = new WPConfigParser($config->wpConfigPath);
    
    $monitor = new DatabaseMonitor($config, $logger, $notifier, $wpParser);
    $monitor->run();
    
} catch (Exception $ex) {
    error_log($ex->getMessage());
    echo $ex->getMessage() . "\n";
    exit(1);
}
?>
