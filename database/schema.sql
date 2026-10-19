-- ==============================================================================
-- CivicShield: Privacy-Preserving Civic Incident Intelligence Platform
-- Phase 2: Relational Database Schema DDL (MySQL 8.x / MariaDB compatible)
-- Character Set: utf8mb4 | Collation: utf8mb4_unicode_ci | Engine: InnoDB
-- Web Technology Open Elective Project (OEP)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `civicshield_db`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `civicshield_db`;

-- ------------------------------------------------------------------------------
-- 1. USERS & ROLES
-- Stores platform accounts: citizens (pseudonymous), municipal field officers,
-- and system administrators. Sensitive contact details are separated.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(60) NOT NULL UNIQUE COMMENT 'Public display pseudonym for citizens or official handle for staff',
    `email` VARCHAR(150) NULL UNIQUE COMMENT 'Optional for citizens to preserve anonymity; required for staff',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'BCRYPT or Argon2id hashed password string',
    `role` ENUM('citizen', 'officer', 'admin') NOT NULL DEFAULT 'citizen',
    `department` VARCHAR(100) NULL COMMENT 'Municipal department affiliation for officers (e.g. Roads, Drainage)',
    `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. INCIDENT CATEGORIES
-- Supported civic incident classifications mapped to responsible municipal departments.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `department_code` VARCHAR(50) NOT NULL COMMENT 'Identifies responsible municipal department',
    `default_severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `resolution_sla_hours` INT UNSIGNED NOT NULL DEFAULT 72 COMMENT 'Standard target resolution time in hours',
    `icon` VARCHAR(50) NOT NULL DEFAULT 'bi-exclamation-triangle',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_categories_slug` (`slug`),
    INDEX `idx_categories_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. CONSOLIDATED INCIDENTS
