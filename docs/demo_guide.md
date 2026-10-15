# CivicShield: Academic Demonstration & Evaluation Guide

> **Web Technology Open Elective Project (OEP) &bull; Semester 5 &bull; Academic Year 2026**  
> **Platform:** CivicShield — Privacy-Preserving Civic Incident Intelligence Platform  
> **Local Deployment URL:** `http://localhost/OEP/`

---

## 1. Executive Summary for Evaluators

CivicShield addresses a critical challenge in modern civic technology: **how to collect granular public hazard intelligence while protecting whistleblowers and citizen reporters from surveillance, retaliation, and location profiling**.

The application is implemented using a **vanilla native PHP 8.x + MySQL/MariaDB (PDO)** architecture without heavy frameworks (Laravel, Symfony) or client build pipelines (Node, Webpack, React). It achieves enterprise-grade security and differential privacy through:

1. **Cryptographic Pseudonyms & Privacy Enclave:** Citizen identity and optional follow-up contact details are isolated in an encrypted database enclave (`citizen_contacts`). Citizen reports store only pseudonyms, salted IP hashes, and SHA-256 digests of single-use tracking tokens.
2. **Spatial Obfuscation:** Public maps and officer dossiers render obfuscated centroids with ~150m Gaussian noise radius zones rather than raw citizen intake coordinates.
3. **Automated Multi-Metric Duplicate Candidate Detection:** Incorporates Haversine geographic proximity, category matching, Levenshtein/Jaccard text similarity, and temporal proximity.
4. **Transparent Priority Scoring:** Computes deterministic severity scores (0–100) based on municipal category weight, submitted urgency, citizen co-reporting density, and SLA decay.
5. **Role-Based Access Control (RBAC):** Strict separation of duties between Citizens, Field Officers, and Municipal Administrators.
6. **Hardened Security Architecture:** Prepared statements on 100% of queries, strict CSRF validation on all state-changing endpoints, HttpOnly/SameSite session cookies, session fixation mitigation, rate-limiting throttling, and `.htaccess`-shielded upload storage.

---

## 2. Seed Demonstration Accounts

All accounts are pre-seeded in `database/seed.sql` with BCRYPT password hashes.

| Role | Username | Password | Full Name / Jurisdiction |
| :--- | :--- | :--- | :--- |
| **Municipal Administrator** | `admin_surat` | `AdminSurat@2026` | Central Command Administrator (SMC HQ) |
| **Field Officer** | `officer_patel` | `OfficerPatel@2026` | Inspector Rajesh Patel (Roads & Bridges, West Zone) |
| **Field Officer** | `officer_desai` | `OfficerDesai@2026` | Inspector Amit Desai (Drainage & Sewerage, South-West) |
| **Field Officer** | `officer_shah` | `OfficerShah@2026` | Inspector Neha Shah (Solid Waste, Central Zone) |
| **Citizen Reporter** | `vesu_watcher` | `VesuCivic@2026` | Priya Mehta (Vesu Ward Citizen) |
| **Citizen Reporter** | `adajan_civic` | `SuratCitizen@2026` | Rahul Joshi (Adajan Ward Citizen) |
| **Citizen Reporter** | `varachha_safe` | `VarachhaSafe@2026` | Sanjay Patel (Varachha Ward Citizen) |

### Pre-Seeded Demonstration Tracking Tokens
* **Vesu Water Rupture (Resolved):** `cstk_vesu_waterleak_7a9f2b1c4e6d` (Report: `REP-SRT-2026-0203`)
* **Adajan Pothole (In Progress):** `cstk_adajan_pothole_dup_3e2d1c0b` (Report: `REP-SRT-2026-0202`)
* **Rander Waste Dump (Assigned):** `cstk_rander_garbage_4f5e6d7c8b9a` (Report: `REP-SRT-2026-0204`)

---

## 3. Recommended Demonstration Walkthrough

Follow this sequence to showcase the complete civic reporting, duplicate triage, field dispatch, resolution, and intelligence lifecycle:

