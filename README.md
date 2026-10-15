# CivicShield: Privacy-Preserving Civic Incident Intelligence Platform

> **Web Technology Open Elective Project (OEP)**  
> Semester 5 &bull; Academic Year 2026

CivicShield is a modern civic engagement and incident reporting platform engineered with **privacy-preserving mechanisms**. It empowers citizens to report public infrastructure issues, safety hazards, and environmental concerns anonymously, while providing municipal officers and administrators with actionable spatial intelligence without violating citizen privacy.

---

## 🌟 Key Platform Pillars

- **Zero-Identity Citizen Reporting:** Cryptographic pseudonyms and IP address hashing protect citizens from retaliation, surveillance, and location profiling.
- **Geo-Obfuscation on Public Maps:** Public maps render obfuscated centroids with an approximate 150m privacy fuzz radius so exact citizen pin locations are not exposed on public or officer map layers.
- **Consolidated Incident Pipeline:** Multiple citizen reports can link to one canonical incident. Candidate duplicates are algorithmically scored and manually confirmed—never auto-merged solely on proximity.
- **Geospatial Intelligence Map:** Dynamic incident visualization powered by **Leaflet.js** and **OpenStreetMap (OSM)** with responsive cluster indicators.
- **Role-Based Access Control (RBAC):** Distinct workflows and access layers for **Citizens**, **Department Officers**, and **Municipal Administrators**.
- **Hardened Architecture:** Native PHP 8.x + PDO implementation with prepared statements, strict CSRF token validation, central exception handling, and script-execution-shielded upload storage.

---

## 🛠️ Technology Stack

| Layer | Technologies |
| :--- | :--- |
| **Frontend UI** | HTML5, CSS3, Bootstrap 5.3, Bootstrap Icons |
| **Client Scripting** | JavaScript (ES6+), jQuery 3.7.1, Fetch API, AJAX, JSON |
| **Geospatial & Mapping** | Leaflet.js 1.9.4, OpenStreetMap (OSM) Tiles |
| **Server Backend** | PHP 8.2+ (Vanilla Native Architecture — Framework-Free) |
| **Data Persistence** | MySQL 8.x / MariaDB via PHP Data Objects (PDO) |
| **Development Stack** | Apache / XAMPP on Windows |

---

## 📂 Project Directory Structure

```text
CivicShield/
├── .env.example              # Template environment variables (no secrets)
├── .gitignore                # Excludes secrets, local logs, and uploads
├── README.md                 # Project documentation & local setup guide
├── index.php                 # Landing portal & demonstration map
├── track.php                 # Public tracking-token lookup UI
├── config/
│   ├── app.php               # Core application bootstrap, paths & error hooks
│   └── database.php          # Database configuration settings via Env
├── core/
│   ├── AnalyticsManager.php  # Admin analytics aggregations (Phase 9)
│   ├── Database.php          # PDO connection manager (Singleton)
│   ├── DuplicateDetector.php # Spatial/text duplicate candidate scoring
│   ├── Env.php               # Native PHP .env parser
│   ├── ErrorHandler.php      # Centralized error/exception handlers
│   ├── IncidentManager.php   # Admin/officer incident lifecycle
│   ├── PriorityScorer.php    # Transparent priority heuristic
│   ├── Response.php          # JSON response helpers
│   ├── Session.php           # Secure sessions, CSRF, login throttle
│   └── Uploader.php          # Image MIME/size validation & EXIF scrub
├── includes/                 # Shared header, navbar, footer, auth_guard
├── auth/                     # Login, register, logout
├── citizen/                  # Citizen dashboard & report submission
├── officer/                  # Officer dashboard, queue, work-order detail
├── admin/                    # Admin console, incidents, duplicates, analytics
├── api/v1/                   # health.php, track.php
├── assets/                   # CSS + JS (main.js, map.js)
├── database/
│   ├── schema.sql            # Full DDL (11 tables)
│   ├── seed.sql              # Surat demonstration data
│   └── migrations/           # Incremental ALTER scripts (001, 002, …)
├── docs/architecture.md      # Architecture, ERD, privacy & workflow docs
├── tests/                    # Phase 6–10 PHP CLI regression suites
├── logs/                     # Application logs (git-ignored)
└── uploads/                  # Attachments (.htaccess blocks PHP execution)
```

---

