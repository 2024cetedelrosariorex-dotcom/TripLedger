# Verification report — 24 October 2026

## Actual results

The application changes are implemented locally. This is not a declaration of production readiness: live Gemini connectivity, a second LAN workstation, physical printing and Ubuntu deployment still need the checks below.

| Check actually executed | Result |
| --- | --- |
| Existing database verification before changes | 14 passed, 0 failed |
| Final `php test/run.php` integration suite | **81 passed, 0 failed** |
| Final `php test/verify.php` against existing database | **20 passed, 0 failed** |
| PHP syntax checks across app, public, scripts, tests and migrations | Passed |
| JavaScript syntax checks, including browser harness | Passed |
| Headless Chrome browser checks (included in 81) | 13 passed; no console/resource errors in tested flows |
| Fresh installation and legacy-schema upgrade/rerun | Passed in separate databases; legacy ticket/counter/audit preserved |
| Initial Administrator CLI setup and refusal of a second Admin | Passed in separate database |
| Concurrent issuance | Six simultaneous requests succeeded without duplicate numbers |
| Concurrent repeated submission | Four requests produced one ticket |
| Concurrent returns and reversals | One accepted action, three rejected competing actions each |
| Demonstration backup/restore using matching MySQL 8 client | All nine tables' rows matched |
| Pre-upgrade existing-database backup/restore | All seven original tables matched in a separate restore database |
| Existing-data preservation through each migration run | Full ordered-row fingerprints unchanged for all seven original tables |
| Final live/fresh schema column metadata comparison | Match (types, nullability, defaults and generated/update metadata) |
| Local Apache access checks | Login 200; app configuration, schema, test artifacts and `.git/HEAD` 403 |

The starting and backup-snapshot totals were **10 issued, 8 returned, 2 outstanding**. The final read-only check shows **10 issued, 9 returned, 1 outstanding**, with **three active Authorized Staff and one active Administrator**. A non-test account added a `RETURN_RECORDED` activity during this work; it was preserved. No demonstration tickets exist in the live database. Each migration run separately confirmed that all pre-existing rows were unchanged by that migration. No test transactions were inserted into the existing database. Versions `001_session_submission`, `002_ai_settings` and `003_timestamp_constraints` were applied; rerunning is a no-op. Gemini switches default to disabled.

The pre-upgrade backup is at:

```text
C:\Users\Acer\AppData\Local\Temp\TripLedger-private-backups\tripledger-before-upgrade-20261024-150008.sql
```

SHA-256: `1c5cf1619c86c0010c29a40d475853c39ee6e82d704ab65ab748d7de4259f9b9`.

This is a private temporary local copy, **not** an off-server retention arrangement. Move a verified copy to the client's protected backup storage. The exact restore database name and final test database identifiers are recorded in ignored, HTTP-blocked `test/.runtime/last-live-upgrade.json` and `last-run.json`. The live-backup restore contains real existing data; protect it accordingly. Test/restore databases were retained, not dropped. No deployment, push or publishing occurred.

## Coverage and limits

The suite executes actual PHP HTTP requests and database writes in isolated databases. It checks every protected route's Staff/Admin/anonymous boundary, CSRF, login redirects, logout, Admin account lifecycle and immediate session revocation; issuance/replay, numbering transitions/backdates, correction validation, return dates, Before/After values, reversal history and stale-return protection; filtered report/CSV content, empty states and pagination; AI settings persistence, model allowlisting, role enforcement and local fallback.

Gemini quota, malformed/empty/incomplete responses and hidden thought filtering were tested deterministically. Connection failure and timeout were exercised through the actual cURL transport against local fixtures. No successful request to the live Gemini provider was made. That still depends on private credentials, an available model, outbound internet and provider quota. No staff messages or raw records were sent to Gemini.

Chrome checks execute page scripts, use login, Trippy's date-filtered lookup, Escape dismissal, return confirmation cancellation/acceptance, and narrow 390-pixel layouts for Records, Trippy and AI Settings. Screenshots were inspected; they remain in `test/.runtime/records-mobile.png` and `ai-settings-mobile.png`. HTTP tests confirm shared printable report content and export filters; actual print preview/paper output has not been accepted by the client. Authenticated integration/browser tests used an isolated PHP HTTP server; the installed Apache was checked for login reachability and private-file protection, not with real user passwords.

