# CivicShield API Reference

This document describes the implemented versioned endpoints and the application's PHP page workflows. It is not a promise of a separate framework-based REST service.

## API endpoints

### `GET /api/v1/health.php`

Returns a minimal health status and timestamp. Database failures return a generic degraded response; diagnostics should remain in server logs rather than the public payload.

### `POST /api/v1/track.php`

Accepts a tracking token from a JSON or form-encoded POST body and returns the public lifecycle status for the matching report. GET requests are rejected with `405 Method Not Allowed`. The endpoint sends no-store and no-referrer headers and is designed not to return raw coordinates or citizen contact details.

PowerShell example:

```powershell
$body = @{ token = "YOUR_TRACKING_TOKEN" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "http://localhost/OEP/api/v1/track.php" -ContentType "application/json" -Body $body
```

Replace the URL base according to local `APP_URL`. Never commit real tracking tokens.

## Page-based workflows

| Area | Entry point | Purpose |
|---|---|---|
| Authentication | `auth/login.php`, `auth/register.php`, `auth/logout.php` | Login, account creation, logout |
| Citizen | `citizen/index.php`, `citizen/report.php`, `citizen/report_success.php` | Dashboard, reporting, report receipt |
| Public tracking | `track.php` | Token-based status lookup |
| Officer | `officer/index.php`, `officer/incidents.php`, `officer/incident_detail.php` | Assigned work and field updates |
| Administrator | `admin/index.php`, `admin/incidents.php`, `admin/duplicates.php`, `admin/analytics.php` | Queue, duplicate review, dispatch and analytics |

Most application operations are handled by authenticated PHP pages and their POST forms rather than a standalone REST API.

## Security expectations

- Send state-changing requests through the application forms and valid CSRF tokens.
- Enforce authentication and role authorization server-side.
- Do not expose raw intake GPS, citizen contact information, password hashes or token hashes in public payloads.
- Public tracking at the versioned endpoint is POST-only.
- Error responses must not disclose database connection strings or exception details.

## Scope note

This reference documents the known endpoints. It is not an OpenAPI specification and does not imply that placeholder files under `api/` are complete services.
