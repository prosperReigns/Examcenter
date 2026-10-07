<?php
return [
    "name" => "examcenter",
    "display_name" => "CBT Examination System",
    "version" => getenv("EXAMCENTER_VERSION") ?: "2.0.0",
    "environment" => getenv("EXAMCENTER_ENV") ?: "production",
    "vendor" => "ExamCenter Technologies",
    "timezone" => getenv("EXAMCENTER_TIMEZONE") ?: "Africa/Lagos",
    "license_secret" => getenv("EXAMCENTER_LICENSE_SECRET") ?: "",
    "api_secret" => getenv("EXAMCENTER_API_SECRET") ?: "",
    "base_url" => getenv("EXAMCENTER_BASE_URL") ?: "",
    "support_email" => getenv("EXAMCENTER_SUPPORT_EMAIL") ?: "support@examcenter.com",
    "support_phone" => getenv("EXAMCENTER_SUPPORT_PHONE") ?: "+234 XXX XXX XXXX",
];