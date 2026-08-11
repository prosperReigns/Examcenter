from celery import Celery
from celery.schedules import crontab
from kombu import Queue

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

    task_default_queue="default",

    task_default_exchange="default",

    task_default_routing_key="default",

)

celery_app.conf.task_queues = (

    Queue("default"),

    Queue("payments"),

    Queue("emails"),

    Queue("notifications"),

    Queue("reports"),

    Queue("backups"),
    Queue("maintenance"),
    Queue("purchase"),
)

celery_app.conf.imports = (
    "app.tasks.purchase_tasks",
    "app.tasks.payment_tasks",
    "app.tasks.notification_tasks",
    "app.tasks.activation_token_tasks",
    "app.tasks.outbox_tasks",
    "app.tasks.analytics_tasks",
    "app.tasks.backup_tasks",
    "app.tasks.cleanup_tasks",
    "app.tasks.device_tasks",
    "app.tasks.renewal_tasks",
    "app.tasks.audit_tasks",
    "app.tasks.webhook_tasks",
    "app.tasks.license_tasks",
    "app.tasks.receipt_tasks",
    
)
celery_app.conf.beat_schedule = {

    "expire-licenses-nightly": {

        "task": "app.tasks.renewal_tasks.expire_licenses",

        "schedule": crontab(hour=0, minute=5),

    },

    "send-expiry-reminders": {

        "task": "app.tasks.renewal_tasks.send_expiry_reminders",

        "schedule": crontab(hour=8, minute=0),

    },

    "process-outbox": {

        "task": "app.tasks.outbox_tasks.process_outbox",

        "schedule": 60,

    },

    "cleanup-notifications": {

        "task": "app.tasks.notification_tasks.cleanup_notifications",

        "schedule": crontab(hour=2, minute=0),

    },

    "daily-analytics": {

        "task": "app.tasks.analytics_tasks.generate_daily_statistics",

        "schedule": crontab(hour=1, minute=0),

    },

    "database-backup": {

        "task": "app.tasks.backup_tasks.run_database_backup",

        "schedule": crontab(hour=3, minute=0),

    },

    "retry-failed-notifications": {

        "task":"app.tasks.notification_tasks.retry_failed_notifications",

        "schedule":3600,

    },

    "cleanup-old-notifications": {

        "task":"app.tasks.notification_tasks.cleanup_notifications",

        "schedule":crontab(hour=2,minute=30),

    },

    "outbox":{

        "task":"app.tasks.outbox_tasks.process_pending_outbox_event",

        "schedule":30,

    },

    "analytics":{

        "task":"app.tasks.analytics_tasks.generate_daily_statistics",

        "schedule":crontab(hour=1),

    },
    "cleanup":{

        "task":"app.tasks.cleanup_tasks.cleanup_old_tokens",

        "schedule":crontab(hour=4),

    },
    "daily-backup":{

        "task":"app.tasks.backup_tasks.run_database_backup",

        "schedule":crontab(hour=3),

    },
    "heartbeat-cleanup":{

        "task":"app.tasks.device_tasks.heartbeat_cleanup",

        "schedule":3600,

    },

    "cleanup-activation-tokens": {
        "task": "activation.cleanup",
        "schedule": crontab(
            hour=2,
            minute=0,
        ),
    },
}

celery_app.conf.beat_schedule.update({

    "cleanup-webhook-events": {

        "task": "webhooks.cleanup",

        "schedule": crontab(

            hour=2,

            minute=0,

        ),

        "kwargs": {

            "retention_days": 180,

        },

    },
    "purchase-maintenance": {

        "task":
            "purchase.maintenance",

        "schedule":
            crontab(

                hour=3,

                minute=0,

            ),

    }

})