-- Represents the canonical public civic issue. Multiple individual citizen reports
-- may be linked/consolidated to this single incident record.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_reference` VARCHAR(32) NOT NULL UNIQUE COMMENT 'Non-sequential human-readable reference, e.g. CS-SRT-2026-0101',
    `category_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `priority_score` INT NOT NULL DEFAULT 0 COMMENT 'Calculated priority score based on severity, report count & civic weight',
    `priority_breakdown` JSON NULL COMMENT 'Rule-based decision-support priority breakdown factors',
    `status` ENUM('reported', 'triaged', 'assigned', 'in_progress', 'resolved', 'closed', 'rejected') NOT NULL DEFAULT 'reported',
    -- Privacy-Preserving Obfuscated Spatial Coordinates
    `obfuscated_lat` DECIMAL(10, 8) NOT NULL COMMENT 'Centroid / differentially perturbed latitude for public map intelligence',
    `obfuscated_lng` DECIMAL(11, 8) NOT NULL COMMENT 'Centroid / differentially perturbed longitude for public map intelligence',
    `fuzz_radius_meters` INT UNSIGNED NOT NULL DEFAULT 150 COMMENT 'Gaussian noise radius applied to protect submitter location',
    `neighborhood` VARCHAR(150) NOT NULL COMMENT 'Ward / Locality name (e.g. Adajan, Vesu, Rander)',
    `city` VARCHAR(100) NOT NULL DEFAULT 'Surat',
    `reports_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Count of citizen reports linked to this consolidated issue',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` TIMESTAMP NULL,
    `closed_at` TIMESTAMP NULL,
    INDEX `idx_incidents_status` (`status`),
    INDEX `idx_incidents_severity` (`severity`),
    INDEX `idx_incidents_category` (`category_id`),
    INDEX `idx_incidents_priority` (`priority_score`),
    INDEX `idx_incidents_obf_spatial` (`obfuscated_lat`, `obfuscated_lng`),
    INDEX `idx_incidents_created_at` (`created_at`),
    CONSTRAINT `fk_incidents_category` FOREIGN KEY (`category_id`) 
        REFERENCES `incident_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. INDIVIDUAL CITIZEN INCIDENT REPORTS
-- Individual submissions from citizens. Links to consolidated incidents.
-- Uses one-way tracking token hashes and SHA-256 IP digests for citizen protection.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_reports` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_reference` VARCHAR(32) NOT NULL UNIQUE COMMENT 'Public report tracking identifier, e.g. REP-SRT-2026-0201',
    `tracking_token_hash` CHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of private single-use tracking token provided to citizen',
    `incident_id` BIGINT UNSIGNED NULL COMMENT 'Null when freshly filed; assigned upon triage/consolidation',
    `citizen_id` BIGINT UNSIGNED NULL COMMENT 'Null for guest/anonymous reports; user ID for registered citizens',
    `category_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `submitted_severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `submission_mode` ENUM('pseudonymous', 'anonymous') NOT NULL DEFAULT 'pseudonymous',
    -- Restricted Intake Coordinates (Restricted from public view; fuzzed upon consolidation)
    `raw_lat` DECIMAL(10, 8) NULL COMMENT 'Direct sensor latitude; restricted from public APIs',
    `raw_lng` DECIMAL(11, 8) NULL COMMENT 'Direct sensor longitude; restricted from public APIs',
    `location_description` VARCHAR(255) NULL COMMENT 'Citizen landmark description (e.g. Near Prime Arcade)',
    `ip_hash` CHAR(64) NULL COMMENT 'Salted SHA-256 hash of submitter IP address for anti-spam rate limiting',
    `user_agent_hash` CHAR(64) NULL COMMENT 'Hashed browser signature for anti-abuse verification',
    `status` ENUM('pending_review', 'consolidated', 'duplicate', 'standalone', 'rejected') NOT NULL DEFAULT 'pending_review',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reports_incident` (`incident_id`),
    INDEX `idx_reports_citizen` (`citizen_id`),
    INDEX `idx_reports_category` (`category_id`),
    INDEX `idx_reports_status` (`status`),
    INDEX `idx_reports_created_at` (`created_at`),
    INDEX `idx_reports_raw_coords` (`raw_lat`, `raw_lng`),
    CONSTRAINT `fk_reports_incident` FOREIGN KEY (`incident_id`) 
        REFERENCES `incidents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_reports_citizen` FOREIGN KEY (`citizen_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_reports_category` FOREIGN KEY (`category_id`) 
        REFERENCES `incident_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. RESTRICTED CITIZEN CONTACT DETAILS
-- Strictly isolated table for optional callback / emergency contact information.
-- NEVER exposed on public incident views or general APIs.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `citizen_contacts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id` BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'One-to-one relationship with specific incident report',
    `contact_name` VARCHAR(100) NULL COMMENT 'Optional name if citizen explicitly consented to verification callback',
    `contact_phone` VARCHAR(25) NULL COMMENT 'Optional callback phone number',
    `contact_email` VARCHAR(150) NULL COMMENT 'Optional status notification email',
    `preferred_contact_method` ENUM('none', 'phone', 'email', 'sms') NOT NULL DEFAULT 'none',
    `consent_emergency_followup` TINYINT(1) NOT NULL DEFAULT 0,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_contacts_report` FOREIGN KEY (`report_id`) 
        REFERENCES `incident_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. INCIDENT ATTACHMENTS (SANITIZED EVIDENCE)
-- File metadata for citizen-uploaded photos/documents. Image binaries are stored
-- in uploads/incidents/ with EXIF stripped. Only relative paths and hashes stored.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id` BIGINT UNSIGNED NOT NULL,
    `file_path` VARCHAR(255) NOT NULL COMMENT 'Relative storage path in uploads/incidents/',
    `file_name_original` VARCHAR(255) NOT NULL,
    `file_name_stored` VARCHAR(255) NOT NULL,
    `file_mime` VARCHAR(100) NOT NULL,
    `file_size_bytes` INT UNSIGNED NOT NULL,
    `sha256_hash` CHAR(64) NOT NULL COMMENT 'Content digest for deduplication and tamper verification',
    `exif_scrubbed` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Flag indicating GPS/camera metadata has been sanitized',
    `is_flagged_nsfw` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_attachments_report` (`report_id`),
    INDEX `idx_attachments_sha256` (`sha256_hash`),
    CONSTRAINT `fk_attachments_report` FOREIGN KEY (`report_id`) 
        REFERENCES `incident_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. INCIDENT DUPLICATE CANDIDATES & MERGE DECISION AUDIT
-- Explicitly models duplicate reporting. Candidate matches are scored via spatial,
-- temporal, and categorical metrics. Merges require explicit human review.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_duplicate_candidates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id` BIGINT UNSIGNED NOT NULL COMMENT 'New citizen submission being evaluated',
    `candidate_incident_id` BIGINT UNSIGNED NOT NULL COMMENT 'Existing consolidated incident candidate',
    `match_score` DECIMAL(5, 2) NOT NULL COMMENT 'Computed composite match confidence score (0.00 to 100.00%)',
    `spatial_distance_meters` INT UNSIGNED NOT NULL COMMENT 'Geodesic distance between report and candidate in meters',
    `time_difference_minutes` INT UNSIGNED NOT NULL COMMENT 'Time elapsed between submissions in minutes',
    `category_matched` TINYINT(1) NOT NULL DEFAULT 1,
    `text_similarity_score` DECIMAL(5, 2) NULL COMMENT 'NLP/Keyword similarity metric (0.00 to 100.00%)',
    `review_status` ENUM('pending_review', 'confirmed_duplicate', 'marked_distinct') NOT NULL DEFAULT 'pending_review',
    `reviewed_by_user_id` BIGINT UNSIGNED NULL COMMENT 'Admin/Officer who validated the match decision',
    `decision_notes` TEXT NULL,
    `reviewed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_dup_candidate` (`report_id`, `candidate_incident_id`),
    INDEX `idx_dup_review_status` (`review_status`),
    INDEX `idx_dup_score` (`match_score`),
    CONSTRAINT `fk_dup_report` FOREIGN KEY (`report_id`) 
        REFERENCES `incident_reports` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dup_incident` FOREIGN KEY (`candidate_incident_id`) 
        REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dup_reviewer` FOREIGN KEY (`reviewed_by_user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. INCIDENT ASSIGNMENTS
-- Work orders assigning consolidated civic incidents to departmental field officers.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_assignments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` BIGINT UNSIGNED NOT NULL,
    `officer_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NOT NULL,
    `assignment_status` ENUM('assigned', 'acknowledged', 'in_progress', 'escalated', 'reassigned', 'completed', 'cancelled') NOT NULL DEFAULT 'assigned',
    `instructions` TEXT NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `acknowledged_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_assignments_incident` (`incident_id`),
    INDEX `idx_assignments_officer` (`officer_id`),
    INDEX `idx_assignments_status` (`assignment_status`),
    CONSTRAINT `fk_assignments_incident` FOREIGN KEY (`incident_id`) 
        REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assignments_officer` FOREIGN KEY (`officer_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_assignments_admin` FOREIGN KEY (`assigned_by_user_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. RESOLUTION EVIDENCE
-- Verification artifacts submitted by officers upon resolving an incident.
-- Reviewed and verified by municipal administrators before closing incidents.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `resolution_evidence` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` BIGINT UNSIGNED NOT NULL,
    `assignment_id` BIGINT UNSIGNED NULL,
    `officer_id` BIGINT UNSIGNED NOT NULL,
    `photo_path` VARCHAR(255) NOT NULL COMMENT 'Relative storage path in uploads/incidents/ for completion proof',
    `photo_mime` VARCHAR(100) NOT NULL,
    `photo_size_bytes` INT UNSIGNED NOT NULL,
    `photo_sha256` CHAR(64) NOT NULL,
    `evidence_notes` TEXT NOT NULL,
    `inspection_lat` DECIMAL(10, 8) NULL COMMENT 'On-site verification coordinates recorded by officer',
    `inspection_lng` DECIMAL(11, 8) NULL,
    `verification_status` ENUM('pending_review', 'verified', 'rejected', 'rework_requested') NOT NULL DEFAULT 'pending_review',
    `verified_by_user_id` BIGINT UNSIGNED NULL,
    `verification_notes` TEXT NULL,
    `verified_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_evidence_incident` (`incident_id`),
    INDEX `idx_evidence_officer` (`officer_id`),
    INDEX `idx_evidence_status` (`verification_status`),
    CONSTRAINT `fk_evidence_incident` FOREIGN KEY (`incident_id`) 
        REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_evidence_assignment` FOREIGN KEY (`assignment_id`) 
        REFERENCES `incident_assignments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_evidence_officer` FOREIGN KEY (`officer_id`) 
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_evidence_verifier` FOREIGN KEY (`verified_by_user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. INCIDENT STATUS HISTORY
-- Complete audit trail of incident lifecycle transitions with actor tracking.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incident_status_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `incident_id` BIGINT UNSIGNED NOT NULL,
    `changed_by_user_id` BIGINT UNSIGNED NULL COMMENT 'User who initiated transition; null for automated system actions',
    `status_from` VARCHAR(50) NOT NULL,
    `status_to` VARCHAR(50) NOT NULL,
    `reason` TEXT NULL,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether transition note is visible on public tracking pages',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_history_incident` (`incident_id`),
    INDEX `idx_history_created` (`created_at`),
    CONSTRAINT `fk_history_incident` FOREIGN KEY (`incident_id`) 
        REFERENCES `incidents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_history_actor` FOREIGN KEY (`changed_by_user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. AUDIT LOGS (PRIVACY-PRESERVED)
-- Immutable ledger of administrative and system activities without personal data.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `actor_role` VARCHAR(50) NOT NULL,
    `action` VARCHAR(100) NOT NULL COMMENT 'e.g. INCIDENT_TRIAGED, DUPLICATE_CONFIRMED, EVIDENCE_VERIFIED',
    `target_type` VARCHAR(50) NOT NULL COMMENT 'Target entity type (e.g. incident, report, assignment)',
    `target_id` VARCHAR(60) NOT NULL COMMENT 'Entity identifier or reference',
    `ip_hash` CHAR(64) NULL COMMENT 'Salted SHA-256 digest of request IP address',
    `metadata_json` JSON NULL COMMENT 'Structured audit attributes excluding citizen PII',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_actor` (`actor_user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_target` (`target_type`, `target_id`),
    INDEX `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) 
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. LOGIN RATE LIMITS (PERSISTENT ABUSE MITIGATION)
-- Tracks failed login attempts across sessions to protect against brute-force attacks.
-- Privacy guarantee: Plaintext passwords are NEVER stored; rate_key is SHA-256 hashed.
-- ------------------------------------------------------------------------------
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

