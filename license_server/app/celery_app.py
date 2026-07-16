from celery import Celery
from celery.schedules import crontab

celery_app = Celery(
    "license_server",
    broker="redis://localhost:6379/0",
    backend="redis://localhost:6379/1",
)

celery_app.conf.update(

    timezone="Africa/Lagos",

    enable_utc=True,

    task_serializer="json",

    result_serializer="json",

    accept_content=["json"],

    result_expires=86400,

    task_track_started=True,

    task_time_limit=300,

    task_soft_time_limit=240,

    worker_prefetch_multiplier=1,

    worker_max_tasks_per_child=100,

    task_acks_late=True,

    broker_connection_retry_on_startup=True,

    imports=(
        "app.tasks.activation_token_tasks",
        "app.tasks.analytics_tasks",
        "app.tasks.backup_tasks",
        "app.tasks.email_tasks",
        "app.tasks.invoice_tasks",
        "app.tasks.notification_tasks",
        "app.tasks.outbox_tasks",
        "app.tasks.payment_tasks",
        "app.tasks.purchase_tasks",
        "app.tasks.receipt_tasks",
        "app.tasks.renewal_tasks",
    ),

)

celery_app.conf.beat_schedule = {

    "recover-pending-purchases": {

        "task": "purchases.recover_pending",

        "schedule": crontab(minute="*/5"),

    },

    "process-outbox-events": {

        "task": "outbox.process_pending",

        "schedule": crontab(minute="*/1"),

    },

    "cleanup-processed-outbox-events": {

        "task": "outbox.cleanup_processed",

        "schedule": crontab(hour=1, minute=30),

    },

    "expire-licenses": {

        "task": "renewals.expire_licenses",

        "schedule": crontab(minute=15),

    },

    "send-expiry-reminders": {

        "task": "renewals.send_expiry_reminders",

        "schedule": crontab(hour=8, minute=0),

    },

    "purge-expired-activation-tokens": {

        "task": "activation_tokens.purge_expired",

        "schedule": crontab(
            minute=0,
        ),

    },

    "revoke-stale-activation-tokens": {

        "task": "activation_tokens.revoke_stale",

        "schedule": crontab(
            hour=2,
            minute=0,
        ),

    },

    "delete-used-activation-tokens": {

        "task": "activation_tokens.delete_used",

        "schedule": crontab(
            hour=3,
            minute=0,
        ),

    },

    "activation-token-statistics": {

        "task": "activation_tokens.statistics",

        "schedule": crontab(
            minute="*/30",
        ),

    },

    "activation-token-cleanup-audit": {

        "task": "activation_tokens.audit_cleanup",

        "schedule": crontab(hour=3, minute=30),

    },

    "analytics-daily-snapshot": {

        "task": "analytics.snapshot",

        "schedule": crontab(hour=0, minute=15),

    },

    "database-metadata-backup": {

        "task": "backups.database_metadata",

        "schedule": crontab(hour=2, minute=30),

    },

}
