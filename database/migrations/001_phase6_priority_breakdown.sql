-- ==============================================================================
-- CivicShield Phase 6 Migration: Incident Priority Breakdown
-- Stores rule-based transparent priority score component breakdown as JSON.
-- ==============================================================================

USE `civicshield_db`;

ALTER TABLE `incidents` 
ADD COLUMN IF NOT EXISTS `priority_breakdown` JSON NULL 
AFTER `priority_score`;
