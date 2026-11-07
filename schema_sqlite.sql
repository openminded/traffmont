-- TraffMont SQLite Schema
-- Designed for zero-config local testing

CREATE TABLE IF NOT EXISTS `users` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `username` TEXT NOT NULL UNIQUE,
  `password_hash` TEXT NOT NULL,
  `email` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `routers` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` TEXT NOT NULL,
  `host` TEXT NOT NULL,
  `api_type` TEXT DEFAULT 'routeros_api',
  `api_port` INTEGER DEFAULT 8728,
  `username` TEXT DEFAULT 'admin',
  `password` TEXT DEFAULT '',
  `use_ssl` INTEGER DEFAULT 0,
  `timeout_sec` INTEGER DEFAULT 4,
  `target_sla_percent` REAL DEFAULT 99.50,
  `push_token` TEXT DEFAULT NULL,
  `is_active` INTEGER DEFAULT 1,
  `current_status` TEXT DEFAULT 'UNKNOWN',
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_status_change` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `interfaces` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `router_id` INTEGER NOT NULL,
  `interface_name` TEXT NOT NULL,
  `alias_name` TEXT DEFAULT NULL,
  `target_sla_percent` REAL DEFAULT NULL,
  `is_active` INTEGER DEFAULT 1,
  `current_status` TEXT DEFAULT 'UNKNOWN',
  `last_rx_bytes` INTEGER DEFAULT 0,
  `last_tx_bytes` INTEGER DEFAULT 0,
  `last_rx_bps` INTEGER DEFAULT 0,
  `last_tx_bps` INTEGER DEFAULT 0,
  `last_latency_ms` INTEGER DEFAULT 0,
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_status_change` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  UNIQUE(`router_id`, `interface_name`)
);

CREATE TABLE IF NOT EXISTS `monitoring_logs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `router_id` INTEGER NOT NULL,
  `interface_id` INTEGER NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` TEXT NOT NULL,
  `latency_ms` INTEGER DEFAULT 0,
  `rx_bps` INTEGER DEFAULT 0,
  `tx_bps` INTEGER DEFAULT 0,
  `raw_rx_bytes` INTEGER DEFAULT 0,
  `raw_tx_bytes` INTEGER DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS `idx_log_query` ON `monitoring_logs` (`interface_id`, `checked_at`);
CREATE INDEX IF NOT EXISTS `idx_log_router` ON `monitoring_logs` (`router_id`, `checked_at`);
CREATE INDEX IF NOT EXISTS `idx_checked_at` ON `monitoring_logs` (`checked_at`);

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `router_id` INTEGER NOT NULL,
  `interface_id` INTEGER NOT NULL,
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `duration_seconds` INTEGER DEFAULT 0,
  `status` TEXT DEFAULT 'OPEN',
  `root_cause` TEXT DEFAULT 'Connection Timeout / Interface Down',
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `daily_aggregates` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `router_id` INTEGER NOT NULL,
  `interface_id` INTEGER NOT NULL,
  `date` DATE NOT NULL,
  `total_checks` INTEGER DEFAULT 0,
  `up_checks` INTEGER DEFAULT 0,
  `down_checks` INTEGER DEFAULT 0,
  `uptime_percent` REAL DEFAULT 100.00,
  `avg_latency_ms` INTEGER DEFAULT 0,
  `max_rx_bps` INTEGER DEFAULT 0,
  `max_tx_bps` INTEGER DEFAULT 0,
  `avg_rx_bps` INTEGER DEFAULT 0,
  `avg_tx_bps` INTEGER DEFAULT 0,
  `total_downtime_minutes` INTEGER DEFAULT 0,
  `incident_count` INTEGER DEFAULT 0,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE,
  UNIQUE(`interface_id`, `date`)
);

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` TEXT PRIMARY KEY,
  `key_value` TEXT DEFAULT NULL
);

INSERT OR IGNORE INTO `settings` (`key_name`, `key_value`) VALUES
('app_name', 'TraffMont - MikroTik Traffic & SLA Monitor'),
('cron_token', 'traffmont_secret_key_change_me'),
('timezone', 'Asia/Jakarta'),
('retention_days', '60'),
('alert_email', '');

