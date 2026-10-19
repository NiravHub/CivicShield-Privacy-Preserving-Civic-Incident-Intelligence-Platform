-- ==============================================================================
-- CivicShield Database Migration 003: Persistent Login Rate Limiting Table
-- Replaces vulnerable session-scoped login throttling with database persistence.
-- Ensures rate-limiting survives session resets, cookie clearance, and incognito switching.
-- Privacy guarantee: Plaintext passwords are NEVER stored; rate_key is SHA-256 hashed.
-- ==============================================================================

USE `civicshield_db`;

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rate_key` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of throttle identifier (e.g. IP + username)',
    `ip_address` VARCHAR(45) NOT NULL COMMENT 'Client IPv4 or IPv6 address for source-based abuse mitigation',
    `username` VARCHAR(60) NULL COMMENT 'Target username (lowercased), or null for IP-only throttling',
    `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rate_key_time` (`rate_key`, `attempted_at`),
    INDEX `idx_rate_ip_time` (`ip_address`, `attempted_at`),
    INDEX `idx_rate_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
