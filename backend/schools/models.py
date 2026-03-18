from django.db import models


class School(models.Model):
    """Represents an exam center with a unique center number."""

    name = models.CharField(max_length=255, unique=True)
    center_number = models.CharField(max_length=50, unique=True)
    created_at = models.DateTimeField(auto_now_add=True)

    def __str__(self) -> str:
        return f"{self.name} ({self.center_number})"
