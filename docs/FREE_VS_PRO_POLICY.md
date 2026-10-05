# Examcenter Free vs Pro

## Core Free

Examcenter Core is permanently free for the complete normal examination lifecycle.

A Core installation must be able to conduct a normal examination offline without a Pro entitlement, including:

- institution/basic academic setup
- academic years and periods
- classes/organizational units
- subjects/courses
- administrators, teachers and students
- teacher assignments
- question creation and management
- test/exam creation
- exam scheduling
- student examination/taking tests
- exam timer and submission
- objective marking
- basic result calculation and viewing
- basic result management
- basic examination records
- basic backups required to protect the exam process

## Pro

Pro contains supporting, advanced, automated, intelligent, security, analytics, professional-document, multi-campus and corporate capabilities.

Current Pro feature codes are defined in includes/pro_features.php.

## Non-negotiable rule

**If removing a feature would prevent an institution from conducting and completing a normal examination, that feature is Core Free.**

**If it supports, improves, automates, secures, analyzes, scales or extends examination operations without being necessary for the normal exam lifecycle, it is Pro.**

## Licensing rule

Pro entitlement failures, expiry, heartbeat failures or license-server unavailability must never block Core examination operations.

Feature gates must be placed only around Pro features.

## Development rule

Every new feature must be classified before implementation using:

- includes/product_policy.php
- ExamcenterProductPolicy::isCore()
- ExamcenterProductPolicy::isPro()

No future Pro feature may become a hidden dependency of Core CBT.
