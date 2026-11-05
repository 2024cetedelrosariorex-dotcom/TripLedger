# TripLedger

Trip Ticket Records Reconciliation and Retrieval System with Automated Annual Numbering for the Department of Agrarian Reform, General Services and Procurement Office, DAR?Sarangani.

TripLedger uses PHP, Apache, MySQL, HTML, CSS and JavaScript. The intended office roster is **three Authorized Staff and one Administrator**. Staff have identical operational permissions. Drivers have no accounts; there is no public registration. Admin manages Staff, reviews system-wide audit history and manages Gemini settings. Ticket operations are Staff-only.

## Implemented workflows

- Login, CSRF-protected POST logout, inactivity timeout and account/session revalidation on every protected request. Deactivation and resets revoke sessions.
- Issuance with immutable `YYYY-MM-NNNN` numbers, atomic annual counters and unique submission tokens. Width defaults to four digits; sequences continue across months within each year, using transaction dates.
- Ticket/plate search, transaction-date and return-status filters, paginated records, details, controlled corrections and history.
- Physical return recording, correction and reason-required reversal. Reversed records remain stored. Database constraints and transactional locks prevent multiple active returns.
- Outstanding reports, printing and CSV export using shared filters; formula-like spreadsheet values are neutralized. Returned means an active physical return is recorded. Outstanding does **not** mean lost or overdue. Issued = Returned + Outstanding.
- Audited Staff creation, activation/deactivation and password resets through Admin. Initial Admin creation is terminal-only.
- Read-only system audit with actor, timestamp, ticket, safe Before/After values, filters and pagination.
- Persistent Admin AI switches enforced by backend, permitted model selection, bounded Gemini calls and local fallback. Keys stay in private files. Trippy local search uses deterministic supported phrases, **not model-based language understanding**.

## Setup and operation

See [setup/migrations](docs/SETUP.md), [backup/restore](docs/BACKUP.md), and [verification/remaining checks](docs/VERIFICATION.md). Never import the fresh-install schema over an existing database.

Copy [the safe configuration example](app/config.example.php) to ignored `app/config.php`, or set `TRIPLEDGER_CONFIG` to a private PHP file. Set Apache's document root to `public/`; do not expose the repository or forward public ports for Gemini.

After backup, existing installations run:

```powershell
& C:\XAMPP_new\php\php.exe scripts\migrate.php
& C:\XAMPP_new\php\php.exe test\verify.php
```

Isolated integration verification (operator credentials with permission to create test databases):

```powershell
& C:\XAMPP_new\php\php.exe test\run.php
```

The suite creates new `tripledger_test_*` databases and random demonstration credentials, exercises HTTP and concurrent database operations, and tests backup/restore. It never imports schema over the configured database or deletes databases. Private generated configuration and artifacts live in ignored `test/.runtime/`. On Ubuntu use `php` and installed MySQL clients. Remove test databases only after positively identifying them.

## Trippy date semantics

Supported examples:

- `Show outstanding tickets`
- `Find tickets for plate ABC 1234`
- `Find ticket 2026-10-0001`
- `Show outstanding tickets for October 2026`
- `How many tickets were returned in October 2026?`
- `Show outstanding tickets from 2026-10-01 to 2026-10-31`

Issued/outstanding periods use `transaction_date`. Returned-in-period questions use the **active return_date**, including returns of earlier-month issuances. Replies state dates and matching counts; lists show at most ten rows. Unsupported or ambiguous filters require clarification. Records-page date filters always use transaction dates, including when status is Returned.

Gemini receives fixed fictional prompts or minimal aggregate counts/age groups only. It never receives staff messages, names, plates, raw records or credentials, and cannot execute SQL or change records. Enabling analysis explicitly permits those aggregates to leave the server. Only the server needs outbound internet; local search works offline. Existing limits are per session, not a global billing cap.

## Client decisions and limits

Existing numbering width and date basis are preserved. Backdated issuances allocate the next sequence in that transaction year. Cross-month/year corrections remain blocked for client manual review; no override was invented.

Confirm official numbering/sequence capacity, backdating policy, cross-month correction procedure, report format, audit/backup retention, account replacement policy and future roster expansion before operational adoption. Admin retains its existing ability to create replacement accounts; no new seat/licensing rule is imposed. Historical records are retained.

This work does not establish official DAR production approval. See verification evidence and deployment checks. No mobile app, driver accounts, GPS, fuel/procurement management, notifications, OCR, public registration or automatic lost/overdue classification is included.
