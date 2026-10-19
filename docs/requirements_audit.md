# CivicShield: Evidence-Based Requirements & Verification Audit Report

**Audit Date:** October 19, 2026 (Revised & Re-executed)  
**Audited Directory:** `C:\Users\LENOVO\Desktop\Study\Semester 5\WT\OEP`  
**Execution Environment:** Windows 11 &bull; PHP 8.2.12 CLI (`C:\xampp\php\php.exe`) &bull; MariaDB/MySQL (`localhost:3306`) &bull; Apache HTTPD  
**Target Document:** Corrective audit of project checkpoints and missing requirements baseline  

---

## 1. Executive Summary & Checklist Limitation Statement

### 1.1. Status of the Original ~70–75 Requirements Checklist
An exhaustive, multi-pass search was conducted across the workspace, including `docs/`, `tests/`, `config/`, `README.md`, Git commit history, and Git object blobs.
* **Finding:** The original external checklist or curriculum specification containing approximately 70–75 numbered requirements **was not found** in the repository.
* **Evidence:**
  - `docs/requirements.md`: Present on disk but **0 bytes (empty)**.
  - `docs/project-audit.md`: Present on disk but **0 bytes (empty)**.
  - `docs/architecture.md`: Details database schemas, formulas, and RBAC matrices, but contains no enumerated 70–75 requirement checklist.
  - `README.md`: Outlines setup, technology stack, and demonstration workflows, but lacks a numbered requirement specification.
  - Git history (`274fdc5`): Contains solely the initial release commit (`feat: initial release of CivicShield - Privacy-Preserving Civic Incident Intelligence Platform (Phases 1-10)`); no prior requirements drafts were committed.
* **Distinction:** Per project instructions, this audit **does not fabricate or reconstruct** an artificial 75-point checklist. Instead, it audits the **83 project-derived checkpoints** explicitly documented in the repository:
  1. **67 Automated Regression Test Specifications** from Phase 6 through Phase 10 (`tests/test_phase6.php` to `tests/test_phase10.php`).
  2. **8 Core Functional Capabilities** identified in project directives (Citizen Reporting, Duplicate Review, Distinct Promotion, Admin Intake Queue, Incident Lifecycle, Tracking Token Isolation, Analytics Counts, and Live Map Data).
  3. **8 Academic Demonstration Walkthrough Workflows** documented in `docs/demo_guide.md`.

---

## 2. Test Execution & Verification Methodology

### 2.1. Confirmation of Automated Test Suites (Rerun Live)
All seven automated test suites were **actively rerun live** during this audit using `C:\xampp\php\php.exe` against the active local MySQL database:

```powershell
& "C:\xampp\php\php.exe" tests/test_phase6.php                   # Output: 14 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_phase7.php                   # Output: 14 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_phase8.php                   # Output: 14 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_phase9.php                   # Output: 12 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_phase10.php                  # Output: 13 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_login_rate_limit.php          # Output: 10 Passed, 0 Failed
& "C:\xampp\php\php.exe" tests/test_distinct_report_promotion.php # Output: 10 Passed, 0 Failed
```

* **Automated Test Results:** **87 passed, 0 failed (100% of automated test assertions).**
* **Syntax Validation:** All 40 project PHP files were validated with `php -l` (**0 syntax errors**).

### 2.2. Review of `docs/security_test_report.md`
`docs/security_test_report.md` documents 10 manual security checks (Authentication, RBAC, IDOR, SQLi, XSS, CSRF, File Upload, Session Security, Input Validation, Abuse/Rate Limiting).
* **Automated vs. Manual Evidence Distinction:**
  - Automated tests independently reproduce SQL injection parameterization, CSRF token validation, session destruction, and upload execution blocking (`uploads/.htaccess`).
  - Manual browser-dependent workflows (UI login cooldown alerts, DevTools cookie inspect, browser file picker validation) are recorded as **developer-reported** results; full request/response payloads and browser screenshots were partially retained.
  - Consequently, end-to-end browser UI workflows are marked **NEEDS REVIEW** pending automated browser test scripts.

---

## 3. Comprehensive Checkpoint Audit Table

