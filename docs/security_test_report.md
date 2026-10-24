# CivicShield Security Test Report

**Test date:** October 19, 2026  
**Environment:** Local XAMPP — `http://localhost/OEP`  
**Project:** CivicShield — Privacy-Preserving Civic Incident Intelligence Platform

## 1. Automated Test Results

| Test suite | Passed | Failed |
|---|---:|---:|
| Phase 6 | 14 | 0 |
| Phase 7 | 14 | 0 |
| Phase 8 | 14 | 0 |
| Phase 9 | 12 | 0 |
| Phase 10 | 13 | 0 |
| **Total** | **67** | **0** |

**Result:** All 67 reported automated tests passed.

## 2. Manual Security Test Results

The following results are based on the developer's report of manual testing. Most screenshots were not retained, so these results have not been independently reproduced for this report.

| # | Security check | Reported result |
|---:|---|---|
| 1 | Authentication and login | PASS — reported by developer |
| 2 | Role-based access control | PASS — reported by developer |
| 3 | IDOR / incident privacy | PASS — reported by developer |
| 4 | SQL injection | PASS — reported by developer |
| 5 | Cross-site scripting (XSS) | PASS — reported by developer |
| 6 | CSRF protection | PASS — reported by developer |
| 7 | File upload validation | PASS — reported by developer |
| 8 | Session security | PASS — reported by developer |
| 9 | Input validation and data exposure | PASS — reported by developer |
| 10 | Abuse and rate limiting | PASS — reported by developer |

## 3. Available Supporting Evidence

- A login page displayed an invalid-credentials message.
- A login attempt displayed a temporary cooldown of 190 seconds after repeated failures.
- Browser DevTools showed the application's cookies, including `PHPSESSID`.
- The incident form rejected a file format outside the permitted JPEG, PNG, and WebP formats.

These screenshots provide partial evidence for authentication, rate limiting, session handling, and upload validation. They do not independently establish every security property.

## 4. Evidence Limitations

Most manual test screenshots and request/response records were not saved. The manual results above are recorded as reported by the developer, rather than independently verified findings.

## 5. Follow-up Actions

1. Capture screenshots or sanitized request/response evidence during future security regression tests.
2. Repeat relevant tests after changes to authentication, authorization, sessions, uploads, or incident workflows.
3. Keep automated and manual test results separate.
4. Record any vulnerabilities, reproduction steps, remediation, and retest results.
5. Do not treat passing tests as proof that the application is free of all security vulnerabilities.

## 6. Overall Assessment

The five reported automated test suites passed, with 67 passes and zero failures. The developer also reports passing all ten listed manual security checks. Because most manual evidence was not retained, the manual results should be treated as self-reported and not as an independent security audit.

**Report status:** Initial security test record; further evidence collection recommended.


## 7. Focused Follow-up — October 24, 2026

The project has since received focused changes to the public health response, versioned tracking request method, tracking-page token handling and redirect sanitization. The developer reported the following browser checks as working: role-specific login redirects, rejection of unsafe external redirect targets, citizen reporting, valid/invalid tracking-token behavior, `405 Method Not Allowed` for GET to `api/v1/track.php`, and severity/badge rendering. PHP syntax checks were reported passing for `core/Session.php`, `auth/login.php`, `api/v1/health.php`, `api/v1/track.php` and `track.php`.

These are developer-reported focused checks; this update does **not** claim that the nine scripts currently listed in `docs/testing.md` were all rerun together on October 24. The 67-pass total above remains the historical Phase 6–10 result recorded on October 19. This report is not an independent penetration test or proof that the application is vulnerability-free.
