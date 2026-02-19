CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret_enc TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS secrets (
  name VARCHAR(120) NOT NULL PRIMARY KEY,
  secret_enc TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_login_attempts_uip (username, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backup_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_backup_codes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trusted_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(16) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ua_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_trusted_devices_selector (selector),
  KEY idx_trusted_devices_user (user_id),
  KEY idx_trusted_devices_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(20) NOT NULL,
  channel VARCHAR(60) NOT NULL,
  message TEXT NOT NULL,
  context_json TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_app_logs_channel_date (channel, created_at),
  KEY idx_app_logs_level_date (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