### 3.1. Core Functional Capabilities (8 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **C1** | **Citizen Report Submission UI & Intake** | `citizen/report.php`, `citizen/report_success.php`, `tests/test_phase10.php` | CSRF token validation; Surat coordinate bounding (`21.05–21.30` Lat, `72.70–73.00` Lng); category/severity validation; optional contact enclave insertion; single-use token generation; upload security (magic-byte MIME check, executable rejection, 5MB limit); EXIF metadata stripping via GD re-encoding; error-handling hygiene. | Live HTTP request verification against local Apache/XAMPP (16/16 checks passed across 5 groups) + automated backend assertion (`test_phase10.php` #9). | **PASS** |
| **C2** | **Duplicate Review UI & Actions** | `admin/duplicates.php`, `core/DuplicateDetector.php`, `tests/test_phase6.php` | Algorithmic scoring breakdown (Haversine 40%, Category 30%, Text 20%, Time 10%); confirm duplicate binds report to canonical incident; mark distinct updates report. | Automated test (`test_phase6.php` #1, #11) + static code inspection; modal browser UI not automated. | **NEEDS REVIEW** |
| **C3** | **Distinct-Report Auto-Promotion** | `core/IncidentManager.php` (`createIncidentFromReport`), `admin/duplicates.php`, `citizen/report.php`, `tests/test_distinct_report_promotion.php` | Promotes report to canonical `incidents` row with differential spatial fuzzing (~150m), priority score, initial history ('intake' &rarr; 'reported'), and audit log; updates source report to `standalone`. Prevents duplicates and handles rollbacks. | Automated regression suite (`test_distinct_report_promotion.php`, 10/10 passed live) | **PASS** |
| **C4** | **Admin Intake Queue UI & Filtering** | `admin/incidents.php`, `core/IncidentManager.php` (`getIncidents`), `tests/test_phase7.php` | SQL pagination (`LIMIT`/`OFFSET`); filtering by status, category, minimum priority score, and search query; multi-role RBAC matrix validation across admin, officer, citizen, and guest endpoints. | Automated test (`test_phase7.php` #2) + live HTTP multi-role matrix verification (12 route/role checks passed). | **PASS** |
| **C5** | **Incident Lifecycle State Machine** | `core/IncidentManager.php` (`ALLOWED_TRANSITIONS`), `database/schema.sql`, `tests/test_phase7.php`, `tests/test_phase10.php` | Strict transition whitelist (`reported` → `triaged` → `assigned` → `in_progress` → `resolved` → `closed`); invalid jumps rejected; notes required on closure. | Automated test (`test_phase7.php` #7, #8, #9; `test_phase10.php` #1). | **PASS** |
| **C6** | **Tracking-Token Isolation & Public Track** | `track.php`, `api/v1/track.php`, `core/Session.php`, `tests/test_phase10.php` | Tokens stored only as SHA-256 hashes (`tracking_token_hash`); tracking queries strictly exclude `citizen_contacts` (PII) and `raw_lat`/`raw_lng`. | Automated test (`test_phase10.php` #9, #11). | **PASS** |
| **C7** | **Dashboard Telemetry & Aggregations** | `core/AnalyticsManager.php` (`getSummaryMetrics`), `admin/analytics.php`, `tests/test_phase9.php` | Computes Total Incidents, Corroborating Reports, Open Queue, Clearance Rate (%), and SLA average resolution hours with safe date normalization; live KPI telemetry cards, Chart.js canvases, spatial fuzzing radius validation (~52.5m–150m), and differential privacy distinction audit. | Automated test (`test_phase9.php` #2, #3, #7, #8) + live HTTP telemetry verification (5 checks passed across 5 groups). | **PASS** |
| **C8** | **Live Geospatial Map Data** | `index.php`, `assets/js/map.js`, `core/AnalyticsManager.php` (`getMapIncidents`), `tests/test_phase9.php` | Live DB feed queries `incidents` for active fuzzed pins (`obfuscated_lat/lng`) with static demo fallback when DB unreachable; privacy fuzzing radius applied. | Automated test (`test_phase9.php` #11; `test_phase10.php` #4) + static code inspection; Leaflet DOM rendering not automated. | **NEEDS REVIEW** |

---

### 3.2. Phase 6: Duplicate Detection & Priority Scoring (`tests/test_phase6.php` — 14 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **P6.1** | Very close coordinates (< 50m) & same category produce candidate | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Haversine distance formula yields $S_{\text{spatial}} > 0.85$; combined score exceeds threshold. | Automated test (rerun live) | **PASS** |
| **P6.2** | Incidents outside search radius (> 1000m) excluded | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Radius boundary cutoff rejects distant reports ($S_{\text{spatial}} = 0$). | Automated test (rerun live) | **PASS** |
| **P6.3** | Mismatched categories at same location receive lower score | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Strict category multiplier ($S_{\text{category}} = 0$) prevents false candidate match. | Automated test (rerun live) | **PASS** |
| **P6.4** | Similar text at distant location does not become duplicate | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Spatial gate rejects candidate even with high text similarity. | Automated test (rerun live) | **PASS** |
| **P6.5** | Different text at nearby location flagged if category matches | `core/DuplicateDetector.php`, `tests/test_phase6.php` | 40% spatial + 30% category blend exceeds threshold ($\ge 0.55$). | Automated test (rerun live) | **PASS** |
| **P6.6** | Multiple candidates ranked by match score descending | `core/DuplicateDetector.php`, `tests/test_phase6.php` | SQL `ORDER BY match_score DESC` verified across multiple candidates. | Automated test (rerun live) | **PASS** |
| **P6.7** | Missing or invalid coordinates handled safely | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Null coalescing and coordinate validation return empty list without uncaught exception. | Automated test (rerun live) | **PASS** |
| **P6.8** | Duplicate scores bounded strictly between 0.0 and 1.0 | `core/DuplicateDetector.php`, `tests/test_phase6.php` | `min(1.0, max(0.0, ...))` clamping applied on final heuristic score. | Automated test (rerun live) | **PASS** |
| **P6.9** | Priority scores bounded strictly between 0 and 100 | `core/PriorityScorer.php`, `tests/test_phase6.php` | `min(100, max(0, $score))` clamping enforced on severity integer. | Automated test (rerun live) | **PASS** |
| **P6.10** | Report counts do not double-count non-duplicate reports | `core/IncidentManager.php`, `tests/test_phase6.php` | `incidents.reports_count` incremented strictly upon duplicate confirmation. | Automated test (rerun live) | **PASS** |
| **P6.11** | Administrators can review, confirm, and mark distinct | `admin/duplicates.php`, `tests/test_phase6.php` | Admin session authorized to confirm duplicate or mark distinct; audit log created. | Automated test (rerun live) | **PASS** |
| **P6.12** | Citizens and guests denied duplicate review access | `includes/auth_guard.php`, `tests/test_phase6.php` | Non-admin sessions redirected or returned 403 Forbidden. | Automated test (rerun live) | **PASS** |
| **P6.13** | Repeated processing does not produce duplicate candidate rows | `core/DuplicateDetector.php`, `tests/test_phase6.php` | Unique constraint `uq_report_incident` prevents duplicate candidate pairs. | Automated test (rerun live) | **PASS** |
| **P6.14** | Login, report submission, and tracking pass unimpeded | `auth/login.php`, `citizen/report.php`, `track.php`, `tests/test_phase6.php` | Regression check confirms core citizen workflows execute without breakage. | Automated test (rerun live) | **PASS** |

---

### 3.3. Phase 7: Admin Incident Operations & State Machine (`tests/test_phase7.php` — 14 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **P7.1** | Role validation protects admin operational routes | `includes/auth_guard.php`, `tests/test_phase7.php` | `requireAuth('admin')` blocks non-admin users. | Automated test (rerun live) | **PASS** |
| **P7.2** | Incident filtering, searching, and pagination operate correctly | `core/IncidentManager.php` (`getIncidents`), `tests/test_phase7.php` | Dynamic WHERE bindings with safe LIMIT/OFFSET parameterization. | Automated test (rerun live) | **PASS** |
| **P7.3** | Active officer retrieval returns only 'active' status officers | `core/IncidentManager.php` (`getActiveOfficers`), `tests/test_phase7.php` | Query filters `role = 'officer' AND status = 'active'`. | Automated test (rerun live) | **PASS** |
| **P7.4** | Assigning incident to active officer updates status to 'assigned' | `core/IncidentManager.php` (`assignIncident`), `tests/test_phase7.php` | Inserts `incident_assignments` row; sets `incidents.status = 'assigned'`. | Automated test (rerun live) | **PASS** |
| **P7.5** | Assignment to citizen or invalid user is rejected | `core/IncidentManager.php` (`assignIncident`), `tests/test_phase7.php` | Role validation rejects non-officer assignees. | Automated test (rerun live) | **PASS** |
| **P7.6** | Reassignment marks previous work order as 'reassigned' | `core/IncidentManager.php` (`assignIncident`), `tests/test_phase7.php` | Prior active assignment updated to `assignment_status = 'reassigned'`. | Automated test (rerun live) | **PASS** |
| **P7.7** | Valid status lifecycle transitions succeed | `core/IncidentManager.php` (`updateStatus`), `tests/test_phase7.php` | Follows `ALLOWED_TRANSITIONS` sequence; records history. | Automated test (rerun live) | **PASS** |
| **P7.8** | Disallowed lifecycle transitions are rejected | `core/IncidentManager.php` (`updateStatus`), `tests/test_phase7.php` | Unauthorized status jumps blocked by state-machine validator. | Automated test (rerun live) | **PASS** |
| **P7.9** | Mandatory resolution and rejection notes enforced | `core/IncidentManager.php` (`updateStatus`), `tests/test_phase7.php` | Blank or whitespace notes on terminal statuses trigger validation error. | Automated test (rerun live) | **PASS** |
| **P7.10** | Resolving incident marks open assignment as 'completed' | `core/IncidentManager.php` (`updateStatus`), `tests/test_phase7.php` | Active work order transitioned to `completed`; sets `resolved_at`. | Automated test (rerun live) | **PASS** |
| **P7.11** | Priority score dynamically recalculates on report accumulation | `core/PriorityScorer.php`, `tests/test_phase7.php` | Volume heuristic increments priority as corroborating reports increase. | Automated test (rerun live) | **PASS** |
| **P7.12** | Incident detail queries return assignments and history | `core/IncidentManager.php` (`getIncidentDetails`), `tests/test_phase7.php` | Returns joined incident details, active officer, and status timeline. | Automated test (rerun live) | **PASS** |
| **P7.13** | Dangerous SQL injection and XSS input sanitized safely | `core/IncidentManager.php`, `tests/test_phase7.php` | Parameterized prepared statements; output entity escaping. | Automated test (rerun live) | **PASS** |
| **P7.14** | CSRF validation accepts valid tokens and rejects invalid | `core/Session.php` (`validateCsrf`), `tests/test_phase7.php` | Constant-time `hash_equals` verification; invalid tokens rejected. | Automated test (rerun live) | **PASS** |

---

### 3.4. Phase 8: Officer Portal & Field Operations (`tests/test_phase8.php` — 14 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **P8.1** | Officer role can access officer portal routes | `includes/auth_guard.php`, `tests/test_phase8.php` | `requireAuth('officer')` validates officer session claim. | Automated test (rerun live) | **PASS** |
| **P8.2** | Citizen role is denied officer routes | `includes/auth_guard.php`, `tests/test_phase8.php` | Citizens attempting to access `/officer/` receive 403 Forbidden. | Automated test (rerun live) | **PASS** |
| **P8.3** | Admin-only operations remain forbidden to officers | `admin/incidents.php`, `tests/test_phase8.php` | Officers attempting to load admin routes receive 403 Forbidden. | Automated test (rerun live) | **PASS** |
| **P8.4** | Officer sees only incidents assigned to their account | `core/IncidentManager.php` (`getOfficerIncidents`), `tests/test_phase8.php` | Scoped by `WHERE ia.officer_id = :officer_id AND ia.assignment_status = 'assigned'`. | Automated test (rerun live) | **PASS** |
| **P8.5** | Reassigned and completed work orders disappear from active queue | `core/IncidentManager.php` (`getOfficerIncidents`), `tests/test_phase8.php` | Stale or reassigned work orders excluded from active view. | Automated test (rerun live) | **PASS** |
| **P8.6** | Officer cannot access unassigned or peer incident dossiers | `core/IncidentManager.php` (`getOfficerIncidentDetail`), `tests/test_phase8.php` | IDOR protection: returns `null` if incident not assigned to requesting officer. | Automated test (rerun live) | **PASS** |
| **P8.7** | Valid officer status transitions succeed (`assigned` → `in_progress` → `resolved`) | `core/IncidentManager.php` (`updateOfficerStatus`), `tests/test_phase8.php` | Field transitions update `incidents.status` and status timeline. | Automated test (rerun live) | **PASS** |
| **P8.8** | Disallowed officer status transitions rejected | `core/IncidentManager.php` (`updateOfficerStatus`), `tests/test_phase8.php` | Officers cannot set `closed`, `rejected`, or `reported`. | Automated test (rerun live) | **PASS** |
| **P8.9** | Missing and invalid CSRF tokens rejected on status updates | `officer/incident_detail.php`, `tests/test_phase8.php` | Form enforces `Session::validateCsrf($_POST['csrf_token'])`. | Automated test (rerun live) | **PASS** |
| **P8.10** | Operational notes required ($\ge 5$ characters) | `core/IncidentManager.php` (`updateOfficerStatus`), `tests/test_phase8.php` | Notes shorter than 5 non-whitespace characters rejected. | Automated test (rerun live) | **PASS** |
| **P8.11** | Status history and audit logs record transitions with officer ID | `core/IncidentManager.php`, `tests/test_phase8.php` | Inserts `incident_status_history` and `audit_logs` attributing actor. | Automated test (rerun live) | **PASS** |
| **P8.12** | SQL injection and XSS in operational notes handled safely | `core/IncidentManager.php`, `tests/test_phase8.php` | Prepared statements prevent SQL breakout; output escaped. | Automated test (rerun live) | **PASS** |
| **P8.13** | Private contact info and raw GPS omitted from officer views | `core/IncidentManager.php` (`getLinkedReports`), `tests/test_phase8.php` | `$includeRawGps = false` strictly strips `raw_lat`, `raw_lng`, and contact PII. | Automated test (rerun live) | **PASS** |
| **P8.14** | Admin operations remain functional alongside officer workflows | `admin/incident_detail.php`, `tests/test_phase8.php` | Dual-role workflows operate concurrently without lock contention. | Automated test (rerun live) | **PASS** |

---

### 3.5. Phase 9: Civic Intelligence Dashboard & Analytics (`tests/test_phase9.php` — 12 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **P9.1** | Non-admin users denied analytics routes | `admin/analytics.php`, `tests/test_phase9.php` | `requireAuth('admin')` restricts access to administrators. | Automated test (rerun live) | **PASS** |
| **P9.2** | Summary KPI metrics aggregate accurately across database | `core/AnalyticsManager.php` (`getSummaryMetrics`), `tests/test_phase9.php` | `COUNT(DISTINCT i.id)`, `SUM(i.reports_count)`, clearance rate calculated via SQL. | Automated test (rerun live) | **PASS** |
| **P9.3** | Average resolution time computed from closed timestamps | `core/AnalyticsManager.php` (`getSummaryMetrics`), `tests/test_phase9.php` | `AVG(TIMESTAMPDIFF(SECOND, created_at, resolved_at)) / 3600.0`. | Automated test (rerun live) | **PASS** |
| **P9.4** | Category filtering aggregates department totals accurately | `core/AnalyticsManager.php` (`getCategoryDistribution`), `tests/test_phase9.php` | SQL `GROUP BY c.slug` with category parameterization. | Automated test (rerun live) | **PASS** |
| **P9.5** | Status filtering aggregates lifecycle distribution accurately | `core/AnalyticsManager.php` (`getStatusDistribution`), `tests/test_phase9.php` | Aggregates 7 canonical statuses; zero-count statuses mapped cleanly. | Automated test (rerun live) | **PASS** |
| **P9.6** | Priority tier distribution groups Low, Medium, High, Critical | `core/AnalyticsManager.php` (`getPriorityStratification`), `tests/test_phase9.php` | Correctly stratifies: Critical ($\ge 75$), High ($50–74$), Medium ($25–49$), Low ($< 25$). | Automated test (rerun live) | **PASS** |
| **P9.7** | Date normalization handles missing, invalid, or inverted dates | `core/AnalyticsManager.php` (`normalizeDateRange`), `tests/test_phase9.php` | `DateTime` parsing; safely auto-swaps inverted `date_from > date_to`. | Automated test (rerun live) | **PASS** |
| **P9.8** | Empty result sets return zeroed KPIs without division errors | `core/AnalyticsManager.php`, `tests/test_phase9.php` | Returns `0`, `0.0%`, and `null` for resolution time without division-by-zero warnings. | Automated test (rerun live) | **PASS** |
| **P9.9** | Malicious SQL and XSS in filters safely parameterized | `core/AnalyticsManager.php`, `tests/test_phase9.php` | Filter arguments passed via PDO named parameters. | Automated test (rerun live) | **PASS** |
| **P9.10** | Volume trends series are chronologically ordered and formatted | `core/AnalyticsManager.php` (`getVolumeTrends`), `tests/test_phase9.php` | Returns time-series intake vs resolution pairs formatted for Chart.js. | Automated test (rerun live) | **PASS** |
| **P9.11** | Map analytics strictly preserves whistleblower spatial privacy | `core/AnalyticsManager.php` (`getMapIncidents`), `tests/test_phase9.php` | Excludes `raw_lat`, `raw_lng`, and contact enclave; returns fuzzed centroids. | Automated test (rerun live) | **PASS** |
| **P9.12** | Core managers function uninterrupted by analytical queries | `core/IncidentManager.php`, `core/DuplicateDetector.php`, `tests/test_phase9.php` | Non-blocking analytical queries preserve transaction throughput. | Automated test (rerun live) | **PASS** |

---

### 3.6. Phase 10: Final Security Audit & Integration Consistency (`tests/test_phase10.php` — 13 Checkpoints)

| # | Checkpoint | Relevant Source & Test Files | Evidence Found | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **P10.1** | Status vocabulary consistent across schema, managers, analytics | `core/IncidentManager.php`, `core/AnalyticsManager.php`, `database/schema.sql`, `tests/test_phase10.php` | Canonical statuses strictly aligned: `reported`, `triaged`, `assigned`, `in_progress`, `resolved`, `closed`, `rejected`. | Automated test (rerun live) | **PASS** |
| **P10.2** | Active assignment queries exclude reassigned work orders | `core/IncidentManager.php` (`getIncidentDetails`), `tests/test_phase10.php` | Joins active assignment where `assignment_status = 'assigned'`. | Automated test (rerun live) | **PASS** |
| **P10.3** | Officer linked reports omit restricted raw intake coordinates | `core/IncidentManager.php` (`getLinkedReports`), `tests/test_phase10.php` | Strips `raw_lat`, `raw_lng`, and `citizen_contacts` from officer payloads. | Automated test (rerun live) | **PASS** |
| **P10.4** | Analytics map payload free of contact enclave and raw GPS | `core/AnalyticsManager.php` (`getMapIncidents`), `tests/test_phase10.php` | Verifies absence of forbidden PII and raw coordinates in map feed. | Automated test (rerun live) | **PASS** |
| **P10.5** | CSRF helper accepts valid tokens and rejects invalid | `core/Session.php` (`validateCsrf`), `tests/test_phase10.php` | Validates CSRF tokens; rejects null, empty, or tampered strings. | Automated test (rerun live) | **PASS** |
| **P10.6** | Login throttling engages persistently after repeated failed attempts | `core/Session.php` (`isLoginThrottled`, `recordFailedLogin`), `tests/test_phase10.php`, `tests/test_login_rate_limit.php` | Throttles identifier after 5 consecutive failures for 300s cooldown; persists across session resets via `login_rate_limits` table; defends against IP-level stuffing. | Automated test (rerun live) | **PASS** |
| **P10.7** | Uploads directory blocks script execution via `.htaccess` | `uploads/.htaccess`, `tests/test_phase10.php` | Configured with `RemoveHandler .php` and `Deny from all` execution block. | Automated test (rerun live) | **PASS** |
| **P10.8** | Core managers instantiate and respond without regressions | `core/IncidentManager.php`, `core/AnalyticsManager.php`, `tests/test_phase10.php` | Regression tests confirm all core services respond cleanly. | Automated test (rerun live) | **PASS** |
| **P10.9** | End-to-end reporting and token hash isolation verified | `citizen/report.php`, `track.php`, `tests/test_phase10.php` | Single-use token hash lookup verified; contact enclave completely decoupled. | Automated test (rerun live) | **PASS** |
| **P10.10** | Officer access boundaries strictly enforced (no cross-view) | `core/IncidentManager.php` (`getOfficerIncidentDetail`), `tests/test_phase10.php` | IDOR defense: returns `null` on unauthorized access; update rejected. | Automated test (rerun live) | **PASS** |
| **P10.11** | SQL injection and XSS safety on tracking token inputs | `track.php`, `api/v1/track.php`, `tests/test_phase10.php` | Prepared statements prevent SQL breakout; inputs hashed via SHA-256. | Automated test (rerun live) | **PASS** |
| **P10.12** | Session destruction and open redirect sanitization | `core/Session.php` (`destroy`, `sanitizeRedirect`), `tests/test_phase10.php` | Empties `$_SESSION`; sanitizes protocol wrappers and external URLs to safe target. | Automated test (rerun live) | **PASS** |
| **P10.13** | Resolution duration telemetry handles null timestamps safely | `core/AnalyticsManager.php` (`getSummaryMetrics`), `tests/test_phase10.php` | Unresolved or open statuses return `null` instead of division-by-zero errors. | Automated test (rerun live) | **PASS** |

---

### 3.7. Academic Demonstration Walkthrough Workflows (`docs/demo_guide.md` — 8 Checkpoints)

| # | Checkpoint | Relevant Files & Endpoints | Verification Scope | Verification Method | Status |
| :-: | :--- | :--- | :--- | :--- | :-: |
| **D1** | Citizen Reporting & One-Time Receipt Walkthrough | `citizen/report.php`, `citizen/report_success.php` | Form submission intake, contact enclave storage, server-side MIME verification, disk-level EXIF stripping, single-use token display (`cstk_[0-9a-f]{32}` matching SHA-256 hash in DB), one-time session unsetting on refresh (redirect 302, zero token exposure). | Live HTTP walkthrough against local Apache/XAMPP (16/16 checks passed across 5 groups). | **PASS** |
| **D2** | Public Status Tracking Without Login or PII | `track.php`, `api/v1/track.php` | Public tracking by private token (HTML & JSON REST API v1), pre-hashed SHA-256 DB index lookup, SLA window calculation, complete absence of citizen PII (`citizen_contacts`), raw sensor GPS coordinates, internal officer instructions, and token hashes; generalized neighborhood spatial privacy; IDOR/parameter tampering resistance (`?id=1`, `?report_id=2` rejected); public endpoint IP rate limiting (`HTTP 429`). | Live HTTP verification against local Apache/XAMPP (17/17 checks passed across 7 groups). | **PASS** |
| **D3** | Admin Duplicate Detection & Triage Walkthrough | `admin/duplicates.php`, `core/DuplicateDetector.php`, `core/IncidentManager.php` | Candidate match detection, side-by-side comparison queue, authorization boundaries (302/403), CSRF protection, duplicate confirmation (links to canonical incident, increments reports_count, updates priority, 0 orphan incidents, audit log `DUPLICATE_CONFIRMED`), distinct report promotion (creates new incident with initial status `reported`, differential spatial fuzzing within 40–180m, initial status history `'intake'` &rarr; `'reported'`, audit log `DUPLICATE_MARKED_DISTINCT`), and repeat submission idempotency (0 double-promotions, 0 double-counts). | Live HTTP walkthrough against local Apache/XAMPP (14/14 checks passed across 6 groups). | **PASS** |
| **D4** | Admin Incident Dispatch & Work Order Assignment | `admin/incidents.php`, `admin/incident_detail.php`, `core/IncidentManager.php` | Eligible officer selection by department, dispatch work order creation (`incident_assignments`), status transition to `'assigned'`, status history logging with public visibility, immutable audit logging (`INCIDENT_ASSIGNED`), UI reflection on reload (officer name, department, badge, instructions), assigned officer queue reflection (`officer/index.php`), peer officer queue isolation, officer reassignment workflow (`reassigned` status transition, queue transfer, `INCIDENT_REASSIGNED` audit log), rejection of invalid/citizen/suspended/empty officers, closed incident protection, RBAC boundary enforcement (302 unauthenticated, 403 citizen/officer), CSRF rejection without state modification, and repeat submission idempotency (strict single active work order invariant). | Live HTTP walkthrough against local Apache/XAMPP (23/23 checks passed across 7 groups). | **PASS** |
| **D5** | Officer Field Inspection & Status Remediation | `officer/index.php`, `officer/incident_detail.php`, `core/IncidentManager.php` | Assigned officer work order dossier access, operational instructions & linked reports display, cross-officer IDOR defense (403 Forbidden on viewing/updating peer incident), valid transition to `in_progress` with `acknowledged_at` timestamp, transition to `resolved` with `completed_at` timestamp and `resolved_at` incident timestamp, note validation ($\ge 5$ characters required; empty/short notes rejected), invalid/disallowed transition rejection (jumping assigned $\to$ resolved, admin-only closure rejection), RBAC (302 unauthenticated, 403 citizen), CSRF mismatch rejection, repeat submission idempotency (zero duplicate logs), and public tracking privacy preservation (zero officer username, internal notes, or raw GPS leakage). | Live HTTP walkthrough against local Apache/XAMPP (17/17 checks passed across 9 groups). | **PASS** |
| **D6** | Admin Verification & Incident Closure Walkthrough | `admin/incident_detail.php`, `core/IncidentManager.php` | Review officer remediation notes, transition to `closed`, verification notes validation ($\ge 5$ chars), CSRF protection, disallowed state transitions rejection, immutable history & audit logging, repeat submission idempotency, UI state persistence (closed badge, dispatch modal hidden), and public tracking privacy preservation. | Live HTTP walkthrough against local Apache/XAMPP (9/9 checks passed across 9 groups). | **PASS** |
| **D7** | Civic Intelligence & Spatial Analytics Walkthrough | `admin/analytics.php`, `core/AnalyticsManager.php` | KPI summary cards (5 metrics), Chart.js longitudinal trends & breakdowns (4 canvas elements), Leaflet spatial cluster map (`#analyticsClusterMap`), filter toolbar reactivity (status, category, valid dates, inverted dates, search keyword), SQL injection/XSS resilience, and privacy payload audit (obfuscated centroids with ~150m fuzz radius, zero raw GPS or contact enclave). | Live HTTP walkthrough against local Apache/XAMPP (5/5 checks passed across 5 groups). | **PASS** |
| **D8** | Negative Security & Access Boundary Verifications | `includes/auth_guard.php`, `core/Session.php`, `admin/incidents.php`, `officer/incident_detail.php` | 16 live HTTP checks verified across 5 groups: unauthenticated access 302/401, citizen privilege escalation 403, cross-officer IDOR 403, missing/tampered CSRF rejection, and zero sensitive data leakage in response payloads. | Live HTTP request verification against local Apache/XAMPP (16/16 passed) | **PASS** |

#### 3.7.1. Checkpoint D8 Verification Evidence Breakdown (16 Live HTTP Checks)
*Scope Notice: This manual verification confirms the 16 specific role-boundary, CSRF, and negative security scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit.*

* **Group 1: Unauthenticated Direct Access Controls (4 checks):**
  - **1a:** `GET /admin/incidents.php` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?redirect=%2FOEP%2Fadmin%2Fincidents.php`. Response body: 0 bytes. **(PASS)**
  - **1b:** `GET /admin/analytics.php` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?redirect=%2FOEP%2Fadmin%2Fanalytics.php`. Zero metrics emitted. **(PASS)**
  - **1c:** `GET /officer/index.php` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?redirect=%2FOEP%2Fofficer%2Findex.php`. Zero work orders emitted. **(PASS)**
  - **1d:** AJAX `GET /admin/incidents.php` (`Accept: application/json`, `X-Requested-With: XMLHttpRequest`) &rarr; `HTTP 401 Unauthorized` with clean JSON error payload (`"Authentication required to access this endpoint."`). **(PASS)**
* **Group 2: Citizen Privilege Escalation Prevention (4 checks):**
  - **2a:** Authenticated citizen `vesu_watcher` `GET /admin/incidents.php` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied` template rendered; reports role `citizen` not permitted). Zero database records emitted. **(PASS)**
  - **2b:** Citizen `GET /admin/analytics.php` &rarr; `HTTP 403 Forbidden`. Zero KPI cards or Chart.js data emitted. **(PASS)**
  - **2c:** Citizen `GET /officer/index.php` &rarr; `HTTP 403 Forbidden`. Zero officer queues emitted. **(PASS)**
  - **2d:** Citizen AJAX `GET /admin/incidents.php` (`Accept: application/json`) &rarr; `HTTP 403 Forbidden` JSON error payload (`"Access denied. Role \"citizen\" is not permitted to access this resource."`). **(PASS)**
* **Group 3: Cross-Officer IDOR Prevention (4 checks):**
  - **3a:** `officer_desai` (assigned Incident #2) accessing Incident #1 (assigned to `officer_patel`) &rarr; `HTTP 403 Forbidden` (`403 — Unauthorized Incident Access`). Incident #1 title and coordinates strictly omitted. **(PASS)**
  - **3b:** `officer_desai` accessing Incident #3 (assigned to `officer_shah`) &rarr; `HTTP 403 Forbidden`. Zero peer dossier data emitted. **(PASS)**
  - **3c:** `officer_desai` accessing non-existent Incident #99999 &rarr; `HTTP 403 Forbidden` without SQL error or structure leakage. **(PASS)**
  - **3d:** Positive Control: `officer_desai` accessing assigned Incident #2 &rarr; `HTTP 200 OK` (authorized work order dossier rendered). **(PASS)**
* **Group 4: CSRF Protection & State Tampering Prevention (3 checks):**
  - **4a:** Admin `POST /admin/incidents.php` with missing `csrf_token` &rarr; HTML re-render displays alert `'Security token expired or invalid. Please refresh the page.'`; AJAX returns `HTTP 400 Bad Request`. Database assignment unmodified. **(PASS)**
  - **4b:** Admin `POST /admin/incidents.php` with tampered `csrf_token` &rarr; Constant-time hash check fails; HTML displays `'Security token expired or invalid'`; AJAX returns `HTTP 400 Bad Request`. State unmodified. **(PASS)**
  - **4c:** Officer `POST /officer/incident_detail.php?id=2` with tampered `csrf_token` &rarr; Flash alert displayed: `'Security token mismatch. Please refresh and try again.'`. Incident status unmodified. **(PASS)**
* **Group 5: Response Hygiene & Information Exposure Inspection (1 check):**
  - **5a:** Scanned all 10 unauthenticated, citizen, and cross-officer response bodies &rarr; 0 database passwords or Bcrypt hashes (`$2y$10$`), 0 `SQLSTATE`/`PDOException` stack traces, and 0 citizen callback phones/emails (`citizen_contacts`) emitted. **(PASS)**

#### 3.7.2. Checkpoint C1 & D1 Verification Evidence Breakdown (16 Live HTTP Checks)
*Scope Notice: This manual/integration verification confirms the 16 specific citizen reporting intake, upload security, EXIF metadata scrubbing on disk, single-use token receipt, and error hygiene scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Citizen Report Submission Intake Workflow (1 check):**
  - **1.1:** Authenticated citizen `vesu_watcher` submits valid incident report (Category: *Drainage & Water Logging*, Lat: `21.155420`, Lng: `72.784110`, contact follow-up consent enabled) &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/citizen/report_success.php`. Verified in DB: `report_reference` matches `REP-SRT-2026-[A-Z0-9]{6}`, `status = 'standalone'`, `tracking_token_hash` is 64-char hex SHA-256 digest, raw coordinates accurately stored, and `citizen_contacts` enclave created with `consent_emergency_followup = 1` and `preferred_contact_method = 'email'`. **(PASS)**
* **Group 2: Upload Validation & Content-Based MIME Inspection (4 checks):**
  - **2.1:** Upload of executable PHP script disguised as JPEG (`exploit.jpg` with `Content-Type: image/jpeg` header containing `<?php phpinfo(); ?>`) &rarr; `HTTP 200` form re-render with alert: *"The uploaded file appears to be corrupted or is not a valid raster image."* Zero database records created; zero files written to `uploads/incidents/`. **(PASS)**
  - **2.2:** Upload of raw Windows PE binary executable (`tool.exe` with `application/x-msdownload` header and `MZ\x90\x00` magic bytes) &rarr; `HTTP 200` form re-render with alert: *"Unsupported file format. Only JPEG, PNG, and WebP raster images are permitted."* Zero database records created. **(PASS)**
  - **2.3:** Upload of oversized image (5.63 MB synthetic JPEG exceeding 5 MB threshold) &rarr; `HTTP 200` form re-render with alert: *"Attachment exceeds the maximum allowed size of 5 MB."* Stored size: 0 bytes. **(PASS)**
  - **2.4:** Content-based MIME detection overriding spoofed client header/extension (valid PNG image submitted with spoofed `spoofed.jpg` filename and `image/jpeg` header) &rarr; `HTTP 302 Found` to success page. Server-side `finfo(FILEINFO_MIME_TYPE)` detected true MIME `image/png`; stored file renamed to cryptographically random filename with `.png` extension (`att_*.png`), ignoring spoofed `.jpg` client header. **(PASS)**
* **Group 3: Sensitive EXIF Metadata Scrubbing Verification on Disk (1 check):**
  - **3.1:** Synthetic JPEG created with active EXIF metadata (Camera Make: `CivicTestCam`, Model: `Model-Alpha-2026`, GPSLatitude: `21° 10' 12.34" N`, GPSLongitude: `72° 50' 45.67" E`). Pre-upload inspection via `exif_read_data()` verified all tags present. Submitted via report form and saved to disk. Post-upload inspection of saved file in `uploads/incidents/att_*.jpg` via `exif_read_data()` verified: GPS tags = 0 (completely scrubbed), camera tags = 0 (completely scrubbed), `exif_scrubbed` database flag = `1`. Valid raster JPEG preserved (`getimagesize()` IMAGETYPE_JPEG). **(PASS)**
* **Group 4: One-Time Receipt Token Disclosure & Refresh Invalidation (3 checks):**
  - **4.1:** Initial `GET /citizen/report_success.php` with submission session cookie &rarr; `HTTP 200 OK`. Renders `<input id="privateTokenDisplay" value="cstk_[0-9a-f]{32}">`. Constant-time `hash_equals()` verified SHA-256 of displayed raw token equals `tracking_token_hash` in `incident_reports`. **(PASS)**
  - **4.2:** Refresh/reload test: Second immediate `GET /citizen/report_success.php` using identical session cookie &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/citizen/`. Session unset verified via `unset($_SESSION['submitted_report'])`; raw token not present in response body. **(PASS)**
  - **4.3:** Unauthenticated direct access to `report_success.php` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?redirect=%2FOEP%2Fcitizen%2Freport_success.php`. Zero token leakage. **(PASS)**
* **Group 5: Error Handling Hygiene & Information Exposure Prevention (7 checks):**
  - **5.1:** Missing CSRF token &rarr; `HTTP 200` &bull; Sanitized alert: *"Security token mismatch or expired session. Please refresh and try again."* **(PASS)**
  - **5.2:** Short incident title (< 5 chars) &rarr; `HTTP 200` &bull; Sanitized alert: *"Incident title must be between 5 and 200 characters."* **(PASS)**
  - **5.3:** Short incident description (< 10 chars) &rarr; `HTTP 200` &bull; Sanitized alert: *"Incident description must be between 10 and 3000 characters."* **(PASS)**
  - **5.4:** Out-of-range coordinates (`lat=120.5, lng=250.0`) &rarr; `HTTP 200` &bull; Sanitized alert: *"Latitude must be between -90 and 90, and longitude between -180 and 180."* **(PASS)**
  - **5.5:** Non-existent category ID (`999999`) &rarr; `HTTP 200` &bull; Sanitized alert: *"Please select a valid civic incident category."* **(PASS)**
  - **5.6:** Malformed emergency contact email &rarr; `HTTP 200` &bull; Sanitized alert: *"Please enter a valid emergency follow-up email address or leave it blank."* **(PASS)**
  - **5.7:** Global leak inspection across all error response bodies: 0 `SQLSTATE`/`PDOException` stack traces, 0 filesystem path disclosures (`C:\...`), 0 database credentials/hashes (`$2y$10$`). **(PASS)**

#### 3.7.3. Checkpoint D2 Verification Evidence Breakdown (17 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 17 specific public status tracking, token hashing, privacy preservation, parameter tampering defense, and rate-limiting scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Valid Tracking Token Status Retrieval (5 checks):**
  - **1.1:** Web tracking page `GET /track.php?token=cstk_vesu_waterleak_7a9f2b1c4e6d` &rarr; `HTTP 200 OK`. Renders public reference `REP-SRT-2026-0203`, title *"Massive clean drinking water pipe rupture on VIP Road"*, and SLA target window *"24 Hours"*. **(PASS)**
  - **1.2:** Web tracking page `POST /track.php` with valid form CSRF token &rarr; `HTTP 200 OK`. Verified badge *"Resolution Confirmed"* and verified status rendered. **(PASS)**
  - **1.3:** JSON API `GET /api/v1/track.php?token=...` &rarr; `HTTP 200 OK` &bull; `Content-Type: application/json`. Returns structured payload with `success: true`, status `'resolved'`, `target_sla_hours: 24`, and department `'SWD'`. **(PASS)**
  - **1.4:** JSON API `POST /api/v1/track.php` with `application/json` body `{"token": "..."}` &rarr; `HTTP 200 OK` &bull; Returns authorized payload matching GET response. **(PASS)**
  - **1.5:** Token isolation: Token Adajan (`cstk_adajan_pothole_dup_3e2d1c0b`) resolves strictly to `REP-SRT-2026-0202`; Token Rander (`cstk_rander_garbage_4f5e6d7c8b9a`) resolves strictly to `REP-SRT-2026-0204`. Distinct tokens never leak across dossiers. **(PASS)**
* **Group 2: Invalid, Malformed, and Boundary Token Rejection (4 checks):**
  - **2.1:** Non-existent random token (`cstk_nonexistent_token_7a9f...`) &rarr; HTML returns `HTTP 200` with generic alert: *"No incident report found matching this tracking token. Please verify the 37-character token string and try again."*; API returns `HTTP 404 Not Found` with clean JSON error. Zero report references revealed. **(PASS)**
  - **2.2:** Partial/truncated prefix (`cstk_vesu_waterleak`) &rarr; `HTTP 200` alert / `HTTP 404` API error. Zero partial substring matching. **(PASS)**
  - **2.3:** SQL injection payload (`' OR '1'='1' -- `) &rarr; Safely hashed via SHA-256 before indexed query; standard rejection rendered; 0 `SQLSTATE`/PDO exceptions. **(PASS)**
  - **2.4:** Empty token &rarr; HTML renders blank unsubmitted form; API returns `HTTP 400 Bad Request` (*"Please provide a valid tracking token."*). **(PASS)**
* **Group 3: Privacy Hygiene & Zero PII / Coordinate Exposure Check (1 check):**
  - **3.1:** Deep scan across full rendered HTML, DOM, and API JSON payload for Report #3:
    - Citizen identity: 0 usernames (`vesu_watcher`), 0 account emails (`vesu.watcher@shieldmail.local`).
    - Citizen contact enclave: 0 contact names (*"Rajesh V."*), 0 contact phones (*"+91 98251 01234"*), 0 contact emails (*"rajesh.vesu@samplemail.local"*).
    - Coordinates: 0 raw GPS latitude/longitude occurrences (`21.14495000`, `72.77432000`).
    - Internal operations: 0 officer usernames (`officer_desai`), 0 operational instructions (*"Isolate 300mm feeder line valve..."*).
    - System secrets: 0 token hashes (`f9ff82fb...`), 0 database identifiers (`civicshield_db`, `incident_reports`). **(PASS)**
* **Group 4: Spatial Privacy & Neighborhood Generalization (1 check):**
  - **4.1:** Location display in HTML and API strictly generalized to administrative neighborhood string (*"Vesu"*); privacy badge displayed (*"Whistleblower Identity Protected: Submitter pseudonym, raw sensor coordinates, and contact details are excluded from this tracking view."*). Raw coordinates completely omitted. **(PASS)**
* **Group 5: Parameter Tampering & IDOR Bypass Prevention (4 checks):**
  - **5.1:** `GET /track.php?id=1` (without token) &rarr; Renders empty search form; zero report data displayed. **(PASS)**
  - **5.2:** `GET /track.php?report_id=2` (without token) &rarr; Zero report data displayed. **(PASS)**
  - **5.3:** Conflicting parameter injection `GET /track.php?token=VALID_VESU_TOKEN&id=1&report_id=1&incident_id=1` &rarr; Retrieves ONLY Report #3 (`REP-SRT-2026-0203`), completely ignoring injected IDs. **(PASS)**
  - **5.4:** API parameter injection `POST /api/v1/track.php` with `{"id": 1, "report_reference": "REP-SRT-2026-0201"}` (no token) &rarr; `HTTP 400 Bad Request`. **(PASS)**
* **Group 6: Token Comparison Safety & Timing Observation (1 check):**
  - **6.1:** 10-iteration timing sample: Avg valid token: 34.15 ms, Avg invalid token: 42.07 ms (delta: 7.92 ms, dominated by network/HTTP stack). Input pre-hashed via fixed-length SHA-256 before indexed database lookup; application code performs zero plaintext string equality checks. **(PASS)**
* **Group 7: Public API Rate Limiting Verification (1 check):**
  - **7.1:** Rapid burst of 25 lookups from client IP &rarr; Rate limit triggered at attempt 21 with `HTTP 429 Too Many Requests` (*"Too many lookup requests. Please wait ... seconds."*). Localhost throttle records purged after test. **(PASS)**

#### 3.7.4. Checkpoint D3 Verification Evidence Breakdown (14 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 14 specific duplicate detection intake, admin triage queue presentation, access control / CSRF protection, canonical duplicate binding, distinct incident promotion with spatial fuzzing, and idempotency scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Duplicate Candidate Intake Detection & Hold (1 check):**
  - **1.1:** Citizen intake of report similar to Incident #1 (`CS-SRT-2026-0101`, same category, 4m distance, match score 75.11% $\ge 55\%$) &rarr; `HTTP 302 Found` to report success. Report status held in `pending_review` with unlinked `incident_id = NULL`; candidate record created in `incident_duplicate_candidates` with `review_status = 'pending_review'`, candidate incident ID 1, spatial distance 4m, and score 75.11%. **(PASS)**
* **Group 2: Admin Duplicate Queue Presentation & Side-by-Side Comparison (1 check):**
  - **2.1:** Admin access to `GET /admin/duplicates.php?status=pending_review` &rarr; `HTTP 200 OK`. Renders candidate pair card with incoming report reference, title, canonical target reference (`CS-SRT-2026-0101`), target title (*"Deep Road Excavation &amp; Pothole Hazard"*), match score badge, distance metric, and explicit action buttons (*"Confirm Duplicate"*, *"Mark Distinct"*). **(PASS)**
* **Group 3: Security & Access Boundary Verifications on Review Actions (6 checks):**
  - **3.1:** Unauthenticated `POST /admin/duplicates.php` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?redirect=%2FOEP%2Fadmin%2Fduplicates.php`. Candidate state untouched. **(PASS)**
  - **3.2:** Authenticated citizen `vesu_watcher` `POST /admin/duplicates.php` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **3.3:** Authenticated officer `officer_desai` `POST /admin/duplicates.php` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **3.4:** Admin `POST /admin/duplicates.php` with missing `csrf_token` &rarr; `HTTP 200` re-render with alert: *"Security token expired or invalid. Please refresh the page."* Database state untouched. **(PASS)**
  - **3.5:** Admin `POST /admin/duplicates.php` with tampered `csrf_token` &rarr; `HTTP 200` re-render with alert: *"Security token expired or invalid. Please refresh the page."* Database state untouched. **(PASS)**
  - **3.6:** Integrity check across all rejected security attempts &rarr; Candidate status in `incident_duplicate_candidates` strictly preserved as `'pending_review'`. **(PASS)**
* **Group 4: Scenario A — Confirm Duplicate Walkthrough & Metric Recalculation (2 checks):**
  - **4.1:** Admin submits `decision = 'confirmed_duplicate'` &rarr; `HTTP 302 Found` redirect. Database verified: candidate updated to `review_status = 'confirmed_duplicate'`, reviewer set to Admin (User ID 1), report updated to `status = 'duplicate'` and bound to canonical `incident_id = 1`, Incident #1 `reports_count` incremented from 3 to 4, total incidents in database unchanged (0 orphan incidents created), and audit log `DUPLICATE_CONFIRMED` recorded with reviewer ID. **(PASS)**
  - **4.2:** Admin navigates to `GET /admin/duplicates.php?status=confirmed_duplicate` &rarr; `HTTP 200 OK`. Renders candidate card under Confirmed Duplicate tab with report reference, confirmed status badge, and reviewer justification note. **(PASS)**
* **Group 5: Scenario B — Mark Distinct Walkthrough & State Machine Promotion (2 checks):**
  - **5.1:** Admin submits `decision = 'marked_distinct'` on second candidate pair &rarr; `HTTP 302 Found` redirect. Database verified: candidate updated to `review_status = 'marked_distinct'`, report updated to `status = 'standalone'` and bound to new incident, new canonical incident created with initial `status = 'reported'`, `reports_count = 1`, unique reference `CS-SRT-2026-XXXXXX`, initial status history record created (`'intake'` &rarr; `'reported'`), and audit log `DUPLICATE_MARKED_DISTINCT` recorded. **(PASS)**
  - **5.2:** Coordinate obfuscation check: Promoted incident coordinates verified against raw intake coordinates via Haversine calculation. Raw: (21.196350, 72.795850) &bull; Obfuscated: differential fuzz distance verified at 77.9 meters, strictly conforming to the documented 40m–180m (~150m bound) privacy policy. **(PASS)**
* **Group 6: Idempotency & Repeat Submission Protection (2 checks):**
  - **6.1:** Repeated POST of `marked_distinct` on an already-promoted candidate &rarr; Total incidents in database remains unchanged (0 duplicate incidents created); report state preserved as `status = 'standalone'` and linked to existing promoted incident. **(PASS)**
  - **6.2:** Repeated POST of `confirmed_duplicate` on an already-confirmed candidate &rarr; Incident #1 `reports_count` remains unchanged (zero double-counting of reports count). **(PASS)**

#### 3.7.5. Checkpoint D4 Verification Evidence Breakdown (23 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 23 specific admin dispatch, work order lifecycle, status transition, department routing, queue reflection, reassignment, invalid officer rejection, RBAC boundary, CSRF defense, and idempotency scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Eligible Officer Dispatch & Work Order Creation (3 checks):**
  - **1.1:** Admin dispatches eligible officer (`officer_patel`, ID 2, Roads & Bridges) with operational instructions via `POST /admin/incident_detail.php?id={id}` &rarr; `HTTP 302 Found` redirect. Incident status transitions from `reported` to `assigned`; active work order created in `incident_assignments` with `assignment_status = 'assigned'`, assigned by Admin (User ID 1), instructions text recorded, and non-null timestamp. **(PASS)**
  - **1.2:** Status history audit trail: `incident_status_history` records entry (`'reported'` &rarr; `'assigned'`) citing officer username, department (*"Assigned to officer_patel (Roads & Bridges Department)."*), changed by Admin ID 1, and `is_public = 1`. **(PASS)**
  - **1.3:** Immutable audit ledger: `audit_logs` records `action = 'INCIDENT_ASSIGNED'`, `target_id = incident_reference`, `actor_role = 'admin'`, salted IP hash, and structured JSON metadata including officer name, department, assignment ID, and dispatch instructions. **(PASS)**
* **Group 2: UI Persistence & Officer Portal Queue Reflection (3 checks):**
  - **2.1:** Admin dossier reload (`GET /admin/incident_detail.php?id={id}`) &rarr; `HTTP 200 OK`. Renders assigned officer name, department (*"Roads &amp; Bridges Department"*), status badge `cs-badge-assigned`, operational instructions, and lifecycle timeline entry. **(PASS)**
  - **2.2:** Assigned officer personal portal (`GET /officer/index.php` as `officer_patel`) &rarr; `HTTP 200 OK`. Work order appears in active queue with incident reference and title. **(PASS)**
  - **2.3:** Peer officer queue isolation (`GET /officer/index.php` as `officer_desai`) &rarr; `HTTP 200 OK`. The incident is strictly absent from peer officer's active work orders. **(PASS)**
* **Group 3: Officer Reassignment Workflow (3 checks):**
  - **3.1:** Admin reassigns incident to `officer_desai` (Drainage & Sewerage) with updated instructions &rarr; `HTTP 302 Found`. Previous work order automatically updated to `assignment_status = 'reassigned'`; new active work order created with `assignment_status = 'assigned'`; exactly 1 active work order maintained. **(PASS)**
  - **3.2:** Reassignment audit log & status history: `audit_logs` records `action = 'INCIDENT_REASSIGNED'` with `is_reassignment: true` and new officer metadata; status history logs reassignment note. **(PASS)**
  - **3.3:** Queue transfer verification: Incident cleanly removed from `officer_patel`'s active queue and now displayed in `officer_desai`'s active queue. **(PASS)**
* **Group 4: Rejection of Invalid, Inactive, and Unauthorized Officers (5 checks):**
  - **4.1:** Non-existent officer ID (`99999`) &rarr; `HTTP 200` re-render with alert: *"Selected officer account does not exist."* Database state untouched. **(PASS)**
  - **4.2:** Citizen account (`vesu_watcher`, ID 5, role `citizen`) &rarr; `HTTP 200` re-render with alert: *"Selected user is not a field officer. Only officer accounts can receive dispatch assignments."* Zero assignments created. **(PASS)**
  - **4.3:** Suspended/inactive officer account &rarr; `HTTP 200` re-render with alert: *"Selected officer account is inactive or suspended."* Zero assignments created. **(PASS)**
  - **4.4:** Empty officer selection (`officer_id = 0`) &rarr; `HTTP 200` re-render with alert: *"Please select an eligible field officer for assignment."* Zero assignments created. **(PASS)**
  - **4.5:** Assignment attempt on closed incident (`id = 246`) &rarr; `HTTP 200` re-render with alert: *"Cannot assign an incident that is currently closed or rejected."* **(PASS)**
* **Group 5: RBAC & Access Boundary Verifications (6 checks):**
  - **5.1:** Unauthenticated `POST /admin/incident_detail.php` with `action=assign` &rarr; `HTTP 302 Found` to login. Database untouched. **(PASS)**
  - **5.2:** Citizen `POST /admin/incident_detail.php` with `action=assign` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **5.3:** Officer `POST /admin/incident_detail.php` with `action=assign` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **5.4:** Unauthenticated `POST /admin/incidents.php` with `action=assign` &rarr; `HTTP 302 Found` to login. Database untouched. **(PASS)**
  - **5.5:** Citizen `POST /admin/incidents.php` with `action=assign` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **5.6:** Officer `POST /admin/incidents.php` with `action=assign` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
* **Group 6: CSRF Protection & Tamper Resistance (2 checks):**
  - **6.1:** Admin assignment POST with missing `csrf_token` &rarr; `HTTP 200` &bull; Alert: *"Security token expired or invalid. Please refresh the page."* Database state untouched. **(PASS)**
  - **6.2:** Admin assignment POST with tampered `csrf_token` &rarr; `HTTP 200` &bull; Alert: *"Security token expired or invalid. Please refresh the page."* Database state untouched. **(PASS)**
* **Group 7: Repeat Submission Idempotency (1 check):**
  - **7.1:** Repeated assignment POST to the same officer &rarr; Replaces active assignment maintaining the strict invariant of exactly 1 active work order (`assignment_status NOT IN ('completed', 'cancelled', 'reassigned')`). Zero orphaned or corrupted records. **(PASS)**

#### 3.7.6. Checkpoint D5 Verification Evidence Breakdown (17 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 17 specific officer work order inspection, cross-officer IDOR defense, state machine lifecycle transitions (assigned -> in_progress -> resolved), mandatory completion notes validation, disallowed transition rejection, RBAC boundary, CSRF defense, repeat submission idempotency, and public tracking privacy scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Work Order & Incident View by Assigned Officer (1 check):**
  - **1.1:** Assigned officer `officer_patel` accesses `GET /officer/incident_detail.php?id={id}` &rarr; `HTTP 200 OK`. Renders incident reference, category (*Roads &amp; Potholes*), SLA window (*48 Hours*), dispatch instructions (*"Deploy asphalt repair crew..."*), linked citizen report reference, and obfuscated centroid coordinates, while raw intake GPS is strictly omitted. **(PASS)**
* **Group 2: IDOR Defense & Cross-Officer Access Restriction (3 checks):**
  - **2.1:** Peer officer `officer_desai` accesses `GET /officer/incident_detail.php?id={id}` &rarr; `HTTP 403 Forbidden` (`403 — Unauthorized Incident Access`). Operational instructions and linked reports strictly omitted. **(PASS)**
  - **2.2:** Peer officer `officer_desai` submits `POST /officer/incident_detail.php?id={id}` attempting status update &rarr; `HTTP 403 Forbidden`. Database status unmodified. **(PASS)**
  - **2.3:** Peer officer `officer_desai` submits `POST /officer/` (dashboard quick action) targeting Patel's incident &rarr; Fails closed via active assignment verification; database status strictly preserved as `assigned`. **(PASS)**
* **Group 3: Validation Rejection of Insufficient Notes (1 check):**
  - **3.1:** Officer submits transition to `in_progress` with short note (`"ok"`, < 5 chars) &rarr; `HTTP 302 Found` redirect with flash error: *"A descriptive note of at least 5 characters is required when setting status to 'in_progress'."* Database status untouched (`assigned`). **(PASS)**
* **Group 4: Disallowed State Transitions / State Machine Enforcement (2 checks):**
  - **4.1:** Officer attempts direct jump from `assigned` to `resolved` (skipping `in_progress`) &rarr; Rejected with alert: *"Officers are not authorized to transition incident from 'assigned' to 'resolved'."* Incident status preserved as `assigned`. **(PASS)**
  - **4.2:** Officer attempts transition to `closed` &rarr; Rejected with alert: *"Officers are not authorized to transition incident from 'assigned' to 'closed'."* Closure strictly restricted to administrators. **(PASS)**
* **Group 5: State Transition: assigned -> in_progress (2 checks):**
  - **5.1:** Assigned officer submits transition to `in_progress` with operational crew notes ($\ge 5$ chars) &rarr; `HTTP 302 Found` redirect. Database verified: `incidents.status` updated to `'in_progress'`; `incident_assignments.assignment_status` updated to `'in_progress'` with `acknowledged_at` timestamp populated; `incident_status_history` records entry (`'assigned'` &rarr; `'in_progress'`) with officer user ID; and audit log `OFFICER_STATUS_UPDATE` logged. **(PASS)**
  - **5.2:** Officer attempts transition to `resolved` with short note (`"done"`, 4 chars < 5) &rarr; Rejected with alert: *"A descriptive note of at least 5 characters is required when setting status to 'resolved'."* Status preserved as `'in_progress'`. **(PASS)**
* **Group 6: State Transition: in_progress -> resolved (1 check):**
  - **6.1:** Assigned officer submits transition to `resolved` with detailed completion notes &rarr; `HTTP 302 Found` redirect. Database verified: `incidents.status` updated to `'resolved'` with non-null `resolved_at`; `incident_assignments.assignment_status` updated to `'completed'` with `completed_at` timestamp; status history records transition (`'in_progress'` &rarr; `'resolved'`); and audit log `OFFICER_STATUS_UPDATE` logged. **(PASS)**
* **Group 7: RBAC & CSRF Protection (4 checks):**
  - **7.1:** Unauthenticated `POST /officer/incident_detail.php?id={id}` &rarr; `HTTP 302 Found` &bull; `Location: http://localhost/OEP/auth/login.php?...`. **(PASS)**
  - **7.2:** Authenticated citizen `vesu_watcher` `POST /officer/incident_detail.php?id={id}` &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). **(PASS)**
  - **7.3:** Officer POST with missing `csrf_token` &rarr; Rejected with flash error: *"Security token mismatch. Please refresh and try again."* **(PASS)**
  - **7.4:** Officer POST with tampered `csrf_token` &rarr; Rejected with flash error: *"Security token mismatch. Please refresh and try again."* **(PASS)**
* **Group 8: Repeat Submission Idempotency (1 check):**
  - **8.1:** Duplicate transition request to `resolved` on an already-resolved incident &rarr; Rejected with error alert; status history record count remains unchanged (zero duplicate log records). **(PASS)**
* **Group 9: UI Persistence & Public Tracking Privacy Hygiene (2 checks):**
  - **9.1:** Officer dossier reload (`GET /officer/incident_detail.php?id={id}`) &rarr; `HTTP 200 OK`. Renders *"Field Remediation Resolved"* completion banner, *"Awaiting Central Command Verification"* badge, and resolution notes in the timeline. **(PASS)**
  - **9.2:** Public tracking view (`GET /track.php?token=...`) &rarr; `HTTP 200 OK`. Confirms resolution banner (*"Resolution Confirmed"*) while strictly omitting officer username (`officer_patel`), dispatch instructions, officer completion notes, and raw sensor GPS coordinates. **(PASS)**

#### 3.7.7. Checkpoint D6 Verification Evidence Breakdown (9 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 9 specific administrator incident verification, closure transition, operational note review, unauthorized role exclusion, CSRF validation, repeat closure idempotency, and public tracking privacy preservation scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Admin Dossier Access & Officer Remediation Review (1 check):**
  - **1.1:** Administrator accesses resolved incident (`GET /admin/incident_detail.php?id={id}`) &rarr; `HTTP 200 OK`. Dossier renders incident reference, category, active officer name (`officer_patel`), resolved status badge, and historical resolution notes (*"Replacement valve fitted and leak halted. Site restored."*). **(PASS)**
* **Group 2: Role-Based Authorization Enforcement (3 checks):**
  - **2.1:** Unauthenticated user submits closure request &rarr; `HTTP 302 Found` redirect to login (`/auth/login.php?redirect=...`). Database status preserved as `resolved`. **(PASS)**
  - **2.2:** Authenticated citizen (`vesu_watcher`) submits closure request &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). Database status preserved as `resolved`. **(PASS)**
  - **2.3:** Field officer (`officer_patel`) submits closure request on admin endpoint &rarr; `HTTP 403 Forbidden` (`403 — Access Denied`). Closure strictly restricted to administrators. **(PASS)**
* **Group 3: Mandatory Verification Note Enforcement (1 check):**
  - **3.1:** Administrator submits closure request with short reason (`"done"`, 4 chars < 5) &rarr; `HTTP 200` re-render with alert: *"A descriptive note of at least 5 characters is required when setting status to 'closed'."* Database status untouched (`resolved`). **(PASS)**
* **Group 4: CSRF Protection & Token Validation (1 check):**
  - **4.1:** Administrator submits closure with missing CSRF token and tampered token &rarr; `HTTP 200` re-render with alert: *"Security token expired or invalid. Please refresh the page."* Database state untouched. **(PASS)**
* **Group 5: Disallowed State Transitions Rejection (1 check):**
  - **5.1:** Administrator attempts invalid lifecycle jump from `resolved` to `reported` &rarr; Rejected with alert: *"Invalid transition: Cannot move incident from 'resolved' to 'reported'."* Incident status preserved as `resolved`. **(PASS)**
* **Group 6: Valid Incident Closure Transition (1 check):**
  - **6.1:** Administrator submits closure with verified engineering justification ($\ge 5$ chars) &rarr; `HTTP 302 Found` redirect. Database verified: `incidents.status` set to `'closed'`; `incidents.closed_at` populated with timestamp; any open work orders auto-completed (`assignment_status = 'completed'`); `incident_status_history` records entry (`'resolved'` &rarr; `'closed'`) with admin actor ID; and audit log `INCIDENT_STATUS_TRANSITION` logged. **(PASS)**
* **Group 7: Repeat Closure Idempotency (1 check):**
  - **7.1:** Administrator submits duplicate closure request on already-closed incident &rarr; Rejected with alert: *"Incident is already in 'closed' status."* Status history count unchanged (zero duplicate log entries). **(PASS)**
* **Group 8: Admin Dossier UI Persistence After Closure (1 check):**
  - **8.1:** Administrator reloads closed dossier (`GET /admin/incident_detail.php?id={id}`) &rarr; `HTTP 200 OK`. Renders `cs-badge-closed`, verification notes in timeline, and dispatch/reassign officer action button is omitted for closed incidents. **(PASS)**
* **Group 9: Citizen Public Tracking Privacy Hygiene on Closed Incident (1 check):**
  - **9.1:** Citizen tracks closed incident via HTML (`track.php`) and REST API (`api/v1/track.php`) &rarr; Both return `HTTP 200 OK` with verified closure status while strictly omitting officer username (`officer_patel`), completion notes, and raw intake GPS coordinates. **(PASS)**

#### 3.7.8. Checkpoint D7 Verification Evidence Breakdown (5 Live HTTP Checks)
*Scope Notice: This integration verification confirms the 5 specific telemetry aggregations, Chart.js canvas rendering, Leaflet cluster map data feed, filter toolbar reactivity, SQL injection/XSS safety, and privacy-preserving spatial obfuscation scenarios tested live on local XAMPP Apache HTTPD (`http://localhost/OEP`). It does not constitute a full penetration test or an external security audit certification.*

* **Group 1: Analytics Route Authorization Controls (1 check):**
  - **1.1:** Unauthorized access denial verified: Unauthenticated `GET /admin/analytics.php` &rarr; `HTTP 302 Found` to login; Authenticated citizen `vesu_watcher` &rarr; `HTTP 403 Forbidden`; Field officer `officer_patel` &rarr; `HTTP 403 Forbidden`. **(PASS)**
* **Group 2: Admin Telemetry & Visual Canvas Rendering (1 check):**
  - **2.1:** Administrator access (`GET /admin/analytics.php`) &rarr; `HTTP 200 OK`. Renders all 5 KPI summary cards (*Total Issues*, *Active Open Queue*, *Remediated &bull; Closed*, *High / Critical Urgency*, *Avg Resolution Time*), all 4 Chart.js canvas elements (`volumeTrendChart`, `statusDoughnutChart`, `categoryBarChart`, `priorityBarChart`), and Leaflet cluster map container (`#analyticsClusterMap`). **(PASS)**
* **Group 3: Multi-Filter Toolbar Reactivity (1 check):**
  - **2.2:** Filter parameter reactivity verified: Category filter (`category_id=1`), Status filter (`status=closed`), Date range filter (`date_from=2026-01-01&date_to=2026-12-31`), Search keyword filter (`search=Water`), and inverted date range safe swap (`date_from=2026-12-31&date_to=2026-01-01`) all return `HTTP 200 OK` with safe normalized SQL parameters. **(PASS)**
* **Group 4: Malformed Input & SQL Injection / XSS Resilience (1 check):**
  - **2.3:** Dangerous input strings tested: SQL injection query (`category_id=1'+OR+'1'='1`), out-of-bounds priority (`min_priority=-50`), and XSS script tag (`search=<script>alert('xss')</script>`) handle safely &rarr; `HTTP 200 OK`, zero SQL syntax errors, zero unhandled exceptions, and XSS payload safely escaped in rendered HTML input. **(PASS)**
* **Group 5: Spatial Payload Privacy & Differential Privacy Distinction (1 check):**
  - **2.4:** Map analytics feed (`AnalyticsManager::getMapIncidents`) returns obfuscated centroids with ~150m fuzz radius. Raw intake coordinates (`raw_lat`, `raw_lng`), citizen names, phone numbers, email addresses, and tracking token hashes are strictly excluded. Mathematical analysis confirms coordinates are perturbed via bounded pseudorandom radial displacement ($52.5\text{m} \le r \le 150\text{m}$); claims of formal $(\epsilon, \delta)$-differential privacy are a documented misnomer for coordinate fuzzing. **(PASS)**

---

## 4. Accurate Status Totals & Completion Statistics

### 4.1. Checkpoint Status Breakdown

> **Clarification on Checkpoint Denominator vs. Automated Test Count:**  
> The project defines **83 distinct project-derived checkpoints** across three documented categories: 67 Phase 6–10 Regression Checkpoints, 8 Core Functional Capabilities, and 8 Demonstration Walkthroughs. The **87 automated test assertions** (which include 20 unit assertions across the rate-limiting and auto-promotion test suites) are tracked separately as backend regression test coverage and are not conflated with the checkpoint denominator.

| Checkpoint Category | Total Checkpoints | PASS | FAIL | NOT TESTED | NEEDS REVIEW |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Regression Checkpoints (Phases 6–10: P6.1 to P10.13)** | 67 | 67 | 0 | 0 | 0 |
| **Core Capabilities (C1 to C8)** | 8 | 6 | 0 | 0 | 2 |
| **Demonstration Walkthroughs (D1 to D8, `docs/demo_guide.md`)** | 8 | 8 | 0 | 0 | 0 |
| **Total Project Checkpoints** | **83** | **81** | **0** | **0** | **2** |

### 4.2. Completion Calculations

* **Automated Regression Suite Assertions (Separate Backend Metric):**
  $$\frac{\text{Automated Tests Passed}}{\text{Total Automated Tests}} \times 100 = \frac{87}{87} \times 100 = \mathbf{100.0\%}$$
  *(87 passing test assertions across 7 automated test suites: Phase 6 [14], Phase 7 [14], Phase 8 [14], Phase 9 [12], Phase 10 [13], Persistent Login Rate Limiter [10], and C3 Distinct-Report Auto-Promotion [10]).*

* **Conservative Overall Checkpoint Pass Rate (Counting Only PASS as Complete):**
  $$\frac{\text{Checkpoints Marked PASS}}{\text{Total Documented Checkpoints}} \times 100 = \frac{81}{83} \times 100 = \mathbf{97.59\%} \approx \mathbf{97.6\%}$$

* **Checkpoints Requiring Manual / Browser Review:**
  $$\frac{\text{Checkpoints Marked NEEDS REVIEW}}{\text{Total Documented Checkpoints}} \times 100 = \frac{2}{83} \times 100 = \mathbf{2.41\%} \approx \mathbf{2.4\%}$$

* **Checkpoints Marked FAIL or NOT TESTED:**
  $$\mathbf{0}\text{ (0.0\%) FAIL} \quad\vert\quad \mathbf{0}\text{ (0.0\%) NOT TESTED}$$

---

## 5. Five Highest-Priority Confirmed Gaps

1. **Gap 1: Missing Original Requirements Checklist & Legacy Phase 1–5 Test Suites**
   - **File References:** `docs/requirements.md` (0 bytes), `docs/project-audit.md` (0 bytes).
   - **Explanation:** The original syllabus/rubric checklist containing ~70–75 requirements was never committed to the workspace. Furthermore, automated test coverage exists strictly for Phases 6–10; Phases 1 through 5 (initial setup, database schema DDL, authentication, and basic intake) lack dedicated regression test scripts (`tests/test_phase1.php` through `tests/test_phase5.php` do not exist).

2. **Gap 2: Distinct-Report Promotion Lacks Automated Unit/Regression Test Coverage — [RESOLVED]**
   - **File References:** `core/IncidentManager.php` (`createIncidentFromReport`, lines 1015–1189), `admin/duplicates.php` (lines 144–146), `citizen/report.php` (lines 228–234), `tests/test_distinct_report_promotion.php`.
   - **Status:** **RESOLVED** (Dedicated automated regression suite created).
   - **Resolution Evidence:** Implemented comprehensive automated test suite `tests/test_distinct_report_promotion.php` (10 passed, 0 failed). Asserts standalone report promotion, report state update to `standalone`, single incident creation with initial status `reported` and `reports_count = 1`, reference generation `CS-SRT-YYYY-XXXXXX` and uniqueness, coordinate obfuscation within documented ~150m bound ($40\text{m} \le d \le 165\text{m}$), initial status history ('intake' &rarr; 'reported'), audit logging without PII or raw coordinates, transaction rollback integrity, duplicate promotion protection, and failure cases (non-existent report ID, missing coordinates fallback to Surat center).

3. **Gap 3: Absence of an Automated End-to-End Headless Browser UI Test Suite**
   - **File References:** `citizen/report.php`, `admin/duplicates.php`, `admin/analytics.php`, `assets/js/map.js`.
   - **Explanation:** There is no headless browser runner (e.g., Playwright, Puppeteer, or Panther) configured in the project. Complex frontend interactions—such as Leaflet interactive coordinate selection, Chart.js canvas rendering, password visibility toggling, and AJAX alert dismissals—rely entirely on manual browser execution without automated verification.

4. **Gap 4: Hard External CDN Dependencies for Mapping & Analytics (Offline Vulnerability)**
   - **File References:** `includes/header.php` (lines 23–29), `includes/footer.php` (lines 67–73), `admin/analytics.php` (line 720), `README.md` (line 238).
   - **Explanation:** The application loads Bootstrap CSS/JS, Leaflet.js, and Chart.js 4.4.2 directly from external CDNs (jsdelivr, unpkg, cdnjs). If evaluated offline without an active internet connection, maps and charts fail to render unless local vendor bundles are cached or vendored directly into `assets/vendor/`.

5. **Gap 5: Session-Scoped Login Rate Limiting Vulnerable to Cookie Clearance — [RESOLVED]**
   - **File References:** `core/Session.php` (`isLoginThrottled`, `recordFailedLogin`, `clearFailedLogins`, `getClientIp`), `auth/login.php`, `database/schema.sql`, `database/migrations/003_login_rate_limits.sql`, `tests/test_login_rate_limit.php`.
   - **Status:** **RESOLVED** (Migrated to persistent MySQL-backed rate limiting with bounded cost queries and IP spoofing resistance).
   - **Resolution Evidence:** Migrated failed-login tracking from temporary session variables to a dedicated, indexed `login_rate_limits` database table. Rate keys are hashed via SHA-256 (`hash('sha256', $identifier)`). Dual-level protection is enforced:
     1. Per-account/IP pair throttling (5 attempts per 5 minutes).
     2. IP-wide source throttling against distributed credential stuffing across usernames (20 attempts per 15 minutes).
     Attempts persist across session resets, cookie wipes, and session ID regeneration. Queries are strictly parameterized, indexed, and bounded in cost using `COUNT(*)` and `LIMIT 1 OFFSET ...` for $O(1)$ memory usage. Trusted IP resolution via `Session::getClientIp()` prevents header spoofing (`X-Forwarded-For`). Successful logins cleanly purge failed attempt records for that specific identifier without clearing unrelated limits. A robust fail-safe gracefully handles database outages, failing closed on authentication. Verified via automated test suite `tests/test_login_rate_limit.php` (10 passed, 0 failed).
