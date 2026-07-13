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

)

celery_app.conf.beat_schedule = {

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

}