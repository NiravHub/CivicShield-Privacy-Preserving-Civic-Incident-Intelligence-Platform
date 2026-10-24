# CivicShield Demonstration Scenario

This scenario demonstrates the citizen-to-municipality workflow using local seeded data.

## Preparation

1. Follow [Deployment Guide](deployment.md) to configure `.env`, MySQL/MariaDB and PHP.
2. Load `database/schema.sql` and `database/seed.sql` into a local development database.
3. Start the local server and open the application.
4. Use only seed credentials and tokens for this demonstration. Never reuse them in production.

## Walkthrough

### 1. Citizen report
Log in as a demo citizen, open the citizen portal, submit a civic issue with category, title, description, severity and location, and review the success receipt. Keep the tracking token for the status-check step.

### 2. Public tracking
Sign out and open the public tracking page. Submit the token to retrieve lifecycle status without exposing citizen contact details or original intake coordinates. The versioned tracking API accepts POST, not GET.

### 3. Duplicate triage
Log in as administrator and open Duplicate Review. Inspect the score details, then explicitly confirm a genuine duplicate or mark the candidate distinct. The score supports judgment; it is not a substitute for human review.

### 4. Assignment
Open the incident ledger and assign an incident to a field officer with operational instructions. Confirm assignment and status-history entries.

### 5. Officer action
Log in as the assigned officer, open the assigned incident, record a field update and progress through permitted lifecycle transitions. Authorization should prevent one officer from modifying another officer's assignment.

### 6. Resolution and closure
Submit resolution details/evidence as supported by the current workflow. The administrator reviews the outcome and performs the authorized verification/closure step. Review status history and public tracking after the state changes.

### 7. Analytics
Open the administrator analytics dashboard and inspect KPIs, charts and map summaries. Public-facing map payloads should use obfuscated locations and exclude contact details and raw intake coordinates.

## Negative checks

- Unauthenticated users cannot enter staff-only pages.
- Citizens cannot access administrator or officer workflows.
- An officer cannot act on another officer's assignment.
- Missing or invalid CSRF tokens reject state-changing requests.
- Unsupported upload types are rejected.
- Invalid tracking tokens fail safely.
- `GET /api/v1/track.php` returns `405 Method Not Allowed`.

See [demo_guide.md](demo_guide.md) for a detailed evaluator script.
