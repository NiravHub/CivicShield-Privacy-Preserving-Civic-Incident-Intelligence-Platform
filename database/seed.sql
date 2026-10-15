-- ==============================================================================
-- CivicShield: Privacy-Preserving Civic Incident Intelligence Platform
-- Phase 2: Demonstration Seed Data (Surat Municipal Corporation Jurisdiction)
-- Web Technology Open Elective Project (OEP)
--
-- IMPORTANT LOCAL DEVELOPMENT NOTICE:
-- The credentials in this seed script are strictly for local academic development
-- and testing in XAMPP. Passwords are stored exclusively as valid BCRYPT hashes.
-- Never use these credentials or dummy hashes in production.
--
-- DEMO CREDENTIALS REFERENCE:
-- 1. Admin:
--    Username: admin_surat
--    Password: AdminSurat@2026
--    Role: admin | Scope: SMC Central Operations Command
--
-- 2. Field Officers:
--    Username: officer_patel | Password: OfficerPatel@2026 | Dept: Roads & Bridges (West Zone)
--    Username: officer_desai | Password: OfficerDesai@2026 | Dept: Drainage & Sewerage (South-West Zone)
--    Username: officer_shah  | Password: OfficerShah@2026  | Dept: Solid Waste Management (Central Zone)
--
-- 3. Registered Pseudonymous Citizens:
--    Username: vesu_watcher    | Password: VesuCivic@2026
--    Username: adajan_civic    | Password: SuratCitizen@2026
--    Username: varachha_safe   | Password: VarachhaSafe@2026
--
-- 4. Sample Private Tracking Tokens (for anonymous report lookup demonstration):
--    Token: cstk_vesu_waterleak_7a9f2b1c4e6d  -> Reports water rupture (REP-SRT-2026-0203)
--    Token: cstk_adajan_pothole_dup_3e2d1c0b -> Reports duplicate pothole (REP-SRT-2026-0202)
--    Token: cstk_rander_garbage_4f5e6d7c8b9a -> Reports waste overflow (REP-SRT-2026-0204)
-- ==============================================================================

USE `civicshield_db`;

-- ------------------------------------------------------------------------------
-- 1. SEED: INCIDENT CATEGORIES (SMC Municipal Classifications)
-- ------------------------------------------------------------------------------
INSERT INTO `incident_categories` 
(`id`, `name`, `slug`, `description`, `department_code`, `default_severity`, `resolution_sla_hours`, `icon`, `is_active`)
VALUES
(1, 'Roads & Potholes', 'roads-potholes', 'Asphalt craters, damaged pavers, road subsidence, and missing manhole covers', 'SMC-ROADS', 'medium', 48, 'bi-cone-striped', 1),
(2, 'Solid Waste & Garbage', 'solid-waste-garbage', 'Overflowing municipal bins, illegal construction debris, and uncollected waste', 'SMC-SWM', 'low', 24, 'bi-trash3-fill', 1),
(3, 'Drainage & Water Logging', 'drainage-water-logging', 'Potable water pipeline bursts, storm drainage overflow, and open cesspools', 'SMC-DRAINAGE', 'high', 24, 'bi-droplet-half', 1),
(4, 'Street Lighting & Electrical Hazards', 'street-lighting-electrical', 'Dark street poles, hanging high-voltage cables, and sparking transformer boxes', 'SMC-ELECTRICAL', 'high', 12, 'bi-lightning-charge-fill', 1),
(5, 'Public Health & Vector Control', 'public-health-vectors', 'Stagnant water breeding mosquitoes, biohazard waste, and sanitation failures', 'SMC-HEALTH', 'critical', 24, 'bi-shield-shaded', 1),
(6, 'Stray Animal Hazards', 'stray-animal-hazards', 'Aggressive stray packs, cattle obstructing arterial traffic, and animal injury', 'SMC-VET', 'medium', 36, 'bi-exclamation-octagon-fill', 1)
ON DUPLICATE KEY UPDATE 
    `name`=VALUES(`name`),
    `description`=VALUES(`description`),
    `resolution_sla_hours`=VALUES(`resolution_sla_hours`);

