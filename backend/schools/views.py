from rest_framework.permissions import AllowAny
from rest_framework.viewsets import ModelViewSet

from .models import School
from .serializers import SchoolSerializer


class SchoolViewSet(ModelViewSet):
    """Public registration and lookup for exam centers."""

    queryset = School.objects.all()
    serializer_class = SchoolSerializer
    permission_classes = (AllowAny,)
    http_method_names = ["get", "post"]
    lookup_field = "center_number"
