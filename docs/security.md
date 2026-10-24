# CivicShield Security Notes

## Implemented controls

The codebase includes defense-in-depth controls; verify details in source before any production deployment.

- **Authentication and RBAC:** session/authentication helpers and role guards protect citizen, officer and administrator workflows.
- **CSRF:** state-changing forms validate anti-CSRF tokens.
- **Database access:** PDO and parameterized statements are used to reduce SQL injection risk.
- **Session handling:** cookie options, session regeneration and authentication throttling are handled in `core/Session.php` and the login flow.
- **Redirect validation:** `Session::sanitizeRedirect()` limits redirects to safe local or same-host paths according to the configured base URL.
- **Input/output handling:** validation and context-aware output escaping are used in application workflows.
- **Uploads:** `core/Uploader.php` validates file uploads; `uploads/.htaccess` is intended to prevent server-side script execution in upload storage.
- **Public health endpoint:** `api/v1/health.php` returns minimal status and avoids exposing raw database diagnostics.
- **Public tracking endpoint:** `api/v1/track.php` is POST-only and is designed not to expose raw intake GPS or citizen contact details.
- **Traceability:** audit and status-history records support review of administrative actions.

## Local verification

From the repository root:

```powershell
php -l .\core\Session.php
php -l .\api\v1\health.php
php -l .\api\v1\track.php
php -l .\track.php
php -l .\auth\login.php
```

Run the regression scripts described in [testing.md](testing.md).

## Production hardening

- Set `APP_DEBUG=false`.
- Replace the example `CSRF_SECRET_KEY` with a high-entropy secret.
- Use HTTPS and secure cookie settings behind correctly configured proxies.
- Restrict database permissions and test secure backups/restores.
- Restrict allowed origins and externally loaded scripts to deployment needs.
- Keep PHP, MySQL/MariaDB and browser libraries patched.
- Never publish credentials, real tracking tokens, citizen contact details or raw location records.
- Arrange an independent security review before handling real citizen data.

## Scope disclaimer

Passing syntax checks, regression suites and selected manual scenarios is not a penetration test or proof that the application is vulnerability-free. See [security_test_report.md](security_test_report.md).
