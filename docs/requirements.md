# Project Requirements Summary

This document provides a concise overview of the functional and non‑functional requirements implemented in the CivicShield project. It reflects the actual codebase and test suites; no external or speculative requirements are listed.

## Core Functional Requirements

| ID | Requirement | Implemented By | Evidence |
|---|---|---|---|
| C1 | Citizen report submission with CSRF protection, image validation, and privacy‑preserving storage. | `citizen/report.php`, `core/Uploader.php`, `core/IncidentManager.php` | `tests/test_phase10.php` – passes all checks. |
| C2 | Duplicate detection and admin review workflow. | `admin/duplicates.php`, `core/DuplicateDetector.php` | `tests/test_phase6.php` – passes. |
| C3 | Distinct‑report promotion to a new incident. | `core/IncidentManager.php::createIncidentFromReport` | `tests/test_distinct_report_promotion.php`. |
| C4 | Admin incident queue with RBAC filtering. | `admin/incidents.php`, `core/IncidentManager.php::getIncidents` | `tests/test_phase7.php`. |
| C5 | Incident lifecycle state machine (`reported → triaged → assigned → in_progress → resolution_submitted → resolved → closed`). | `core/IncidentManager.php::ALLOWED_TRANSITIONS` | `tests/test_phase7.php`, `tests/test_phase10.php`. |
| C6 | Public tracking token lookup without exposing PII. | `track.php`, `api/v1/track.php` | `tests/test_phase10.php`. |
| C7 | Admin assignment of incidents to officers with audit logging. | `admin/incident_detail.php`, `core/IncidentManager.php::assignIncident` | `tests/test_phase7.php`. |
| C8 | Officer field inspection, status updates, and evidence upload. | `officer/incident_detail.php`, `core/IncidentManager.php::submitResolutionEvidence` | `tests/test_resolution_evidence_workflow.php`. |
| C9 | Analytics dashboard with privacy‑preserving map payloads. | `admin/analytics.php`, `core/AnalyticsManager.php` | `tests/test_phase9.php`. |
| C10 | Security hardening: login throttling, CSRF, input sanitization, upload `.htaccess`. | `core/Session.php`, `core/Validator.php`, `uploads/.htaccess` | `tests/test_login_rate_limit.php`. |

## Non‑Functional Requirements

- **Privacy** – All public endpoints omit raw GPS and citizen contact info; map data is obfuscated to ~150 m radius. Implemented via `IncidentManager::getLinkedReports` and `AnalyticsManager::getMapIncidents`.
- **RBAC** – Role checks via `includes/auth_guard.php` (`requireAuth('admin')`, `requireAuth('officer')`).
- **CSRF** – Tokens generated in `Session::generateCsrf()` and validated on every POST.
- **Audit Logging** – Immutable logs via `core/AuditLogger.php` for assignment, status changes, duplicate actions, and evidence reviews.
- **Performance** – Indexed columns (`incident_assignments.officer_id`, `incidents.status`) with migrations `001_phase6_priority_breakdown.sql` and `002_phase7_assignment_index.sql`.

*This summary is derived from the current implementation and test results; no additional requirements are assumed.*