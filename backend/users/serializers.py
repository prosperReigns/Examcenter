from django.contrib.auth import get_user_model
from rest_framework import serializers

User = get_user_model()


class UserSerializer(serializers.ModelSerializer):
    """Shared read serializer for user listings."""

    class Meta:
        model = User
        fields = ("id", "username", "first_name", "last_name", "email", "role", "is_active")


class BaseUserCreateSerializer(serializers.ModelSerializer):
    """Base serializer that hashes passwords on creation."""

    password = serializers.CharField(write_only=True)
    role = serializers.CharField(read_only=True)

    class Meta:
        model = User
        fields = ("id", "username", "first_name", "last_name", "email", "password", "role")

    def create(self, validated_data):
        password = validated_data.pop("password")
        user = User(**validated_data)
        user.set_password(password)
        user.save()
        return user


class AdminUserCreateSerializer(BaseUserCreateSerializer):
    """Serializer that forces the Admin role."""

    def create(self, validated_data):
        validated_data["role"] = User.Role.ADMIN
        return super().create(validated_data)


class AgentUserCreateSerializer(BaseUserCreateSerializer):
    """Serializer that forces the Agent role."""

    def create(self, validated_data):
        validated_data["role"] = User.Role.AGENT
        return super().create(validated_data)
