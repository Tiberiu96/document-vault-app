<?php

return [
    'restore_enabled' => (bool) env('BACKUP_RESTORE_ENABLED', false),
    'restore_dedicated_redis' => (bool) env('BACKUP_RESTORE_DEDICATED_REDIS', false),
    'mysql' => env('BACKUP_MYSQL_BINARY', 'mysql'),
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'single_host' => (bool) env('BACKUP_SINGLE_HOST_CONFIRMED', false),
    'account_id' => env('BACKUP_R2_ACCOUNT_ID', ''),
    'jurisdiction' => env('BACKUP_R2_JURISDICTION', 'default'),
    'bucket' => env('BACKUP_R2_BUCKET', ''),
    'access_key' => env('BACKUP_R2_ACCESS_KEY_ID', ''),
    'secret_key' => env('BACKUP_R2_SECRET_ACCESS_KEY', ''),
    'password' => env('BACKUP_RESTIC_PASSWORD', ''),
    'restic' => env('BACKUP_RESTIC_BINARY', 'restic'),
    'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
    'upload_kib' => (int) env('BACKUP_UPLOAD_KIB', 2048),
    'warning_seconds' => 900,
    'drain_seconds' => 360,
    'snapshot_seconds' => 120,
    'process_seconds' => (int) env('BACKUP_PROCESS_TIMEOUT', 3600),
    'alert_email' => env('BACKUP_ALERT_EMAIL', ''),
    'directory' => storage_path('app/backup-control'),
];
