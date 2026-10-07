# Examcenter Universal Architecture — Canonical Schema Contract

**Database:** `cbt_app_db`  
**Engine:** MySQL/MariaDB  
**Charset:** `utf8mb4`

The executable migrations under `database/migrations/` are authoritative. This document defines the naming contract application code must use.

## Canonical naming rules

| Concept | Canonical columns |
|---|---|
| Institution | `institutions.id/name/code/is_active` |
| Institution type | `institution_types.id/code/name/is_active` |
| Organisation unit | `organizational_units.id/institution_id/unit_type_id/parent_id/code/name/is_active` |
| Unit type | `organizational_unit_types.id/code/name/is_active` |
| Academic period | `academic_periods.id/institution_id/parent_id/code/name/period_type/starts_at/ends_at/status` |
| Programme | `programmes.id/institution_id/code/name/programme_type/is_active` |
| Programme level | `programme_levels.id/programme_id/organizational_unit_id/code/name/sort_order` |
| Person | `people.id/institution_id/display_name/external_ref/is_active` |
| Institution membership | `institution_memberships.id/institution_id/person_id/role_code/status` |
| Unit membership | `unit_memberships.id/unit_id/person_id/membership_role/status` |
| Course | `courses.id/institution_id/programme_id/code/name/course_type/is_active` |
| Course assignment | `course_assignments.course_id/unit_id/person_id/academic_period_id/role_code` |
| Assessment group | `assessment_groups.id/institution_id/code/name/group_type/weighting` |
| Assessment group assignment | `assessment_group_assignments.assessment_group_id/unit_id/programme_id/academic_period_id/course_id` |
| Test context | `tests.institution_id/organizational_unit_id/programme_id/programme_level_id/academic_period_id/course_id/assessment_group_id` |

## Application rule

Never introduce a new Universal query using the old draft identifiers:

- `institution_name`
- `institution_code`
- `status` for institutions/units/courses/programmes
- `type_code`
- `type_name`
- `unit_name`
- `unit_code`
- `parent_unit_id`
- `period_name`
- `period_code`
- `parent_period_id`
- `programme_name`
- `programme_code`
- `level_name`
- `level_code`
- `course_name`
- `course_code`
- `group_name`
- `group_code`
- `institution_membership_id` or `organizational_unit_id` on `unit_memberships`

Presentation-layer aliases are allowed when a legacy UI expects a display key, e.g.:

```sql
SELECT ou.name AS unit_name
FROM organizational_units ou
```

The database column itself remains `name`.

## Backward compatibility

The legacy CBT tables remain available for existing exam workflows:

- `schools`
- `streams`
- `academic_levels`
- `classes`
- `subjects`
- `subject_levels`
- `students`
- `teachers`
- `teacher_subjects`
- `teacher_classes`
- legacy assessment/question tables

These are compatibility surfaces, not the Universal model.

The bridge is represented by fields such as:

- `organizational_units.legacy_class_id`
- `courses.legacy_subject_id`
- `academic_periods.legacy_academic_year_id`
- `institution_memberships.legacy_student_id`
- `institution_memberships.legacy_teacher_id`

Do not create a second Universal schema to replace these compatibility mappings.

## Universal flows now authoritative

### Institution setup

`super_admin/universal_setup.php`

Supports:

- primary schools
- secondary schools
- tertiary institutions
- vocational institutions
- corporate organisations
- assessment centres
- custom organisational structures

No JSS/SS/PRIMARY assumptions are required.

### Candidate registration

`student/universal_register.php`

The candidate selects an assessment rather than being forced into a hard-coded school class hierarchy.

The legacy `student/register.php` route redirects to the Universal flow when the Universal schema is available.

### Legacy database reconciliation

`database/migrations/20261007_0005_legacy_universal_reconciliation.php`

This migration detects the earlier draft schema and copies its data into the canonical column names without requiring a destructive table replacement.

## Migration order

1. `20261005_0001_universal_architecture.sql`
2. `20261005_0002_theory_assessment.sql`
3. `20261005_0003_pro_assessment_suite.sql`
4. `20261007_0004_system_audit_hardening.sql`
5. `20261007_0005_legacy_universal_reconciliation.php`

Run:

```bash
php database/migrate.php
php scripts/audit_system.php
```

## Design principle

Examcenter is no longer a "secondary-school CBT with extra tables."

The Universal model is:

**Institution → organisational units → programmes/levels → academic periods → courses → assessments → people/memberships → results**

A Nigerian secondary school is one configuration of that model, not the model itself.
