# CivicShield Database Design

## Source of truth

The authoritative schema is [`database/schema.sql`](../database/schema.sql). Demonstration records are in [`database/seed.sql`](../database/seed.sql). Incremental changes are under [`database/migrations/`](../database/migrations/).

The schema targets MySQL 8 / MariaDB with InnoDB and utf8mb4. Use the current schema for a fresh local database; apply migrations only when upgrading a database that predates those changes.

## Main entities

| Table | Responsibility |
|---|---|
| `users` | Authentication identity, role and account status |
| `incident_categories` | Category, owning department, default severity and SLA |
| `incidents` | Consolidated civic issues, obfuscated public location and lifecycle |
| `incident_reports` | Individual citizen submissions and tracking-token hashes |
| `citizen_contacts` | Optional follow-up contact information separated from public incident summaries |
| `incident_attachments` | Uploaded file metadata and integrity information |
| `incident_duplicate_candidates` | Candidate-match scores and administrator decisions |
| `incident_assignments` | Officer work orders and assignment lifecycle |
| `resolution_evidence` | Officer completion evidence and administrator verification |
| `incident_status_history` | Lifecycle transition history and reasons |
| `audit_logs` | Administrative and security activity records |
| `login_rate_limits` | Persistent authentication-throttling records |

Verify exact columns and constraints against `database/schema.sql`; this summary intentionally avoids duplicating DDL that can drift.

## Relationships and integrity

A canonical `incidents` record can be linked to multiple `incident_reports`. Duplicate detection creates candidates for human review; location similarity alone must not automatically merge reports. Assignments associate incidents with field officers, and resolution evidence is linked to an assignment/incident. Status history and audit rows preserve traceability.

## Fresh database initialization

With XAMPP MySQL running, from the repository root:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/schema.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source database/seed.sql"
```

Adjust the MySQL client path and credentials for your installation. Do not run seed data against a database containing valuable records without reviewing the script's effects.

## Migrations

Migration filenames describe the change introduced. Review each migration before applying it to a non-disposable database. A fresh install should begin with the current schema rather than blindly replaying every historical migration.
