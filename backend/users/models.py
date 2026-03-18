from django.contrib.auth.models import AbstractUser
from django.db import models


class User(AbstractUser):
    """User model with explicit role assignment for role-based access."""

    class Role(models.TextChoices):
        SUPERADMIN = "superadmin", "SuperAdmin"
        ADMIN = "admin", "Admin"
        AGENT = "agent", "Agent"

    # Role drives permissions across the API (e.g., Admins manage exams, Agents manage questions).
    role = models.CharField(max_length=20, choices=Role.choices, default=Role.AGENT)

    @property
    def is_superadmin(self) -> bool:
        return self.role == self.Role.SUPERADMIN

    @property
    def is_admin(self) -> bool:
        return self.role == self.Role.ADMIN

    @property
    def is_agent(self) -> bool:
        return self.role == self.Role.AGENT
