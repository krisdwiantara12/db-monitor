#!/usr/bin/env bash
# db_monitor_env.sh
# File konfigurasi untuk db_monitor_external.php v3.0.0+

# === BAGIAN UTAMA ===
# Nama direktori log, relatif terhadap direktori skrip PHP
export ENV_LOG_DIR_NAME="log_db_monitor"
# Path absolut untuk lock file
export ENV_LOCK_FILE_PATH="/tmp/db_monitor_external.lock"
# Path ke wp-config.php (kosongkan jika tidak pakai WordPress: export ENV_WP_CONFIG_PATH="")
export ENV_WP_CONFIG_PATH="/var/www/html/wp-config.php"
# Versi PHP minimal yang dibutuhkan
export ENV_MIN_PHP_VERSION="7.4.0"
# Aktifkan mode debug untuk menampilkan informasi tambahan (true|false)
export ENV_DEBUG_MODE="false"
# Kirim notifikasi jika semua pengecekan berhasil (true|false)
export ENV_NORMAL_NOTIFICATION="false"


# === KONFIGURASI TELEGRAM ===
# OPSI 1: Langsung set Token dan Chat ID di sini (REKOMENDASI)
# export ENV_TELEGRAM_TOKEN="7943049250:AAHdnBwodrSmLqsETpZOgN89xERkpX-1Pkk"
# export ENV_TELEGRAM_CHAT_ID="401856988"
# OPSI 2: Gunakan telegram_config.json
export ENV_TELEGRAM_CONFIG_JSON_PATH="telegram_config.json"


# === BAGIAN MONITORING UMUM ===
# Maksimal percobaan koneksi MySQL sebelum error
export ENV_DB_MAX_RETRIES=3
# Delay dasar (detik) antara retry
export ENV_DB_RETRY_DELAY=5
# Opsi auto restart MySQL saat gagal koneksi (true|false)
export ENV_MYSQL_AUTO_RESTART="true"
# Threshold penggunaan disk (dalam persen)
export ENV_DISK_THRESHOLD_PERCENT=90
# Path tambahan untuk dicek penggunaan disknya, pisahkan dengan koma
export ENV_EXTRA_PATHS_TO_CHECK="/home,/var/log"
# Threshold load average CPU (1 menit)
export ENV_CPU_THRESHOLD_LOAD_AVG=1.5
# Threshold penggunaan memory (dalam persen)
export ENV_MEM_THRESHOLD_PERCENT=90


# === BAGIAN KEAMANAN ===
# Threshold jumlah gagal login SSH sebelum notifikasi muncul
export ENV_LOGIN_FAIL_THRESHOLD=10
# Aktifkan pengecekan tambahan via command 'lastb' (memerlukan sudo)
export ENV_ENABLE_LASTB_CHECK="true"
# Aktifkan auto blokir IP via Fail2Ban (memerlukan sudo)
export ENV_AUTO_BLOCK_IP="true"
# Nama jail Fail2Ban yang digunakan
export ENV_FAIL2BAN_JAIL_NAME="sshd"
# Path ke fail2ban-client (kosongkan untuk auto-detect dari $PATH)
export ENV_FAIL2BAN_CLIENT_PATH=""
# Path ke journalctl (kosongkan untuk auto-detect dari $PATH)
export ENV_JOURNALCTL_PATH=""
# Fallback jika journalctl tidak tersedia (log otentikasi standar)
export ENV_AUTH_LOG_PATH="/var/log/auth.log"


# === BAGIAN MYSQL LANJUTAN ===
# Aktifkan pengecekan performa MySQL (true|false)
export ENV_ENABLE_MYSQL_PERFORMANCE_CHECK="true"
# Threshold Threads_running MySQL
export ENV_MYSQL_THREADS_RUNNING_THRESHOLD=80
# Threshold untuk Aborted_connects (jumlah koneksi gagal karena masalah jaringan/kredensial)
export ENV_MYSQL_ABORTED_CONNECTS_THRESHOLD=10
# Path ke slow query log MySQL (kosongkan jika tidak ingin dicek)
export ENV_MYSQL_SLOW_QUERY_LOG_PATH="/var/log/mysql/mysql-slow.log"
# Cek modifikasi slow query log dalam X menit terakhir
export ENV_MYSQL_CHECK_SLOW_QUERY_MINUTES=60


# === BAGIAN DISK LANJUTAN ===
# Aktifkan pengecekan SMART disk (true|false).
# REKOMENDASI: 'false' untuk Cloud/VPS (seperti Vultr), 'true' untuk server fisik (bare metal).
export ENV_ENABLE_SMART_CHECK="false"
# Path ke smartctl (kosongkan untuk auto-detect dari $PATH)
export ENV_SMARTCTL_PATH=""
# Daftar disk yang dicek SMART, pisahkan dengan koma (contoh: "/dev/sda,/dev/sdb")
export ENV_DISK_DEVICES_TO_CHECK="/dev/sda"


# === BAGIAN WORDPRESS LANJUTAN ===
# Aktifkan pengecekan WordPress debug.log (true|false)
export ENV_ENABLE_WP_DEBUG_LOG_CHECK="true"
# Path ke debug.log WordPress (kosongkan untuk auto-detect dari path wp-config)
export ENV_WP_DEBUG_LOG_PATH=""
# Cek modifikasi debug.log dalam X menit terakhir
export ENV_WP_CHECK_DEBUG_LOG_MINUTES=60


# === BAGIAN AUTO UPDATE SCRIPT ===
# Repositori GitHub untuk auto-update
export ENV_GITHUB_REPO="krisdwiantara12/db-monitor"
export ENV_GITHUB_BRANCH="main"

# Pastikan skrip ini di-source sebelum menjalankan db_monitor_external.php
# Contoh di crontab:
# * * * * * source /path/to/db_monitor_env.sh && /usr/bin/php /path/to/db_monitor_external.php
