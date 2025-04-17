#!/usr/bin/env bash
# db_monitor_env.sh
# File ini berisi pengaturan environment variables untuk skrip db_monitor_external.php

# Maksimal percobaan koneksi MySQL sebelum error
export DB_MAX_RETRIES=3

# Delay dasar (detik) antara retry, akan dipangkatkan secara eksponensial
export DB_RETRY_DELAY=5

# Opsi auto restart MySQL saat gagal koneksi (true|false)
export AUTO_RESTART=true

# Aktifkan mode debug untuk menampilkan informasi tambahan (true|false)
export DEBUG_MODE=false

# Kirim notifikasi normal (setiap cek koneksi berhasil) (true|false)
export NORMAL_NOTIFICATION=false

# Threshold penggunaan disk pada /var/lib/mysql (dalam persen)
export DISK_THRESHOLD=90

# Threshold penggunaan connection pool MySQL (dalam persen)
export CONN_POOL_THRESHOLD=80

# Threshold load average CPU (1 menit)
export CPU_THRESHOLD=1.0

# Threshold penggunaan memory (dalam persen)
export MEM_THRESHOLD=90

# Threshold jumlah gagal login SSH dalam 5 menit
export LOGIN_FAIL_THRESHOLD=5

# Layanan dependency tambahan, pisahkan dengan koma (default: cron)
export DEPENDENCIES=cron

# Path ke file konfigurasi MySQL jika berbeda
export MYSQL_CONFIG=/etc/mysql/my.cnf

# Jalankan skrip ini terlebih dahulu sebelum menjalankan db_monitor_external.php
# Contoh di crontab:
# * * * * * source /path/to/db_monitor_env.sh && /usr/bin/php /path/to/db_monitor_external.php