-- ------------------------------------------------------------------------------
-- 2. SEED: USERS (Admin, Field Officers, Pseudonymous Citizens)
-- Passwords generated with PHP password_hash(..., PASSWORD_BCRYPT)
-- ------------------------------------------------------------------------------
INSERT INTO `users`
(`id`, `username`, `email`, `password_hash`, `role`, `department`, `status`, `created_at`)
VALUES
-- Municipal Administrator
(1, 'admin_surat', 'admin.surat@civicshield.local', '$2y$10$Rt.DM1jDhzzdIJkIQvpedue5TfHi10p0xIhD1J8bJj1WwzFz1JvM6', 'admin', 'SMC Central Command', 'active', '2026-10-01 08:00:00'),

-- Field Officers
(2, 'officer_patel', 'officer.patel@suratmunicipal.gov.local', '$2y$10$v229kZVD/mqPYjaWNKL9Jut5F/ZpbkS9cs3qzL6yjl5AOFSivYO.O', 'officer', 'Roads & Bridges Department', 'active', '2026-10-01 08:30:00'),
(3, 'officer_desai', 'officer.desai@suratmunicipal.gov.local', '$2y$10$LwLNkN0XAcNYNPDw9UVV3uWje4g5pWraN9lnZ6.bzPM4zr1EGLJYO', 'officer', 'Drainage & Sewerage Department', 'active', '2026-10-01 08:35:00'),
(4, 'officer_shah', 'officer.shah@suratmunicipal.gov.local', '$2y$10$PtMvPUf2EUg5pOK08eaAX.cz4hd1Ue2yw5.C0wFkEgeBpd6gRSCGi', 'officer', 'Solid Waste Management', 'active', '2026-10-01 08:40:00'),

-- Registered Pseudonymous Citizens (Private details never stored in users table)
(5, 'vesu_watcher', 'vesu.watcher@shieldmail.local', '$2y$10$QGtXeiMXW67LET/2YJgs6OHkOSpwhK79wvCWFQG1MXMlfk0ZFKaG2', 'citizen', NULL, 'active', '2026-10-05 10:15:00'),
(6, 'adajan_civic', 'adajan.civic@shieldmail.local', '$2y$10$rdHMNC7/wo8WycKd6BYRV.xedldkW7eua3QglINoG0TtzXjFi3HJC', 'citizen', NULL, 'active', '2026-10-06 11:20:00'),
(7, 'varachha_safe', 'varachha.safe@shieldmail.local', '$2y$10$5cnWbCbEGKRGpciz8JEku.S2hAwvsnGibvgEBxUHObqsOu/1XbRSi', 'citizen', NULL, 'active', '2026-10-07 14:45:00')
ON DUPLICATE KEY UPDATE 
    `role`=VALUES(`role`),
    `status`=VALUES(`status`);

-- ------------------------------------------------------------------------------
-- 3. SEED: CONSOLIDATED INCIDENTS (Surat Municipal Geo-Fuzzed Clusters)
-- ------------------------------------------------------------------------------
INSERT INTO `incidents`
(`id`, `incident_reference`, `category_id`, `title`, `description`, `severity`, `priority_score`, `status`, `obfuscated_lat`, `obfuscated_lng`, `fuzz_radius_meters`, `neighborhood`, `city`, `reports_count`, `created_at`, `updated_at`, `resolved_at`)
VALUES
-- Incident 1: Adajan Pothole (Consolidates 2 citizen reports)
(1, 'CS-SRT-2026-0101', 1, 'Deep Road Excavation & Pothole Hazard near Prime Arcade', 'Severe 4-foot asphalt crater caused by monsoon runoff; continuous vehicle undercarriage damage and two-wheeler skid hazards.', 'high', 85, 'in_progress', 21.19630000, 72.79580000, 150, 'Adajan', 'Surat', 2, '2026-10-08 09:15:00', '2026-10-09 14:00:00', NULL),

-- Incident 2: Vesu Drinking Water Burst (Resolved)
(2, 'CS-SRT-2026-0102', 3, 'High-Pressure Potable Water Main Rupture on VIP Road', 'Treated municipal water supply line burst causing flooded roadway and loss of clean pressure across 3 residential societies.', 'critical', 95, 'resolved', 21.14480000, 72.77450000, 150, 'Vesu', 'Surat', 1, '2026-10-10 07:30:00', '2026-10-11 16:30:00', '2026-10-11 16:30:00'),

