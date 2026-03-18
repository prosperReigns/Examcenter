from django.contrib.auth import get_user_model
from rest_framework.permissions import BasePermission

User = get_user_model()


class IsSuperAdmin(BasePermission):
    """Allow only SuperAdmin users to access the view."""

    def has_permission(self, request, view) -> bool:
        return bool(request.user and request.user.is_authenticated and request.user.role == User.Role.SUPERADMIN)


class IsAdmin(BasePermission):
    """Allow only Admin users to access the view."""

    def has_permission(self, request, view) -> bool:
        return bool(request.user and request.user.is_authenticated and request.user.role == User.Role.ADMIN)


class IsAgent(BasePermission):
    """Allow only Agent users to access the view."""

    def has_permission(self, request, view) -> bool:
        return bool(request.user and request.user.is_authenticated and request.user.role == User.Role.AGENT)


class IsAdminOrAgent(BasePermission):
    """Allow Admins and Agents; explicitly deny SuperAdmins for exam/result operations."""

    def has_permission(self, request, view) -> bool:
        return bool(
            request.user
            and request.user.is_authenticated
            and request.user.role in {User.Role.ADMIN, User.Role.AGENT}
        )