## 📋 Prerequisites

1. **XAMPP for Windows** (with PHP 8.1+ and MySQL 8 / MariaDB)
2. **Web Browser** (Chrome, Firefox, Edge, or Safari)
3. **Git** (optional, for version control)

---

## 🚀 Local Setup Instructions (XAMPP on Windows)

### Step 1: Place the Project in XAMPP
Ensure this folder is accessible inside your XAMPP web root:
- **Option A (Symlink / Directory in `htdocs`):**  
  Copy or symlink this folder to:  
  `C:\xampp\htdocs\OEP`
- **Option B (PHP Built-in Server):**  
  Run directly from this workspace via command line:
  ```powershell
  & "C:\xampp\php\php.exe" -S localhost:8000
  ```

### Step 2: Configure Environment Variables
Copy `.env.example` to create your local `.env` file:
```powershell
Copy-Item .env.example .env
```
Open `.env` in a text editor and adjust settings if your MySQL setup differs from standard XAMPP defaults:
```ini
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/OEP

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=civicshield_db
DB_USER=root
DB_PASS=
```

### Step 3: Initialize Database Schema & Seed Data
1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Run the initialization scripts in sequence via PowerShell (use absolute paths; `source` paths are relative to the MySQL client working directory):
   ```powershell
   cd "C:\Users\LENOVO\Desktop\Study\Semester 5\WT\OEP"

   # 1. Create database and 11 normalized tables
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/schema.sql"

   # 2. Populate Surat demonstration data (users, categories, incidents, reports, assignments)
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/seed.sql"

   # 3. Apply incremental migrations if upgrading an existing database
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/migrations/001_phase6_priority_breakdown.sql"
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/migrations/002_phase7_assignment_index.sql"
   ```
   Fresh installs from the current `schema.sql` already include `priority_breakdown` and do not require migration `001` for a brand-new database. Migration `002` remains safe to re-run (idempotent index check).

   *(Or import the same files in order via phpMyAdmin at [http://localhost/phpmyadmin](http://localhost/phpmyadmin)).*

### Step 4: Expose the Project under Apache (if not already in `htdocs`)
If the project lives outside `C:\xampp\htdocs`, create a directory junction:
```powershell
cmd /c mklink /J "C:\xampp\htdocs\OEP" "C:\Users\LENOVO\Desktop\Study\Semester 5\WT\OEP"
```
Alternatively run the PHP built-in server from the project root and set `APP_URL=http://localhost:8000` in `.env`:
```powershell
& "C:\xampp\php\php.exe" -S localhost:8000
```

### Step 5: Verify System Health
Open your browser and navigate to:
- **Application Portal:** `http://localhost/OEP/`
- **API Diagnostics Endpoint:** `http://localhost/OEP/api/v1/health.php`

---

## 🔐 Demonstration Accounts (Local Development Only)

All seed accounts use BCRYPT password hashes for local demonstration only. Do not reuse these passwords outside the academic demo environment.

| Role | Username | Password | Department / Description |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin_surat` | `AdminSurat@2026` | SMC Central Operations Command |
| **Officer** | `officer_patel` | `OfficerPatel@2026` | Roads & Bridges (West Zone) |
| **Officer** | `officer_desai` | `OfficerDesai@2026` | Drainage & Sewerage (South-West Zone) |
| **Officer** | `officer_shah` | `OfficerShah@2026` | Solid Waste Management (Central Zone) |
| **Citizen** | `vesu_watcher` | `VesuCivic@2026` | Registered Citizen (Vesu Ward) |
| **Citizen** | `adajan_civic` | `SuratCitizen@2026` | Registered Citizen (Adajan Ward) |
| **Citizen** | `varachha_safe` | `VarachhaSafe@2026` | Registered Citizen (Varachha Ward) |

### Anonymous Report Tracking Demonstration Tokens
- Water Pipeline Rupture (Report `REP-SRT-2026-0203`): `cstk_vesu_waterleak_7a9f2b1c4e6d`
- Duplicate Pothole Report (Report `REP-SRT-2026-0202`): `cstk_adajan_pothole_dup_3e2d1c0b`
- Overflowing Dumpster (Report `REP-SRT-2026-0204`): `cstk_rander_garbage_4f5e6d7c8b9a`

---

## 🧩 Role Capabilities (Summary)

| Capability | Citizen / Guest | Officer | Admin |
| :--- | :---: | :---: | :---: |
| Submit / track reports | Yes | No | No |
| Duplicate candidate review | No | No | Yes |
| Assign / reassign incidents | No | No | Yes |
| Field status updates (`assigned` → `in_progress` → `resolved`) | No | Yes (own assignments) | Yes |
| Close / reject / reopen | No | No | Yes |
| Analytics dashboard | No | No | Yes |

**Canonical incident statuses (schema + workflow):**  
`reported`, `triaged`, `assigned`, `in_progress`, `resolved`, `closed`, `rejected`.

---

## 🧪 Automated Tests

Run suites with the XAMPP PHP CLI (requires MySQL running and `civicshield_db` initialized):

```powershell
cd "C:\Users\LENOVO\Desktop\Study\Semester 5\WT\OEP"

& "C:\xampp\php\php.exe" tests/test_phase6.php
& "C:\xampp\php\php.exe" tests/test_phase7.php
& "C:\xampp\php\php.exe" tests/test_phase8.php
& "C:\xampp\php\php.exe" tests/test_phase9.php
& "C:\xampp\php\php.exe" tests/test_phase10.php

# Optional syntax check on changed PHP files
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
  & "C:\xampp\php\php.exe" -l $_.FullName
}
```

---

## 🛡️ Security & Privacy Mechanisms

- PDO prepared statements for SQL injection resistance.
- Context-aware `htmlspecialchars` output escaping for XSS mitigation.
- CSRF tokens on state-changing POST forms and AJAX headers.
- Session ID regeneration on login; HttpOnly cookies; inactivity timeout.
- Login attempt throttling (session-based).
- Upload validation (MIME via `finfo`, size limits, random stored names, EXIF scrubbing when GD is available); `uploads/.htaccess` blocks script execution.
- Tracking tokens stored only as SHA-256 hashes; public track API returns no contact enclave or raw GPS.
- `citizen_contacts` is never queried by public map/analytics endpoints.
- Geo privacy on maps uses **obfuscated centroids + fuzz radius**, not a formal differential-privacy (ε) proof.

Set `APP_DEBUG=false` and replace `CSRF_SECRET_KEY` before any non-demo deployment.

---

## 🎬 Demonstration Checklist (Citizen → Admin → Officer)

1. **Citizen:** Sign in as `vesu_watcher` → open Citizen Portal → submit a nearby report (or reuse a seed tracking token on Home / Track).
2. **Admin:** Sign in as `admin_surat` → Duplicate Review (confirm or mark distinct) → Incidents ledger → assign to `officer_patel` → open Analytics and verify KPIs/charts/map.
3. **Officer:** Sign in as `officer_patel` → open assigned queue → start work (`in_progress` with notes) → mark `resolved` with notes.
4. **Admin:** Close the remediating incident with a verification note; confirm status history and that citizen tracking still works without exposing contacts.
5. **Negative checks:** Officer cannot open another officer’s incident ID; citizen cannot open `/admin/` or `/officer/`.

---

## ⚠️ Known Limitations

- The home-page Leaflet map currently renders **seeded demonstration markers** (not a live DB feed). Admin analytics map uses live obfuscated centroids.
- Marking a duplicate candidate as **distinct** updates the report status but does **not** auto-create a new consolidated `incidents` row; demo incidents primarily come from seed data and confirmed consolidations.
- Formal mathematical differential privacy (ε-DP) is **not** claimed; spatial protection is centroid obfuscation / fuzz radius visualization.
- Login throttling is session-scoped (sufficient for academic demo; not a distributed rate limiter).
- Chart.js and Leaflet load from CDNs; analytics charts fall back to a readable notice if Chart.js is unreachable.
- Resolution evidence verification UI is schema-backed from earlier design; primary Phase 7–8 closure path uses status transitions and notes.

---

## 📚 Further Reading & Demo Resources

- **Academic Demonstration Guide:** [`docs/demo_guide.md`](docs/demo_guide.md) — Step-by-step evaluation script, demo accounts, verification checklists, and presentation flow.
- **System Architecture & ERD:** [`docs/architecture.md`](docs/architecture.md) — Comprehensive technical architecture, database schemas, duplicate/priority heuristics, RBAC matrix, and Phase 6–10 workflow specifications.

