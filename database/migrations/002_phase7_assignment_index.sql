-- ==============================================================================
-- CivicShield Phase 7 Migration: Optimized Incident Assignment Index
-- Adds composite index for rapid active assignment lookups during triage & dispatch.
-- ==============================================================================

USE `civicshield_db`;

-- Add composite index on (incident_id, assignment_status) if not already present
-- Compatible with MySQL 8 and MariaDB 10.4+
SET @exist := (
    SELECT COUNT(*) 
    FROM information_schema.statistics 
    WHERE table_schema = DATABASE() 
      AND table_name = 'incident_assignments' 
      AND index_name = 'idx_assignments_active'
);

SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE `incident_assignments` ADD INDEX `idx_assignments_active` (`incident_id`, `assignment_status`)', 
    'SELECT "Index idx_assignments_active already exists."'
);

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
