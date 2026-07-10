from celery import Celery

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