-- ==============================================================================
-- CivicShield Database Migration 004
-- Title: Add 'resolution_submitted' state to incidents and incident_assignments
-- Date: 2026-10-20
-- Safe & Reversible: Preserves all existing status values and historical records.
-- ==============================================================================

-- 1. Expand incidents.status to include 'resolution_submitted'
ALTER TABLE `incidents` 
MODIFY COLUMN `status` ENUM(
    'reported', 
    'triaged', 
    'assigned', 
    'in_progress', 
    'resolution_submitted', 
    'resolved', 
    'closed', 
    'rejected'
) NOT NULL DEFAULT 'reported';

-- 2. Expand incident_assignments.assignment_status to include 'resolution_submitted'
ALTER TABLE `incident_assignments` 
MODIFY COLUMN `assignment_status` ENUM(
    'assigned', 
    'acknowledged', 
    'in_progress', 
    'resolution_submitted', 
    'escalated', 
    'reassigned', 
    'completed', 
    'cancelled'
) NOT NULL DEFAULT 'assigned';

-- ==============================================================================
-- ROLLBACK INSTRUCTIONS (DOWN MIGRATION):
-- Run the following only if no active rows have status = 'resolution_submitted':
--
-- ALTER TABLE `incidents` 
-- MODIFY COLUMN `status` ENUM(
--     'reported', 'triaged', 'assigned', 'in_progress', 'resolved', 'closed', 'rejected'
-- ) NOT NULL DEFAULT 'reported';
--
-- ALTER TABLE `incident_assignments` 
-- MODIFY COLUMN `assignment_status` ENUM(
--     'assigned', 'acknowledged', 'in_progress', 'escalated', 'reassigned', 'completed', 'cancelled'
-- ) NOT NULL DEFAULT 'assigned';
-- ==============================================================================
