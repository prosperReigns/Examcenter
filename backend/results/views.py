from rest_framework import viewsets

from users.permissions import IsAdminOrAgent

from .models import Result
from .serializers import ResultSerializer


class ResultViewSet(viewsets.ReadOnlyModelViewSet):
    """Admin/Agent access to computed results."""

    serializer_class = ResultSerializer
    permission_classes = (IsAdminOrAgent,)

    def get_queryset(self):
        queryset = Result.objects.select_related(
            "registration__candidate__school",
            "registration__exam",
            "registration__subject",
            "exam",
        )
        exam_year = self.request.query_params.get("exam_year")
        subject_id = self.request.query_params.get("subject")
        if exam_year:
            queryset = queryset.filter(exam__exam_year=exam_year)
        if subject_id:
            queryset = queryset.filter(exam__subject_id=subject_id)
        return queryset
