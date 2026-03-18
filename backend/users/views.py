from django.contrib.auth import get_user_model
from rest_framework import viewsets

from .permissions import IsAdmin, IsSuperAdmin
from .serializers import AdminUserCreateSerializer, AgentUserCreateSerializer, UserSerializer

User = get_user_model()


class AdminUserViewSet(viewsets.ModelViewSet):
    """SuperAdmin-only management of Admin accounts."""

    queryset = User.objects.filter(role=User.Role.ADMIN)
    permission_classes = (IsSuperAdmin,)

    def get_serializer_class(self):
        if self.action in {"create", "update", "partial_update"}:
            return AdminUserCreateSerializer
        return UserSerializer


class AgentUserViewSet(viewsets.ModelViewSet):
    """Admin-only management of Agent accounts."""

    queryset = User.objects.filter(role=User.Role.AGENT)
    permission_classes = (IsAdmin,)

    def get_serializer_class(self):
        if self.action in {"create", "update", "partial_update"}:
            return AgentUserCreateSerializer
        return UserSerializer