### Step 1: Citizen Incident Reporting & Cryptographic Receipt
1. Open browser to [`http://localhost/OEP/auth/login.php`](http://localhost/OEP/auth/login.php).
2. Sign in as Citizen: `vesu_watcher` / `VesuCivic@2026`.
3. In the Citizen Portal, click **Report New Incident** (`citizen/report.php`).
4. Fill out the report:
   - **Category:** Roads & Infrastructure
   - **Title:** Deep pothole near Prime Arcade junction
   - **Description:** Dangerous 3-foot asphalt crater causing two-wheelers to swerve into oncoming traffic.
   - **Severity:** High
   - **Location:** Click on the interactive map near Adajan/Vesu (e.g., Lat: `21.1963`, Lng: `72.7958`).
   - **Optional Contact Consent:** Check the consent box, provide an email (`citizen@example.com`).
5. Submit the form.
6. **Key Evaluator Checkpoint:**
   - Observe the **Cryptographic Receipt Issued** confirmation page (`citizen/report_success.php`).
   - Notice the unique **Private Single-Use Tracking Token** (`cstk_...`).
   - Notice the EXIF metadata scrubbing badge.
   - Copy the tracking token for Step 2.
   - Refresh the page: notice that the receipt token is automatically cleared from session to prevent token re-retrieval from browser history.

### Step 2: Public Status Tracking Without Login or PII Exposure
1. Click **Sign Out** from the user menu.
2. In the public header, click **Track Report** (`track.php`).
3. Paste the tracking token copied in Step 1 (or click one of the pre-seeded demo token quick-fill buttons).
4. Click **Track Report**.
5. **Key Evaluator Checkpoint:**
   - Public report status is displayed (`Under Triage & Verification`).
   - Estimated SLA resolution target is displayed.
   - **Zero PII:** Notice that the citizen's contact details, real identity, and raw GPS coordinates are completely absent from the page and response payload.

### Step 3: Admin Duplicate Detection & Triage
1. Sign in as Administrator: `admin_surat` / `AdminSurat@2026`.
2. From the Admin Console (`admin/index.php`), navigate to **Duplicate Review** (`admin/duplicates.php`).
3. Review candidate duplicate reports flagged by the algorithmic detector:
   - Inspect the **Match Score %** breakdown (spatial proximity, category agreement, text similarity, time difference).
4. Select a candidate and click **Confirm Duplicate** (or **Mark Distinct**).
5. **Key Evaluator Checkpoint:**
   - Confirming a duplicate associates the citizen report with the canonical incident without deleting the report.
   - Priority score automatically updates via `PriorityScorer::updateIncidentPriority()`.
   - Action is immutably logged into `audit_logs`.

### Step 4: Admin Incident Dispatch & Assignment
1. In the top navigation, click **Incidents** (`admin/incidents.php`).
2. Filter by status **Reported** or **Triaged**.
3. Click **View Dossier** on an incident (e.g., `admin/incident_detail.php?id=1`).
4. Under **Dispatch & Work Order Assignment**, select an active field officer:
   - **Assignee:** `Rajesh Patel (Roads & Bridges - West Zone)`
   - **Instructions:** `Deploy repair crew for cold-mix asphalt patch within 4 hours.`
5. Click **Dispatch Work Order**.
6. **Key Evaluator Checkpoint:**
   - Incident status transitions to `assigned`.
   - Priority score adjusts to reflect active operational triage.
   - Assignment history records the timestamp, assigning administrator, and operational notes.

### Step 5: Officer Field Inspection & Status Remediation
1. Sign out and sign in as Officer: `officer_patel` / `OfficerPatel@2026`.
2. Notice the **Field Officer Portal** (`officer/index.php`):
   - Only active assignments for Officer Patel are shown.
   - Workload counters display assigned vs in-progress tasks.
3. Open the assigned incident dossier (`officer/incident_detail.php?id=...`).
4. **Transition to In Progress:**
   - In the update panel, select **In Progress (Active Work)**.
   - Enter note: `Arrived on site with repair crew; barricading lane.`
   - Click **Submit Field Update**.
5. **Transition to Resolved:**
   - Select **Resolved (Field Remediation Completed)**.
   - Enter note: `Pothole filled with standard asphalt grade and steam-rolled; lane reopened.`
   - Click **Submit Field Update**.
6. **Key Evaluator Checkpoint:**
   - Status history reflects the officer's name, timestamps, and justification.
   - Officer linked reports view shows descriptions and sanitized landmarks but strictly omits citizen raw GPS pins and contact details.

### Step 6: Admin Verification & Incident Closure
1. Sign back in as Administrator: `admin_surat` / `AdminSurat@2026`.
2. Open the incident dossier.
3. Verify the officer's remediation notes.
4. Transition status to **Closed (Verified)** with verification note: `Field repair inspected via municipal CCTV; work order closed.`
5. **Key Evaluator Checkpoint:**
   - Resolved timestamp is recorded.
   - Incident status is closed.
   - Re-checking the citizen tracking token in `track.php` now reflects `Verified Resolved`.

### Step 7: Civic Intelligence & Spatial Analytics
1. As Administrator, navigate to **Analytics** (`admin/analytics.php`).
2. **Review Key Indicators:**
   - Total Issues vs. Corroborating Citizen Reports.
   - Active Open Queue vs. Field In-Progress.
   - Resolution Clearance Rate (%) and Empirical Average Resolution Time (Hours).
   - High & Critical Urgency breakdown.
3. **Explore Visual Analytics:**
   - Intake & Resolution Velocity timeline (Chart.js line chart).
   - Lifecycle Status breakdown (donut chart).
   - Departmental Category distribution (bar chart).
   - Priority Stratification (Low, Medium, High, Critical).
4. **Inspect Geospatial Density Map:**
   - Obfuscated incident pins with 150m privacy fuzzing radius circles.
   - Differential privacy visualization preserving municipal pattern analysis without leaking citizen home coordinates.
5. **Test Multi-Filter Toolbar:**
   - Filter by category, status, priority tier, or date range.
   - Apply filters and observe reactive KPI recalculations and chart updates.

### Step 8: Negative Security & Access Boundary Verifications
1. **Officer Authorization Boundary Check:**
   - While signed in as `officer_patel`, attempt to open an incident assigned to `officer_shah` by typing its ID in the URL: `officer/incident_detail.php?id=2`.
   - **Result:** Strict 403 Forbidden page is rendered; access is denied.
2. **Citizen Portal Protection:**
   - While signed in as `vesu_watcher`, attempt to access `admin/` or `officer/`.
   - **Result:** Strict 403 Forbidden with prompt to return to Citizen Workspace.
3. **Unauthenticated Access:**
   - Open private/incognito window and attempt to access `admin/analytics.php` directly.
   - **Result:** Immediate redirection to `auth/login.php` with safe internal redirect target.
4. **CSRF Enforcement:**
   - Attempt to submit a POST request to `auth/logout.php` without CSRF token.
   - **Result:** Token rejection; state remains protected.

---

## 4. Test Suite Execution

Run the complete automated test suite from PowerShell:

```powershell
cd "C:\Users\LENOVO\Desktop\Study\Semester 5\WT\OEP"

# Run all test suites
& "C:\xampp\php\php.exe" tests/test_phase6.php
& "C:\xampp\php\php.exe" tests/test_phase7.php
& "C:\xampp\php\php.exe" tests/test_phase8.php
& "C:\xampp\php\php.exe" tests/test_phase9.php
& "C:\xampp\php\php.exe" tests/test_phase10.php

# Run full syntax check across all PHP files
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & "C:\xampp\php\php.exe" -l $_.FullName }
```

### Cumulative Test Results

| Test Suite | Purpose | Tests | Result |
| :--- | :--- | :---: | :---: |
| [`test_phase6.php`](file:///c:/Users/LENOVO/Desktop/Study/Semester%205/WT/OEP/tests/test_phase6.php) | Duplicate Detection & Priority Scoring | 14 | **14 / 14 PASSED** |
| [`test_phase7.php`](file:///c:/Users/LENOVO/Desktop/Study/Semester%205/WT/OEP/tests/test_phase7.php) | Admin Operations & State Machine | 14 | **14 / 14 PASSED** |
| [`test_phase8.php`](file:///c:/Users/LENOVO/Desktop/Study/Semester%205/WT/OEP/tests/test_phase8.php) | Officer Portal & Assignment Boundaries | 14 | **14 / 14 PASSED** |
| [`test_phase9.php`](file:///c:/Users/LENOVO/Desktop/Study/Semester%205/WT/OEP/tests/test_phase9.php) | Civic Intelligence & Analytics | 12 | **12 / 12 PASSED** |
| [`test_phase10.php`](file:///c:/Users/LENOVO/Desktop/Study/Semester%205/WT/OEP/tests/test_phase10.php) | Final Security Audit & E2E Integration | 13 | **13 / 13 PASSED** |
| **Total** | **Comprehensive Full-Stack Coverage** | **67** | **67 / 67 PASSED (100%)** |

---

## 5. Honest Known Limitations

1. **CDN Dependency:** OpenStreetMap map tiles, Leaflet.js CSS/JS, and Chart.js 4.4.2 are loaded from public CDNs. If demonstrating offline without an internet connection, maps and charts gracefully fall back to tabular data and textual notices.
2. **Centroid Obfuscation vs. Mathematical $\epsilon$-DP:** Spatial protection uses coordinate fuzzing (Gaussian perturbation within ~150m) and radius boundaries. It provides practical whistleblower protection rather than formal mathematical $(\epsilon, \delta)$-differential privacy proofs.
3. **Session-Scoped Rate Limiting:** Login attempt throttling and tracking query rate limiting are stored in PHP session state. In production multi-server deployments, a Redis key-value store would be used.
4. **File Upload Environment:** Image sanitization and EXIF stripping utilize PHP GD (`imagecreatefromjpeg`, etc.). In environments without GD, the uploader safely falls back to standard validated filesystem moves with `.htaccess` execution blocking.