Initial test failures were resolved: the sandbox could not write XAMPP session files (test sessions now stay in the workspace), XAMPP's dump client lacked MySQL 8 authentication support (matching MySQL 8 client used), and a pagination assertion incorrectly counted a search-field placeholder (assertion now examines displayed ticket rows). A metadata-column alias in the expanded verification script was also corrected. Final counts above are from passing executions, not merely the presence of test scripts.

## Files changed

| Area | Files |
| --- | --- |
| Private configuration and AI controls | `app/configuration.php`, `app/config.example.php`, `app/ai_settings.php`, `app/db.php`, `app/gemini.php`, `public/admin_ai.php` |
| Local Trippy search and UI | `app/chatbot.php`, `app/partials/sidebar.php`, `public/ai_assistant.php`, `public/assets/chatbot.js`, `public/assets/trippy.js`, `public/assets/reconciliation-ai.js`, `public/api_reconciliation_ai.php` |
| Core validation, sessions and returns | `app/auth.php`, `app/ticket_functions.php`, `app/correction_functions.php`, `app/return_functions.php`, `public/issuance.php`, `public/ticket_details.php` |
| Records and reports | `public/records.php`, `app/report_functions.php`, `public/export_outstanding.php` |
| Admin and safe audit rendering | `app/audit.php`, `public/admin_audit.php`, `public/admin_dashboard.php`, `public/admin_staff.php`, `public/admin_staff_action.php`, `public/assets/admin.css` |
| Database/setup/backup | `database/schema.sql`, `database/migrations/001_session_submission.php`, `002_ai_settings.php`, `003_timestamp_constraints.php`, `app/migrations.php`, `scripts/migrate.php`, `scripts/backup.php`, `scripts/create_admin.php`, `scripts/create_staff.php`, `scripts/reset_password.php`, `scripts/test_gemini.php` |
| Tests | `test/verify.php`, `test/run.php`, `test/worker.php`, `test/web_checks.php`, `test/browser.mjs`, `test/http_fixture.php`, `test/restore_check.php` |
| Protection/documentation | `.gitignore`, root and private-directory `.htaccess` files, `README.md`, `docs/SETUP.md`, `docs/BACKUP.md`, this report |

Other PHP pages received only removal of output before `<?php` and/or a favicon link to an existing asset. Removing leading output is needed for reliable sessions, redirects, JSON and CSV when PHP buffering is disabled. The pre-existing edits to schema, Admin audit and verification were preserved and extended.

## Exact remaining operator/client steps

1. **Apache/LAN:** apply the `public/` document-root configuration in `SETUP.md`, secure alternate/default vhosts, verify the private session-directory permissions and runtime database grants. The inspected default still points at `C:/XAMPP_new/htdocs`; project guards protect the current URL, but global settings were deliberately not changed. Confirm subnet restrictions/HTTPS with the authorized operator; no public port forwarding. From a second office workstation, log in as Staff and Admin and repeat issuance/retrieval/report checks using approved demonstration data in a separate test installation.
2. **Gemini:** configure `gemini.key_file`, `model` and `allowed_models` privately, then use Admin → AI Settings to enable the intended features. Run `php scripts/test_gemini_service.php` with a fixed fictional prompt. Check explanation and aggregate-analysis source labels, then disable the switch and confirm local lookup still works. Do not paste the key into the app or chat. Model availability and successful outbound connectivity remain unverified.
3. **Printing:** filter an outstanding report, open Print Preview, compare its period/count/ticket rows with the CSV, check page breaks and the office printer, and obtain approval of the report format.
4. **Backup:** place the verified backup in protected off-server storage; agree schedule/retention and perform periodic restore drills. Protect the retained live-restore database and test configuration. Review generated databases before any operator-controlled cleanup; this task deletes none.
5. **Deployment/policy:** repeat setup and tests on the intended Ubuntu Apache/PHP/MySQL versions before deployment. Confirm cross-month correction/manual review, backdating/sequence-capacity rules, report and audit retention, roster replacement policy and production acceptance. No automatic lost/overdue rules or undocumented correction override were added.

For another existing installation, apply all three migrations after backup. **No migration remains pending on the inspected local database.**
