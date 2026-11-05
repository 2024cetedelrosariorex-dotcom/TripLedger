# Backup and restore

Back up before migrations and on the client's approved schedule. Store copies off-server on access-controlled, encrypted storage. Agree retention/recovery responsibilities with DAR. Backups contain confidential records and password hashes: never commit them or place them under any Apache root. Git ignores are not access controls.

Use `mysql_config_editor` to create a local login path if available, or an operator-only option file outside the web root. Populate `[client]`, host, port, user and password locally; never pass a password on a command line. Restrict Windows ACLs or set owner-only `0600` mode on Ubuntu. `--defaults-extra-file` must be the first option.

Use the client matching the actual MySQL server. Here the XAMPP client cannot load `caching_sha2_password`; the installed MySQL 8.0 client is required. PowerShell example using a previously prepared private option file:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe' --defaults-extra-file=C:\TripLedgerPrivate\backup.cnf --single-transaction --skip-lock-tables --no-tablespaces --result-file=C:\TripLedgerPrivate\tripledger-backup.sql tripledger
if ($LASTEXITCODE -ne 0) { throw 'Backup failed' }
Get-FileHash -Algorithm SHA256 C:\TripLedgerPrivate\tripledger-backup.sql
```

Use `--result-file` to avoid PowerShell encoding conversion. Omit `--databases`, so the dump does not force a database name during test restores. Keep all tables InnoDB and avoid DDL during the dump. Check exit status/file size, retain a checksum and record app/migration versions. Back up private configuration/API keys separately with tighter access.

Alternatively run `php scripts/backup.php C:/TripLedgerPrivate/new-backup.sql` with the private operator configuration. The helper refuses existing files and project-local destinations, uses a temporary private client option file, removes it on completion, omits DROP TABLE statements, and prints a checksum. You must still ensure the destination is outside every web root and has suitable ACLs. Set `TRIPLEDGER_MYSQL_BIN` if the matching client lives elsewhere. A temporary local backup is not an off-server retention plan.

For restore testing, create a **new empty restore-test database**. Confirm its name before loading data. Select that database in Workbench, or open the client:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe' --defaults-extra-file=C:\TripLedgerPrivate\backup.cnf --database=tripledger_restore_test
```

Inside the client:

```sql
SELECT DATABASE();
SOURCE C:/TripLedgerPrivate/tripledger-backup.sql;
SELECT COUNT(*) FROM trip_tickets;
SELECT COUNT(*) FROM ticket_returns WHERE reversed_at IS NULL;
SELECT COUNT(*) FROM audit_logs;
SELECT * FROM schema_migrations;
```

Check Issued = Returned + Outstanding, unique constraints, annual counters, accounts and audit continuity. Point a separate private test configuration at the restored database and run `test/verify.php`. Do not point the office app at it. For actual recovery, the operator must verify destination/backup, preserve the existing database, stop writes and follow client-approved procedures. This does not authorize overwriting a live database.

On Ubuntu use installed `mysqldump` / `mysql`, Unix paths and shell input redirection as appropriate. The integration suite tests these tools on demonstration databases and compares every restored table row, including audit JSON, counters and generated return IDs. A demonstration restore does not verify the client's live backup.
