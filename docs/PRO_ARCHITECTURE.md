# Examcenter Pro Architecture v2

Examcenter remains offline-first. Core examination operations do not depend on internet connectivity.

## Architecture rules

1. Core CBT functionality remains available locally.
2. Pro functionality is controlled by a signed, machine/installation-bound entitlement.
3. The licensing server is authoritative for Pro purchases, activation, device binding and entitlement status.
4. The desktop application stores and verifies the signed entitlement locally.
5. A heartbeat is informational and must never disable Core because the server is unavailable.
6. Legacy license tables/APIs remain compatibility adapters during migration.
7. Universal academic/organisational structures are additive and do not remove the Nigerian secondary-school workflow.
8. Institutions can model primary schools, secondary schools, universities, colleges, vocational organisations, assessment centres and corporate assessments without hard-coded JSS/SS/100-level assumptions.

## Pro entitlement contract

The client consumes the v2 entitlement API:

- GET /api/v2/public/plans
- POST /api/v2/public/entitlements/activate
- POST /api/v2/public/entitlements/heartbeat
- POST /api/v2/public/entitlements/device-change
- POST /api/v2/public/entitlements/feature-check

A signed package contains package_type=examcenter_pro_entitlement and package_version=2, plus the entitlement identity, installation binding, machine binding, dates, features, checksum and RSA signature.

The package is verified using the public key bundled with Examcenter. The private signing key never belongs in the desktop application.

## Feature policy

Default Pro feature codes are:

- pro
- advanced_reports
- cloud_backup
- remote_results
- multi_campus
- disaster_recovery
- ai_features

The application must check Pro feature access only at Pro feature boundaries. Exam creation, scheduling, question entry, taking tests, result viewing and other core exam-process functions must not be disabled merely because Pro cannot be verified.

## Universal data model

The new model introduces:

institution -> organisational units -> programmes/levels -> people/memberships -> courses -> assessments

The old streams, academic_levels, classes, students, teachers, subjects and tests structures remain as compatibility surfaces. New records can be linked through the universal context tables.

## Migration strategy

The migration is additive and idempotent:

1. Create universal tables.
2. Seed generic institution/unit types.
3. Preserve all existing legacy tables.
4. Introduce adapters and mappings gradually.
5. Migrate UI modules one domain at a time.
6. Keep legacy identifiers until the migration is complete.

Do not delete or rename legacy tables as part of this migration.
