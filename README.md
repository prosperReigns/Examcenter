# Examcenter

Examcenter is an offline-first PHP/MariaDB assessment platform.

## Current architecture

Examcenter Pro Architecture v2 combines:

- Core CBT that remains usable offline.
- Universal institution and organisational modelling.
- Signed, installation-bound and machine-bound Pro entitlements.
- Legacy-license compatibility during migration.
- Idempotent database migrations and legacy-to-universal backfill.

See `docs/PRO_ARCHITECTURE.md` for the architecture contract.
See `docs/UNIVERSAL_SCHEMA.md` for the canonical Universal schema contract.
See `docs/SYSTEM_AUDIT.md` for the latest system audit and remaining gaps.

## Local setup

1. Install XAMPP with Apache and MariaDB/MySQL.
2. Copy Examcenter into `C:/xampp/htdocs/`.
3. Start Apache and MariaDB.
4. Configure database values through environment variables when they differ from the defaults.
5. Run the legacy schema in `help.txt` for an existing installation.
6. Run the additive Universal migration:

```
php database/migrate.php
```

7. For an existing installation, run the legacy mapping:

```
php database/backfill_legacy.php
```

The migration does not delete or rename legacy CBT tables.

## Pro licensing

Configure:

- `EXAMCENTER_PRO_SERVER`
- `EXAMCENTER_LICENSE_SERVER` for legacy compatibility only

The Pro client consumes the v2 entitlement API and verifies signed packages locally. Core CBT is not blocked when the Pro server is unavailable.

## Security

Runtime installation IDs, encrypted license files, caches, logs and secrets must not be committed. Use `.env.example` as the configuration template.
