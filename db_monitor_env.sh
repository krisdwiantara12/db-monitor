#!/usr/bin/env bash
# db_monitor_env.sh
# File ini berisi pengaturan environment variables untuk skrip db_monitor_external.php

# === BAGIAN UTAMA ===
export ENV_LOG_DIR_NAME="log_db_monitor"
export ENV_LOCK_FILE_PATH="/tmp/db_monitor_external.lock"
export ENV_WP_CONFIG_PATH="/var/www/html/wp-config.php"
export ENV_MIN_PHP_VERSION="7.4.0"
export ENV_DEBUG_MODE="false"
export ENV_NORMAL_NOTIFICATION="false"


# === KONFIGURASI TELEGRAM ===
# OPSI 1: Langsung set Token dan Chat ID di sini (REKOMENDASI)
# export ENV_TELEGRAM_TOKEN="BOT_TOKEN_ANDA"
# export ENV_TELEGRAM_CHAT_ID="CHAT_ID_ANDA"
# OPSI 2: Gunakan telegram_config.json
export ENV_TELEGRAM_CONFIG_JSON_PATH="telegram_config.json"


# === BAGIAN MONITORING UMUM ===
export ENV_DB_MAX_RETRIES=3
export ENV_DB_RETRY_DELAY=5
export ENV_MYSQL_AUTO_RESTART="true"
export ENV_DISK_THRESHOLD_PERCENT=90
export ENV_EXTRA_PATHS_TO_CHECK="/home,/var/log"
export ENV_CPU_THRESHOLD_LOAD_AVG=1.5
export ENV_MEM_THRESHOLD_PERCENT=90


# === BAGIAN KEAMANAN ===
export ENV_LOGIN_FAIL_THRESHOLD=5
export ENV_ENABLE_LASTB_CHECK="true"
export ENV_AUTO_BLOCK_IP="true"
export ENV_FAIL2BAN_JAIL_NAME="sshd"
export ENV_FAIL2BAN_CLIENT_PATH=""
export ENV_JOURNALCTL_PATH=""
export ENV_AUTH_LOG_PATH="/var/log/auth.log"
export ENV_LAST_SECURITY_CHECK_FILE_NAME="last_security_check.txt"


# === BAGIAN MYSQL LANJUTAN ===
export ENV_ENABLE_MYSQL_PERFORMANCE_CHECK="true"
export ENV_MYSQL_THREADS_RUNNING_THRESHOLD=80
export ENV_MYSQL_ABORTED_CONNECTS_THRESHOLD=10
export ENV_MYSQL_SLOW_QUERY_LOG_PATH="/var/log/mysql/mysql-slow.log"
export ENV_MYSQL_CHECK_SLOW_QUERY_MINUTES=60
# Konfigurasi backup otomatis sebelum restart telah DIHILANGKAN


# === BAGIAN DISK LANJUTAN ===
export ENV_ENABLE_SMART_CHECK="false"
export ENV_SMARTCTL_PATH=""
export ENV_DISK_DEVICES_TO_CHECK="/dev/sda"


# === BAGIAN WORDPRESS LANJUTAN ===
export ENV_ENABLE_WP_DEBUG_LOG_CHECK="true"
export ENV_WP_DEBUG_LOG_PATH=""
export ENV_WP_CHECK_DEBUG_LOG_MINUTES=60


# === BAGIAN AUTO UPDATE SCRIPT ===
export ENV_GITHUB_REPO="krisdwiantara12/db-monitor"
export ENV_GITHUB_BRANCH="main"

echo "Environment variables (v2.2.1) for db_monitor_external.php loaded."
