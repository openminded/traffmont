-- TraffMont Database Schema
-- Optimized for cPanel MySQL / MariaDB (No Terminal required)

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `routers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `host` VARCHAR(255) NOT NULL,
  `api_type` ENUM('routeros_api', 'rest_api', 'push_agent') DEFAULT 'routeros_api',
  `api_port` INT DEFAULT 8728,
  `username` VARCHAR(100) DEFAULT 'admin',
  `password` VARCHAR(255) DEFAULT '',
  `use_ssl` TINYINT(1) DEFAULT 0,
  `timeout_sec` INT DEFAULT 4,
  `target_sla_percent` DECIMAL(5,2) DEFAULT 99.50,
  `push_token` VARCHAR(64) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `current_status` ENUM('UP', 'DOWN', 'TIMEOUT', 'UNKNOWN') DEFAULT 'UNKNOWN',
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_status_change` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_routers_status` (`current_status`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `interfaces` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `router_id` INT NOT NULL,
  `interface_name` VARCHAR(100) NOT NULL,
  `alias_name` VARCHAR(100) DEFAULT NULL,
  `target_sla_percent` DECIMAL(5,2) DEFAULT NULL, -- NULL means inherit router's SLA
  `is_active` TINYINT(1) DEFAULT 1,
  `current_status` ENUM('UP', 'DOWN', 'TIMEOUT', 'UNKNOWN') DEFAULT 'UNKNOWN',
  `last_rx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `last_tx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `last_rx_bps` BIGINT UNSIGNED DEFAULT 0,
  `last_tx_bps` BIGINT UNSIGNED DEFAULT 0,
  `last_latency_ms` INT DEFAULT 0,
  `last_checked_at` DATETIME DEFAULT NULL,
  `last_status_change` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_router_interface` (`router_id`, `interface_name`),
  INDEX `idx_interface_status` (`current_status`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `monitoring_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `router_id` INT NOT NULL,
  `interface_id` INT NOT NULL,
  `checked_at` DATETIME NOT NULL,
  `status` ENUM('UP', 'DOWN', 'TIMEOUT') NOT NULL,
  `latency_ms` INT DEFAULT 0,
  `rx_bps` BIGINT UNSIGNED DEFAULT 0,
  `tx_bps` BIGINT UNSIGNED DEFAULT 0,
  `raw_rx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `raw_tx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `error_message` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE,
  INDEX `idx_log_query` (`interface_id`, `checked_at`),
  INDEX `idx_log_router` (`router_id`, `checked_at`),
  INDEX `idx_checked_at` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `router_id` INT NOT NULL,
  `interface_id` INT NOT NULL,
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `duration_seconds` INT DEFAULT 0,
  `status` ENUM('OPEN', 'RESOLVED') DEFAULT 'OPEN',
  `root_cause` VARCHAR(255) DEFAULT 'Connection Timeout / Interface Down',
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE,
  INDEX `idx_incidents_active` (`interface_id`, `status`),
  INDEX `idx_incidents_time` (`started_at`, `ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `daily_aggregates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `router_id` INT NOT NULL,
  `interface_id` INT NOT NULL,
  `date` DATE NOT NULL,
  `total_checks` INT DEFAULT 0,
  `up_checks` INT DEFAULT 0,
  `down_checks` INT DEFAULT 0,
  `uptime_percent` DECIMAL(5,2) DEFAULT 100.00,
  `avg_latency_ms` INT DEFAULT 0,
  `max_rx_bps` BIGINT UNSIGNED DEFAULT 0,
  `max_tx_bps` BIGINT UNSIGNED DEFAULT 0,
  `avg_rx_bps` BIGINT UNSIGNED DEFAULT 0,
  `avg_tx_bps` BIGINT UNSIGNED DEFAULT 0,
  `total_downtime_minutes` INT DEFAULT 0,
  `incident_count` INT DEFAULT 0,
  FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_daily_agg` (`interface_id`, `date`),
  INDEX `idx_agg_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(50) PRIMARY KEY,
  `key_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Settings
INSERT INTO `settings` (`key_name`, `key_value`) VALUES
('app_name', 'TraffMont - MikroTik Traffic & SLA Monitor'),
('cron_token', 'traffmont_secret_key_change_me'),
('timezone', 'Asia/Jakarta'),
('retention_days', '60'),
('alert_email', '')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