-- Incident 3: Rander Causeway Waste Overflow (Assigned to Officer Shah)
(3, 'CS-SRT-2026-0103', 2, 'Commercial Garbage Dump Overflowing onto Pedestrian Walkway', 'Bulk commercial food waste and plastic debris overflowing 200m before Rander Causeway approach; foul odor and hygiene hazard.', 'medium', 60, 'assigned', 21.21850000, 72.79320000, 150, 'Rander', 'Surat', 1, '2026-10-12 08:45:00', '2026-10-12 10:00:00', NULL),

-- Incident 4: Athwalines High-Voltage Dangling Line (Newly Reported)
(4, 'CS-SRT-2026-0104', 4, 'Sparking Overhead Transformer Cable Hanging at Pedestrian Height', 'Exposed 440V distribution wire snapping and dangling near Athwa Chowpatty bus stop; urgent shock prevention required.', 'critical', 90, 'reported', 21.17380000, 72.80910000, 150, 'Athwalines', 'Surat', 1, '2026-10-13 18:20:00', '2026-10-13 18:20:00', NULL)
ON DUPLICATE KEY UPDATE 
    `status`=VALUES(`status`),
    `reports_count`=VALUES(`reports_count`),
    `priority_score`=VALUES(`priority_score`);

-- ------------------------------------------------------------------------------
-- 4. SEED: INDIVIDUAL CITIZEN INCIDENT REPORTS
-- Tracking token hashes correspond to secure single-use strings provided to citizens.
-- ------------------------------------------------------------------------------
INSERT INTO `incident_reports`
(`id`, `report_reference`, `tracking_token_hash`, `incident_id`, `citizen_id`, `category_id`, `title`, `description`, `submitted_severity`, `submission_mode`, `raw_lat`, `raw_lng`, `location_description`, `ip_hash`, `user_agent_hash`, `status`, `created_at`)
VALUES
-- Report 1: Filed by registered citizen adajan_civic
(1, 'REP-SRT-2026-0201', '9df3a771991bcd9ee88ff461b266d33cd8d5b5541945e1ef32c6951d62fd4135', 1, 6, 1, 'Dangerous pothole outside Prime Arcade Adajan', 'Huge hole in left lane towards Gujarat Gas Circle. Autos swerving dangerously into oncoming traffic.', 'high', 'pseudonymous', 21.19652100, 72.79564200, 'Prime Arcade Gate 2, Adajan Main Road', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9', 'consolidated', '2026-10-08 09:15:00'),

-- Report 2: Filed anonymously by guest citizen (34m away from Report 1, identified as candidate duplicate)
(2, 'REP-SRT-2026-0202', '51f0f61d73c4f66eae12e2d9e02e40efa87a2dfa218a887689702dec294605ff', 1, NULL, 1, 'Deep asphalt crater near Prime Arcade shopping center', 'Deep open hole with exposed metal reinforcing rod. Seen a biker fall this morning.', 'high', 'anonymous', 21.19671000, 72.79589000, 'Opposite Prime Arcade Bus Shelter, Adajan', 'd41d8cd98f00b204e9800998ecf8427e00000000000000000000000000000000', 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9', 'duplicate', '2026-10-08 10:40:00'),

-- Report 3: Filed by registered citizen vesu_watcher (Water pipe burst)
(3, 'REP-SRT-2026-0203', 'f9ff82fbf815c638813347e43daca725d87561ba2d9733a119430d4ac9352776', 2, 5, 3, 'Massive clean drinking water pipe rupture on VIP Road', 'Gushing water flooding VIP Road opposite Shanti Asiatic School. Pressure dropping in societies.', 'critical', 'pseudonymous', 21.14495000, 72.77432000, 'VIP Road near Shanti Asiatic School, Vesu', 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789abcdef0123456789abcdef0', 'c7b41d6e8a0029b9f018318b7625841029177a419b489a27b987625143a0e981', 'consolidated', '2026-10-10 07:30:00'),

-- Report 4: Filed anonymously (Rander garbage overflow)
(4, 'REP-SRT-2026-0204', 'b260eaa61d23e3fa1c72fc223667c7ac281df04b779fb6a7be5f349b126e9a86', 3, NULL, 2, 'Rotting garbage spilled across Rander approach road', 'Sanitation dumper hasn’t cleared the site in 4 days. Dogs tearing trash bags into road.', 'medium', 'anonymous', 21.21873000, 72.79341000, '200m south of Rander Causeway traffic junction', 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210', '1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b', 'consolidated', '2026-10-12 08:45:00'),

-- Report 5: Filed by registered citizen varachha_safe (Athwalines electrical wire)
(5, 'REP-SRT-2026-0205', 'ff90cd87824e419596af960b546a2e62d21e64f9c172fd2d182cb20570a80b37', 4, 7, 4, 'Sparking live electrical wire hanging in Athwalines', 'Wire fell from pole after heavy wind, swinging near pedestrian walkway.', 'critical', 'pseudonymous', 21.17392000, 72.80931000, 'Near Athwa Chowpatty Circle opposite garden gate', '3344556677889900112233445566778899001122334455667788990011223344', '6677889900112233445566778899001122334455667788990011223344556677', 'standalone', '2026-10-13 18:20:00')
ON DUPLICATE KEY UPDATE 
    `status`=VALUES(`status`),
    `incident_id`=VALUES(`incident_id`);

-- ------------------------------------------------------------------------------
-- 5. SEED: RESTRICTED CITIZEN CONTACTS
-- Isolated from public views; accessible strictly to officers for verified emergencies.
-- ------------------------------------------------------------------------------
INSERT INTO `citizen_contacts`
(`id`, `report_id`, `contact_name`, `contact_phone`, `contact_email`, `preferred_contact_method`, `consent_emergency_followup`, `is_verified`, `created_at`)
VALUES
-- Citizen on Report 3 consented to emergency SMS/Phone for water department engineers
(1, 3, 'Rajesh V. (Vesu Society Secretary)', '+91 98251 01234', 'rajesh.vesu@samplemail.local', 'phone', 1, 1, '2026-10-10 07:32:00'),

-- Citizen on Report 1 requested email only for resolution confirmation
(2, 1, 'Adajan Resident (Private)', NULL, 'adajan.verify@samplemail.local', 'email', 1, 0, '2026-10-08 09:17:00')
ON DUPLICATE KEY UPDATE 
    `consent_emergency_followup`=VALUES(`consent_emergency_followup`);

-- ------------------------------------------------------------------------------
-- 6. SEED: INCIDENT ATTACHMENTS (SANITIZED EVIDENCE ARTIFACTS)
-- Metadata scrubbed (EXIF stripped); zero binary payloads stored in MySQL.
-- ------------------------------------------------------------------------------
INSERT INTO `incident_attachments`
(`id`, `report_id`, `file_path`, `file_name_original`, `file_name_stored`, `file_mime`, `file_size_bytes`, `sha256_hash`, `exif_scrubbed`, `is_flagged_nsfw`, `created_at`)
VALUES
(1, 1, 'uploads/incidents/rep_0201_adajan_pothole_a1.webp', 'IMG_20261008_Adajan.jpg', 'cs_att_adajan_pothole_9482.webp', 'image/webp', 342150, '8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a', 1, 0, '2026-10-08 09:16:00'),
(2, 2, 'uploads/incidents/rep_0202_adajan_pothole_a2.webp', 'WhatsApp_Image_Road.jpeg', 'cs_att_adajan_dup_1048.webp', 'image/webp', 289410, '1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b', 1, 0, '2026-10-08 10:41:00'),
(3, 3, 'uploads/incidents/rep_0203_vesu_waterburst.webp', 'camera_capture_vesu.png', 'cs_att_vesu_water_5521.webp', 'image/webp', 451200, '3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d', 1, 0, '2026-10-10 07:31:00'),
(4, 4, 'uploads/incidents/rep_0204_rander_waste.webp', 'rander_dumpster.jpg', 'cs_att_rander_waste_8192.webp', 'image/webp', 512080, '5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f', 1, 0, '2026-10-12 08:46:00'),
(5, 5, 'uploads/incidents/rep_0205_athwa_sparking_wire.webp', 'dangling_cable.jpg', 'cs_att_athwa_wire_3021.webp', 'image/webp', 389100, '7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b', 1, 0, '2026-10-13 18:22:00')
ON DUPLICATE KEY UPDATE 
    `sha256_hash`=VALUES(`sha256_hash`);

-- ------------------------------------------------------------------------------
-- 7. SEED: DUPLICATE CANDIDATE MATCH & HUMAN DECISION AUDIT
-- Explicitly illustrates the duplicate detection pipeline:
-- Report 2 was submitted 34m away and 85 mins later than Report 1.
-- The automated system computed a match score of 91.20%.
-- Admin confirmed the duplicate merge; incidents were NOT merged solely on distance.
-- ------------------------------------------------------------------------------
INSERT INTO `incident_duplicate_candidates`
(`id`, `report_id`, `candidate_incident_id`, `match_score`, `spatial_distance_meters`, `time_difference_minutes`, `category_matched`, `text_similarity_score`, `review_status`, `reviewed_by_user_id`, `decision_notes`, `reviewed_at`, `created_at`)
VALUES
(1, 2, 1, 91.20, 34, 85, 1, 84.50, 'confirmed_duplicate', 1, 'Visual inspection of citizen photo confirms identical pothole crater opposite Prime Arcade. Consolidated into CS-SRT-2026-0101 and boosted priority score from 65 to 85.', '2026-10-08 11:15:00', '2026-10-08 10:42:00')
ON DUPLICATE KEY UPDATE 
    `review_status`=VALUES(`review_status`),
    `decision_notes`=VALUES(`decision_notes`);

-- ------------------------------------------------------------------------------
-- 8. SEED: INCIDENT WORK ORDER ASSIGNMENTS
-- ------------------------------------------------------------------------------
INSERT INTO `incident_assignments`
(`id`, `incident_id`, `officer_id`, `assigned_by_user_id`, `assignment_status`, `instructions`, `assigned_at`, `acknowledged_at`, `completed_at`)
VALUES
-- Adajan Pothole assigned to Officer Patel (Roads)
(1, 1, 2, 1, 'in_progress', 'Deploy road patching crew with cold-mix asphalt. Barricade lane during active repair work.', '2026-10-08 11:30:00', '2026-10-08 12:05:00', NULL),

-- Vesu Water Rupture assigned to Officer Desai (Drainage/Water) - Successfully completed
(2, 2, 3, 1, 'completed', 'Isolate 300mm feeder line valve. Replace fractured ductile iron sleeve immediately.', '2026-10-10 08:00:00', '2026-10-10 08:15:00', '2026-10-11 15:30:00'),

-- Rander Garbage assigned to Officer Shah (Solid Waste)
(3, 3, 4, 1, 'assigned', 'Dispatch compactor truck RC-14 for immediate clearance and spray bleaching powder.', '2026-10-12 09:30:00', NULL, NULL)
ON DUPLICATE KEY UPDATE 
    `assignment_status`=VALUES(`assignment_status`);

-- ------------------------------------------------------------------------------
-- 9. SEED: RESOLUTION EVIDENCE (SUBMITTED BY FIELD OFFICER)
-- ------------------------------------------------------------------------------
INSERT INTO `resolution_evidence`
(`id`, `incident_id`, `assignment_id`, `officer_id`, `photo_path`, `photo_mime`, `photo_size_bytes`, `photo_sha256`, `evidence_notes`, `inspection_lat`, `inspection_lng`, `verification_status`, `verified_by_user_id`, `verification_notes`, `verified_at`, `created_at`)
VALUES
(1, 2, 2, 3, 'uploads/incidents/res_0102_vesu_water_repaired.webp', 'image/webp', 412900, '9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b', 'Repaired 300mm ductile pipe junction with new mechanical coupling. Pressure restored to 2.8 bar. Road resurfaced and opened to traffic.', 21.14482000, 72.77448000, 'verified', 1, 'On-site sensor logs verify water pressure normalized; field photo approved by SMC Central Command.', '2026-10-11 16:30:00', '2026-10-11 15:45:00')
ON DUPLICATE KEY UPDATE 
    `verification_status`=VALUES(`verification_status`),
    `verification_notes`=VALUES(`verification_notes`);

-- ------------------------------------------------------------------------------
-- 10. SEED: INCIDENT STATUS HISTORY
-- ------------------------------------------------------------------------------
INSERT INTO `incident_status_history`
(`id`, `incident_id`, `changed_by_user_id`, `status_from`, `status_to`, `reason`, `is_public`, `created_at`)
VALUES
-- Transitions for Incident 1 (Adajan Pothole)
(1, 1, 1, 'reported', 'triaged', 'Incident evaluated and validated by SMC Command.', 1, '2026-10-08 09:45:00'),
(2, 1, 1, 'triaged', 'assigned', 'Dispatched to Officer Patel (Roads & Bridges West Zone).', 1, '2026-10-08 11:30:00'),
(3, 1, 2, 'assigned', 'in_progress', 'Field inspection team on site; asphalt patching underway.', 1, '2026-10-08 12:05:00'),

-- Transitions for Incident 2 (Vesu Water Leak)
(4, 2, 1, 'reported', 'triaged', 'Critical water line rupture prioritized.', 1, '2026-10-10 07:45:00'),
(5, 2, 1, 'triaged', 'assigned', 'Dispatched emergency repair unit to Officer Desai.', 1, '2026-10-10 08:00:00'),
(6, 2, 3, 'assigned', 'in_progress', 'Feeder valve isolated; trench excavation started.', 1, '2026-10-10 08:15:00'),
(7, 2, 1, 'in_progress', 'resolved', 'Pipe sleeve replaced, water restored, site inspected and verified.', 1, '2026-10-11 16:30:00'),

-- Transitions for Incident 3 (Rander Waste)
(8, 3, 1, 'reported', 'assigned', 'Dispatched to Solid Waste Management Officer Shah.', 1, '2026-10-12 09:30:00'),

-- Transitions for Incident 4 (Athwalines Wire)
(9, 4, NULL, 'draft', 'reported', 'Citizen submission published into civic queue.', 1, '2026-10-13 18:20:00')
ON DUPLICATE KEY UPDATE 
    `status_to`=VALUES(`status_to`);

-- ------------------------------------------------------------------------------
-- 11. SEED: PRIVACY-PRESERVED AUDIT LOGS
-- Demonstrates immutable audit tracking without exposing citizen PII.
-- ------------------------------------------------------------------------------
INSERT INTO `audit_logs`
(`id`, `actor_user_id`, `actor_role`, `action`, `target_type`, `target_id`, `ip_hash`, `metadata_json`, `created_at`)
VALUES
(1, 1, 'admin', 'SYSTEM_SEED_INITIALIZED', 'system', 'civicshield_db', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '{"environment": "development", "version": "2.0.0", "jurisdiction": "Surat"}', '2026-10-01 08:00:00'),
(2, 1, 'admin', 'INCIDENT_TRIAGED', 'incident', 'CS-SRT-2026-0101', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '{"initial_severity": "high", "department": "SMC-ROADS"}', '2026-10-08 09:45:00'),
(3, 1, 'admin', 'DUPLICATE_MERGE_CONFIRMED', 'duplicate_candidate', '1', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '{"report_reference": "REP-SRT-2026-0202", "target_incident": "CS-SRT-2026-0101", "match_score": 91.2}', '2026-10-08 11:15:00'),
(4, 1, 'admin', 'OFFICER_DISPATCHED', 'assignment', '1', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '{"incident_ref": "CS-SRT-2026-0101", "assigned_officer": "officer_patel"}', '2026-10-08 11:30:00'),
(5, 1, 'admin', 'RESOLUTION_VERIFIED', 'evidence', '1', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', '{"incident_ref": "CS-SRT-2026-0102", "verified_by": "admin_surat"}', '2026-10-11 16:30:00')
ON DUPLICATE KEY UPDATE 
    `action`=VALUES(`action`);
