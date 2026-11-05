# Installation and configuration

## Requirements

- PHP 8.1+ with PDO MySQL, mbstring, JSON, OpenSSL and sessions; cURL for Gemini and HTTP tests. Enable extensions in both Apache and CLI PHP. PHP needs a private writable session directory and error log; use `display_errors=Off` for office access.
- MySQL 8 with InnoDB, JSON and stored generated columns. Verification used MySQL 8.0.45. Confirm the configured host/port: XAMPP's MariaDB and a separate MySQL installation may be different servers.
- Apache 2.4 with document root pointing to `public/`.
- Windows examples use XAMPP. On Ubuntu use installed PHP/MySQL clients and Linux paths. Application credential paths are configurable.

## Private configuration

Copy `app/config.example.php` to ignored `app/config.php`. Enter database connection settings locally. Never put credentials in a terminal command, source control or screenshot. Alternatively set `TRIPLEDGER_CONFIG` to an absolute private PHP file. Setting an environment variable in a terminal does not configure Apache automatically.

Use utf8mb4. Preserve `ticket_numbering.sequence_width` (default 4); changing it after issuance requires client review. Application and database connections use Asia/Manila / UTC+08:00.

## Fresh installation only

1. Using an operator account, create a **new empty** database with utf8mb4 / utf8mb4_unicode_ci. Select it in Workbench, then execute `database/schema.sql`. The schema contains no `USE`, `CREATE DATABASE`, `DROP`, seed accounts or passwords.
2. Point private configuration at the new database. Run `php scripts/migrate.php`; the fresh schema already includes migration versions, so this is a no-op.
3. Run `php scripts/create_admin.php` in a private terminal. Enter the initial Admin's name, username and unique 12–72-byte password. Input may be echoed: do not screen-share or record setup. An existing Admin prevents creating another.
4. Sign in as Admin and create three Staff accounts. Use the audited Admin workflow for future Staff creation and resets; old CLI helpers now direct operators there.
5. Check Staff/Admin separation. An empty install has zero tickets. `test/verify.php` expects the three Staff and one Admin roster, so run it after account setup.

## Existing database upgrade

1. Validate a backup using `BACKUP.md`; schedule a short maintenance window. MySQL DDL commits independently.
2. Use private **operator** configuration with DDL privileges to run `php scripts/migrate.php`. Never run `schema.sql` over an existing database.
3. `001_session_submission` adds missing session-version/submission-token columns and a unique token index. Existing null tokens remain null; ticket numbers and audit history are untouched.
4. `002_ai_settings` creates persistent settings, initially disabled. It does not read or copy API credentials.
   `003_timestamp_constraints` aligns legacy timestamp nullability with the fresh schema. It first checks all affected columns for nulls and stops for manual review if any are missing; it never invents historical dates. On the inspected installation, all values were present and were preserved.
5. `schema_migrations` records completed versions. An advisory lock prevents concurrent runners; per-object checks permit recovery from interrupted DDL. Unique-index conflicts stop for manual investigation, never deletion of data.
6. Rerun migrations (should report current), run `php test/verify.php`, and return Apache to its app credentials.

## Database least privilege

Create a database-specific runtime account restricted to its actual connecting host, not `%`. Set its password privately using DBA tooling. Example grants, after substituting the correct database/account/host:

```sql
GRANT SELECT, INSERT, UPDATE ON tripledger.users TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tripledger.vehicles TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT ON tripledger.drivers TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tripledger.annual_counters TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tripledger.trip_tickets TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tripledger.ticket_returns TO 'tripledger_app'@'localhost';
GRANT SELECT, INSERT ON tripledger.audit_logs TO 'tripledger_app'@'localhost';
GRANT SELECT, UPDATE ON tripledger.ai_settings TO 'tripledger_app'@'localhost';
GRANT SELECT ON tripledger.schema_migrations TO 'tripledger_app'@'localhost';
```

Do not use root in Apache. Runtime needs no DELETE, ALTER, DROP, FILE or global privileges. Use separate operator/backup accounts. Never grant runtime UPDATE or DELETE on audit_logs; the app offers no log editing endpoint.

## Apache and LAN

Example virtual host for the authorized operator to adapt; this task does not alter server settings:

```apache
<VirtualHost *:80>
    ServerName tripledger.office.lan
    DocumentRoot "C:/XAMPP_new/htdocs/TripLedger/public"
    <Directory "C:/XAMPP_new/htdocs/TripLedger/public">
        Options -Indexes
        AllowOverride None
        Require ip 127.0.0.1 ::1 192.168.1.0/24
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
```

Substitute the real office subnet, address and hostname. On Ubuntu use e.g. `/var/www/tripledger/public`. Ensure other/default XAMPP virtual hosts cannot expose the repository, `.git`, configuration, tests, schema or backups through alternate URLs. Securing one virtual host does not secure another.

The inspected local Apache still serves `C:/XAMPP_new/htdocs` on port 80. Project-level `.htaccess` guards now block private directories and dotfiles at the existing `/TripLedger/` URL; HTTP checks confirmed 403 for app configuration, schema, test artifacts and `.git/HEAD`, while login remains 200. These guards depend on Apache overrides/modules and are defense in depth, not a replacement for the `public/` document root. No global Apache settings were changed.

Have the authorized operator configure LAN DNS/hosts, stable server addressing and subnet-scoped firewall access. Do not forward router ports or expose TripLedger publicly. Prefer office-managed HTTPS; secure cookies activate when PHP sees HTTPS. Have the operator configure any proxy correctly. Verify from a second workstation. No server-wide or firewall settings are changed automatically.

## Gemini configuration

Gemini defaults to disabled. In private configuration set:

- `gemini.key_file`: absolute path outside **all** web roots, such as `C:/XAMPP_new/tripledger-secrets/gemini_key.txt` or `/etc/tripledger/gemini_key.txt`. Restrict read access to the operator/PHP account. Never commit the file.
- `gemini.model` and `gemini.allowed_models`: identifiers available to your provider project. Availability is not assumed or tested automatically; no hardcoded model migration is performed.

Admin → AI Settings selects a permitted model and enables the master/feature switches. Enabling analysis permits only aggregate counts and age groups to leave the server. Changes are audited without secrets. Disabling stops subsequent calls; an already-sent request cannot be recalled.

The server needs outbound HTTPS, DNS, correct time and a valid CA trust store. Never disable TLS verification. Requests have a 10-second connection timeout, 20-second total timeout, 128 KiB response cap and one retry for HTTP 503. Missing keys, disabled AI, quota, network and malformed/incomplete responses yield local fallback. Local database search works without internet. Per-session limits are 20 requests/hour per endpoint with short spacing; these are not an organization-wide billing cap.
