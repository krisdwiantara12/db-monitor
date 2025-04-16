#!/usr/bin/php
<?php
/**
 * Skrip ini berfungsi untuk:
 * - Memonitor koneksi MySQL (fsockopen & MySQLi) dengan retry dan exponential backoff
 * - Mengirim notifikasi via Telegram jika terjadi kegagalan koneksi atau kondisi kritis lain
 * - Merestart MySQL secara otomatis, dengan pengecualian jika restart berulang
 * - Memulihkan pasca restart dan mengirim notifikasi sukses
 * - Mengecek disk usage, connection pool, integritas konfigurasi, dependency “cron” saja
 * - Auto–update script dari GitHub bila versi remote lebih baru
 *
 * Silakan atur variabel environment seperti DB_MAX_RETRIES, TELEGRAM_TOKEN, NORMAL_NOTIFICATION, dsb.
 */

define('LOCAL_VERSION', '1.0.0');

if (php_sapi_name() !== 'cli') {
    die("Skrip hanya dapat dijalankan dari command line.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Auto–update script: cek version.txt & script.php di GitHub
 */
function autoUpdateScript(Logger $logger, TelegramNotifier $notifier, $localVersion) {
    $verUrl = "https://raw.githubusercontent.com/krisdwiantara12/db-monitor/refs/heads/main/version.txt";
    $scriptUrl = "https://raw.githubusercontent.com/krisdwiantara12/db-monitor/refs/heads/main/script.php";

    // ambil versi remote
    $ch = curl_init($verUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $remoteVer = trim(curl_exec($ch));
    $err = curl_error($ch);
    curl_close($ch);
    if (!$remoteVer) {
        $logger->log("AutoUpdate: gagal ambil versi remote: $err");
        return;
    }

    if (version_compare($remoteVer, $localVersion, '>')) {
        $logger->log("AutoUpdate: update dari v$localVersion → v$remoteVer");
        $newScript = @file_get_contents($scriptUrl);
        if (!$newScript) {
            $logger->log("AutoUpdate: gagal ambil script baru");
            return;
        }

        // backup & tulis
        copy(__FILE__, __FILE__ . '.bak.' . time());
        if (file_put_contents(__FILE__, $newScript) !== false) {
            $msg = "🔄 Script diperbarui ke versi $remoteVer";
            $logger->log("AutoUpdate: berhasil update");
            $notifier->send($msg);
            exit("Script di‑update ke v$remoteVer. Jalankan ulang.\n");
        } else {
            $logger->log("AutoUpdate: gagal menulis file baru");
        }
    } else {
        $logger->log("AutoUpdate: sudah versi terbaru ($localVersion)");
    }
}

/**
 * Konfigurasi umum
 */
class Config {
    public $logDir, $logFile, $restartLogFile, $lastRestartFile, $lastErrorFile;
    public $maxRetries, $retryDelay, $telegramToken, $telegramChatId;
    public $autoRestart, $debug, $normalNotification;
    public $wpConfigPath;
    // threshold & parameter tambahan
    public $diskThreshold, $connPoolThreshold;
    public $dependenciesToCheck;
    public $mysqlConfigPath, $hashFile;
    public $maxRestarts, $restartPeriod;

    public function __construct() {
        $this->logDir          = __DIR__ . '/log_db_monitor';
        $this->logFile         = "$this->logDir/db-monitor-log.txt";
        $this->restartLogFile  = "$this->logDir/restart-history.log";
        $this->lastRestartFile = "$this->logDir/last_restart.txt";
        $this->lastErrorFile   = "$this->logDir/last_error.json";

        $this->maxRetries       = getenv('DB_MAX_RETRIES')  ? (int)getenv('DB_MAX_RETRIES')  : 3;
        $this->retryDelay       = getenv('DB_RETRY_DELAY')  ? (int)getenv('DB_RETRY_DELAY')  : 5;
        $this->autoRestart      = getenv('AUTO_RESTART')    ? filter_var(getenv('AUTO_RESTART'), FILTER_VALIDATE_BOOLEAN) : true;
        $this->debug            = getenv('DEBUG_MODE')      ? filter_var(getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN) : false;
        $this->normalNotification = getenv('NORMAL_NOTIFICATION') ? filter_var(getenv('NORMAL_NOTIFICATION'), FILTER_VALIDATE_BOOLEAN) : false;

        $this->telegramToken    = getenv('TELEGRAM_TOKEN')  ?: '7943049250:AAHdnBwodrSmLqsETpZOgN89xERkpX-1Pkk';
        $this->telegramChatId   = getenv('TELEGRAM_CHAT_ID') ?: '401856988';

        $this->wpConfigPath     = __DIR__ . '/wp-config.php';

        $this->diskThreshold    = getenv('DISK_THRESHOLD')    ? (int)getenv('DISK_THRESHOLD')    : 90;
        $this->connPoolThreshold= getenv('CONN_POOL_THRESHOLD')? (int)getenv('CONN_POOL_THRESHOLD'): 80;
        // hanya cek cron, php-fpm dihapus
        $this->dependenciesToCheck = explode(',', getenv('DEPENDENCIES') ?: 'cron');
        $this->mysqlConfigPath  = getenv('MYSQL_CONFIG')      ?: '/etc/mysql/my.cnf';
        $this->hashFile         = "$this->logDir/config_hashes.json";
        $this->maxRestarts      = 3;
        $this->restartPeriod    = 600; // detik

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}

/**
 * Logger dengan file locking
 */
class Logger {
    private $file;
    public function __construct($file) { $this->file = $file; }
    public function log($msg) {
        $fp = fopen($this->file, 'a');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, "[".date('Y-m-d H:i:s')."] $msg\n");
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        if ($fp) fclose($fp);
    }
}

/**
 * Notifier Telegram via cURL
 */
class TelegramNotifier {
    private $token, $chatId, $logger;
    public function __construct($t,$c,Logger $l){$this->token=$t;$this->chatId=$c;$this->logger=$l;}
    public function send($text){
        if(!$this->token||!$this->chatId){
            $this->logger->log("Telegram token/chat kosong");return false;
        }
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => http_build_query(['chat_id'=>$this->chatId,'text'=>$text,'parse_mode'=>'HTML']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT     => 5,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if(!$res) $this->logger->log("Telegram gagal: $err");
        return (bool)$res;
    }
}

/**
 * Parser wp-config.php
 */
class WPConfigParser {
    private $file;
    public function __construct($f){
        if(!file_exists($f)) throw new Exception("wp-config.php tidak ditemukan");
        $this->file=$f;
    }
    public function getConfigValue($key){
        $d = file_get_contents($this->file);
        if(preg_match("/define\\s*\\(\\s*['\"]".preg_quote($key,'/')."['\"]\\s*,\\s*['\"](.+?)['\"]\\s*\\)/",$d,$m))
            return $m[1];
        return null;
    }
    public function getSiteDomain(){
        $h = $this->getConfigValue('WP_HOME')?:$this->getConfigValue('WP_SITEURL');
        if($h && ($p=parse_url($h)) && !empty($p['host'])) return $p['host'];
        // fallback ke hostname server
        return gethostname()?:'localhost';
    }
}

/**
 * Lock untuk single instance
 */
class ProcessLock {
    private $file,$h;
    public function __construct($f){$this->file=$f;}
    public function acquire(){
        $this->h=fopen($this->file,'c');
        if(!$this->h||!flock($this->h,LOCK_EX|LOCK_NB)) throw new Exception("Skrip sedang berjalan");
        ftruncate($this->h,0);
        fwrite($this->h,getmypid());
    }
    public function release(){
        if($this->h){ flock($this->h,LOCK_UN); fclose($this->h); }
    }
}

/**
 * Core monitoring & notifikasi
 */
class DatabaseMonitor {
    private $cfg,$log,$notifier,$wp;
    private $dbHost,$dbPort,$dbUser,$dbPass,$dbName,$site,$ip;

    public function __construct($cfg,$log,$notifier,$wp){
        $this->cfg=$cfg; $this->log=$log; $this->notifier=$notifier; $this->wp=$wp;
    }

    private function writeErrorJson($d){ file_put_contents($this->cfg->lastErrorFile,json_encode($d,JSON_PRETTY_PRINT)); }
    private function restartMySQL(){
        $out=shell_exec("sudo systemctl restart mysql 2>&1");
        $this->log->log("Restart: ".trim($out));
        file_put_contents($this->cfg->restartLogFile,"[".date('Y-m-d H:i:s')."] $out\n",FILE_APPEND);
        return trim($out)?:'Restart dikirim.';
    }
    private function checkRestartAttempts(){
        if(!file_exists($this->cfg->restartLogFile)) return false;
        $lines=file($this->cfg->restartLogFile,FILE_IGNORE_NEW_LINES);
        $cnt=0; $now=time();
        foreach($lines as $l){
            if(preg_match('/\[([^\]]+)\]/',$l,$m) && ($now-strtotime($m[1])<$this->cfg->restartPeriod)) $cnt++;
        }
        if($cnt>=$this->cfg->maxRestarts){
            $this->notifier->send("🚨 <b>{$this->site}</b> Gagal restart MySQL {$cnt}x dalam {$this->cfg->restartPeriod} detik!");
            return true;
        }
        return false;
    }
    private function handleError($type,$msg,$ctx){
        $data=['site'=>$this->site,'ip'=>$this->ip,'time'=>date('Y-m-d H:i:s'),'error'=>$msg,'type'=>$type];
        $this->writeErrorJson($data);
        $this->log->log("ERROR($type): $msg");
        $text="? <b>{$this->site}</b> GAGAL konek ".($type=='fsockopen'?'MySQL':'DB')." {$ctx} <pre>{$data['time']}\nServer: {$this->ip}\nError: $msg</pre>";
        $this->notifier->send($text);
        if($this->cfg->autoRestart){
            if(!$this->checkRestartAttempts()){
                $out=$this->restartMySQL();
                touch($this->cfg->lastRestartFile);
                $this->notifier->send("🔄 <b>{$this->site}</b> MySQL di‑restart.\n<pre>$out</pre>");
            }
        }
    }

    public function testFsockopen($h,$p){
        $t0=microtime(true);
        $fp=@fsockopen($h,$p,$e,$s,3);
        $dt=round((microtime(true)-$t0)*1000);
        if(!$fp){
            $this->handleError('fsockopen',$s,"($h:$p) {$dt}ms");
            throw new Exception("FSOCKOPEN gagal: $s");
        }
        fclose($fp);
        return $dt;
    }

    private function exponentialBackoff($a){ return $this->cfg->retryDelay * pow(2,$a-1); }

    public function testMysqli(){
        $last=''; $a=0;
        while($a<$this->cfg->maxRetries){
            $a++;
            try{
                $c=new mysqli($this->dbHost,$this->dbUser,$this->dbPass,$this->dbName,$this->dbPort);
                if($c->connect_errno) throw new Exception($c->connect_error);
                $c->close();
                $this->log->log("✔ <b>{$this->site}</b> Koneksi MySQL normal. Percobaan: $a");
                return $a;
            }catch(Exception $e){
                $last=$e->getMessage();
                $this->log->log("Percobaan $a: $last");
                if($a<$this->cfg->maxRetries) sleep($this->exponentialBackoff($a));
            }
        }
        $this->handleError('mysqli',$last,"setelah {$a} percobaan");
        throw new Exception("MySQL gagal konek setelah {$a} percobaan.");
    }

    private function notifyRecovery(){
        if(!file_exists($this->cfg->lastRestartFile)) return;
        if(time()-filemtime($this->cfg->lastRestartFile)<=300){
            $this->notifier->send("✅ <b>{$this->site}</b> MySQL pulih pasca restart");
            unlink($this->cfg->lastRestartFile);
        }
    }

    private function checkDiskUsage(){
        $out=shell_exec("df -h /var/lib/mysql | tail -1");
        if(preg_match('/\s(\d+)%\s/',$out,$m)){
            $u=(int)$m[1];
            if($u>$this->cfg->diskThreshold){
                $msg="⚠️ <b>{$this->site}</b> Disk /var/lib/mysql {$u}%";
                $this->notifier->send($msg);
                $this->log->log("Disk: {$u}%");
            }
        }
    }

    private function checkConnectionPool(){
        try{
            $c=new mysqli($this->dbHost,$this->dbUser,$this->dbPass,$this->dbName,$this->dbPort);
            $v=$c->query("SHOW VARIABLES LIKE 'max_connections'")->fetch_assoc();
            $s=$c->query("SHOW STATUS LIKE 'Threads_connected'")->fetch_assoc();
            $c->close();
            $pct=round($s['Value']/$v['Value']*100,1);
            if($pct> $this->cfg->connPoolThreshold){
                $msg="🔄 <b>{$this->site}</b> ConnPool {$s['Value']}/{$v['Value']} ({$pct}%)";
                $this->notifier->send($msg);
                $this->log->log("ConnPool: {$pct}%");
            }
        }catch(Exception $e){}
    }

    private function checkConfigIntegrity(){
        $files=[$this->cfg->wpConfigPath,$this->cfg->mysqlConfigPath];
        $hsh=file_exists($this->cfg->hashFile)?json_decode(file_get_contents($this->cfg->hashFile),true):[];
        foreach($files as $f){
            if(!file_exists($f))continue;
            $cur=md5_file($f);
            $name=basename($f);
            if(isset($hsh[$name]) && $hsh[$name]!==$cur){
                $msg="🔧 <b>{$this->site}</b> Config {$name} berubah";
                $this->notifier->send($msg);
                $this->log->log("ConfigIntegrity: $name berubah");
            }
            $hsh[$name]=$cur;
        }
        file_put_contents($this->cfg->hashFile,json_encode($hsh));
    }

    private function checkDependencies(){
        foreach($this->cfg->dependenciesToCheck as $srv){
            $st=trim(shell_exec("systemctl is-active $srv 2>/dev/null"));
            if($st!=='active'){
                $msg="⚙️ <b>{$this->site}</b> Layanan $srv tidak aktif";
                $this->notifier->send($msg);
                $this->log->log("DepCheck: $srv $st");
            }
        }
    }

    public function run(){
        // baca DB config
        $hostRaw=$this->wp->getConfigValue('DB_HOST')?:'localhost';
        if(strpos($hostRaw,':')!==false){
            list($this->dbHost,$p)=explode(':',$hostRaw,2);
            $this->dbPort=(int)$p;
        }else{
            $this->dbHost=$hostRaw;
            $this->dbPort=3306;
        }
        $this->dbUser=$this->wp->getConfigValue('DB_USER')?:'user';
        $this->dbPass=$this->wp->getConfigValue('DB_PASSWORD')?:'pass';
        $this->dbName=$this->wp->getConfigValue('DB_NAME')?:'db';

        $this->site = $this->wp->getSiteDomain();
        $this->ip   = gethostbyname(gethostname());

        // auto–update
        autoUpdateScript($this->log,$this->notifier,LOCAL_VERSION);

        // cek koneksi
        $this->testFsockopen($this->dbHost,$this->dbPort);
        try {
            $a = $this->testMysqli();
            $msg = "? <b>{$this->site}</b> Koneksi normal. Percobaan: $a";
            echo "$msg\n";
            if($this->cfg->normalNotification) {
                $this->notifier->send($msg);
            }
        } catch(Exception $e) {
            echo $e->getMessage()."\n";
        }

        // fitur tambahan
        $this->notifyRecovery();
        $this->checkDiskUsage();
        $this->checkConnectionPool();
        $this->checkConfigIntegrity();
        $this->checkDependencies();

        if($this->cfg->debug){
            echo $this->getSystemInfo()."\n";
        }
    }

    public function getSystemInfo(){
        $l = sys_getloadavg()[0];
        $m = stripos(PHP_OS,'win')===false?shell_exec('free -m'):'Mem info N/A';
        return "Load: $l | Mem:\n$m";
    }
}

/* === EKSEKUSI UTAMA === */
try {
    $lock = new ProcessLock('/tmp/db_monitor.lock');
    $lock->acquire();
    register_shutdown_function(function() use($lock){ $lock->release(); });

    $cfg   = new Config();
    $log   = new Logger($cfg->logFile);
    $note  = new TelegramNotifier($cfg->telegramToken, $cfg->telegramChatId, $log);
    $wp    = new WPConfigParser($cfg->wpConfigPath);

    $mon   = new DatabaseMonitor($cfg,$log,$note,$wp);
    $mon->run();

} catch(Exception $ex) {
    error_log($ex->getMessage());
    echo $ex->getMessage()."\n";
    exit(1);
}
