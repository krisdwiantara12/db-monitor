#!/usr/bin/env bash
# db_monitor_env.sh
# File ini berisi pengaturan environment variables untuk skrip db_monitor_external.php (v2.1.1+)

# === BAGIAN UTAMA ===
# Nama direktori log, relatif terhadap direktori skrip PHP
export ENV_LOG_DIR_NAME="log_db_monitor"
# Path absolut untuk lock file
export ENV_LOCK_FILE_PATH="/tmp/db_monitor_external.lock"
# Path ke wp-config.php (kosongkan jika tidak pakai WordPress: export ENV_WP_CONFIG_PATH="")
export ENV_WP_CONFIG_PATH="/var/www/html/wp-config.php"
# Versi PHP minimal yang dibutuhkan
export ENV_MIN_PHP_VERSION="7.2.0"
# Aktifkan mode debug untuk menampilkan informasi tambahan (true|false)
export ENV_DEBUG_MODE="false"
# Kirim notifikasi jika semua pengecekan berhasil (true|false)
export ENV_NORMAL_NOTIFICATION="false"


# === KONFIGURASI TELEGRAM ===
# OPSI 1: Langsung set Token dan Chat ID di sini (REKOMENDASI BARU)
# export ENV_TELEGRAM_TOKEN="BOT_TOKEN_ANDA"
# export ENV_TELEGRAM_CHAT_ID="CHAT_ID_ANDA"
# OPSI 2: Jika Anda ingin tetap menggunakan telegram_config.json
# Biarkan ENV_TELEGRAM_TOKEN dan ENV_TELEGRAM_CHAT_ID di atas tidak diset (dikomentari atau kosong).
# Lalu set path ke file telegram_config.json Anda di sini:
export ENV_TELEGRAM_CONFIG_JSON_PATH="telegram_config.json" # Path relatif terhadap skrip PHP atau path absolut


# === BAGIAN MONITORING UMUM ===
# Maksimal percobaan koneksi MySQL sebelum error
export ENV_DB_MAX_RETRIES=3
# Delay dasar (detik) antara retry
export ENV_DB_RETRY_DELAY=5
# Opsi auto restart MySQL saat gagal koneksi (true|false)
export ENV_MYSQL_AUTO_RESTART="true"
# Threshold penggunaan disk (dalam persen)
export ENV_DISK_THRESHOLD_PERCENT=90
# Threshold load average CPU (1 menit)
export ENV_CPU_THRESHOLD_LOAD_AVG=1.0
# Threshold penggunaan memory (dalam persen)
export ENV_MEM_THRESHOLD_PERCENT=90


# === BAGIAN KEAMANAN ===
# Threshold jumlah gagal login SSH
export ENV_LOGIN_FAIL_THRESHOLD=5
# Aktifkan auto blokir IP via Fail2Ban (true|false)
export ENV_AUTO_BLOCK_IP="true"
# Nama jail Fail2Ban yang digunakan untuk memblokir IP SSH
export ENV_FAIL2BAN_JAIL_NAME="sshd"
# Path ke fail2ban-client (kosongkan untuk menggunakan dari $PATH sistem)
export ENV_FAIL2BAN_CLIENT_PATH="/usr/bin/fail2ban-client"
# Path ke journalctl (kosongkan untuk menggunakan dari $PATH sistem)
export ENV_JOURNALCTL_PATH="/bin/journalctl"
# Fallback jika journalctl tidak tersedia/gagal (log otentikasi standar)
export ENV_AUTH_LOG_PATH="/var/log/auth.log"
# Nama file untuk menyimpan timestamp pengecekan keamanan terakhir (di dalam direktori log)
export ENV_LAST_SECURITY_CHECK_FILE_NAME="last_security_check.txt"


# === BAGIAN MYSQL LANJUTAN ===
# Aktifkan pengecekan performa MySQL (true|false)
export ENV_ENABLE_MYSQL_PERFORMANCE_CHECK="true"
# Threshold Threads_running MySQL
export ENV_MYSQL_THREADS_RUNNING_THRESHOLD=80
# Path ke slow query log MySQL (kosongkan jika tidak ingin dicek)
export ENV_MYSQL_SLOW_QUERY_LOG_PATH="/var/log/mysql/mysql-slow.log"
# Cek modifikasi slow query log dalam X menit terakhir
export ENV_MYSQL_CHECK_SLOW_QUERY_MINUTES=60
# Path ke mysqldump (kosongkan untuk menggunakan dari $PATH sistem).
# Meskipun backup otomatis sebelum restart dihilangkan, mysqldump mungkin masih berguna untuk fitur lain di masa depan
# atau jika Anda ingin memicu backup manual dengan command terpisah yang menggunakan path ini.
# Jika tidak ada rencana penggunaan lain, Anda bisa juga menghapus variabel ini.
export ENV_MYSQLDUMP_PATH="/usr/bin/mysqldump"
# Path ke file konfigurasi my.cnf. Skrip PHP tidak memakai ini secara langsung,
# tapi ini pengingat yang baik untuk konfigurasi mysqldump client jika Anda menggunakannya manual.
export ENV_MYSQL_CONFIG_FILE_NOTE="/etc/mysql/my.cnf"


# === BAGIAN DISK LANJUTAN ===
# Aktifkan pengecekan SMART disk (true|false)
export ENV_ENABLE_SMART_CHECK="true"
# Path ke smartctl (kosongkan untuk menggunakan dari $PATH sistem)
export ENV_SMARTCTL_PATH="/usr/sbin/smartctl"
# Daftar disk yang dicek SMART, pisahkan dengan koma (contoh: "/dev/sda,/dev/sdb")
export ENV_DISK_DEVICES_TO_CHECK="/dev/sda"


# === BAGIAN WORDPRESS LANJUTAN (Jika menggunakan WordPress) ===
# Aktifkan pengecekan WordPress debug.log (true|false)
export ENV_ENABLE_WP_DEBUG_LOG_CHECK="true"
# Path ke debug.log WordPress (kosongkan jika tidak ingin dicek atau path default tidak cocok)
# Jika kosong dan ENV_WP_CONFIG_PATH diset, skrip akan coba menebak path (wp-content/debug.log)
export ENV_WP_DEBUG_LOG_PATH=""
# Cek modifikasi debug.log dalam X menit terakhir
export ENV_WP_CHECK_DEBUG_LOG_MINUTES=60


# === BAGIAN AUTO UPDATE SCRIPT ===
# Repositori GitHub untuk auto-update
export ENV_GITHUB_REPO="krisdwiantara12/db-monitor" # Sesuaikan jika Anda fork
# Cabang (branch) GitHub
export ENV_GITHUB_BRANCH="main"

# Pastikan skrip ini di-source sebelum menjalankan db_monitor_external.php
# Contoh di crontab:
# * * * * * source /path/to/db_monitor_env.sh && /usr/bin/php /path/to/db_monitor_external.php
echo "Environment variables for db_monitor_external.php (v2.1.1+) loaded."
