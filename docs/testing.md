# CivicShield Testing Guide

## Environment

The project is a PHP/MySQL application intended for local testing with XAMPP or a compatible stack. Previous test notes recorded PHP CLI 8.2.12 on Windows; record the actual version used for each new run.

## Regression scripts

Run from the repository root using PHP on PATH:

```powershell
php .\tests\test_phase6.php
php .\tests\test_phase7.php
php .\tests\test_phase8.php
php .\tests\test_phase9.php
php .\tests\test_phase10.php
php .\tests\test_login_rate_limit.php
php .\tests\test_distinct_report_promotion.php
php .\tests\test_resolution_evidence_workflow.php
php .\tests\test_resolution_guard.php
```

These are separate CLI scripts. Some need a database configured with expected seed/test records. Read each script before running it against any database that contains valuable data.

## PHP syntax checks

```powershell
php -l .\core\Session.php
php -l .\api\v1\health.php
php -l .\api\v1\track.php
php -l .\track.php
php -l .\auth\login.php
```

For a broader syntax pass:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    php -l $_.FullName
}
```

Exclude vendored, generated or intentionally inactive files as appropriate.

## Manual smoke checklist

- Login redirects each role to the correct area.
- Redirect parameters cannot send users to an untrusted external site.
- Citizen reporting completes and shows a receipt.
- Public tracking accepts valid tokens and fails safely for invalid ones.
- `GET /api/v1/track.php` returns 405.
- Officers can only act on their assigned work.
- Administrator duplicate review, assignment, analytics and closure flows function.
- Upload validation rejects disallowed file types.
- Public responses omit raw intake coordinates and contact information.

## Reporting results

Record command, environment, date, pass/fail counts and relevant output. Keep automated results distinct from developer-reported browser checks. Syntax validation is not a functional test or security assessment.
