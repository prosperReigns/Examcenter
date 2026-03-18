from rest_framework import serializers

from exams.serializers import ExamSerializer
from exams.serializers import CandidateRegistrationDetailSerializer

from .models import Result


class ResultSerializer(serializers.ModelSerializer):
    registration = CandidateRegistrationDetailSerializer(read_only=True)
    exam = ExamSerializer(read_only=True)

    class Meta:
        model = Result
        fields = (
            "id",
            "registration",
            "exam",
            "score",
            "total_questions",
            "percentage",
            "status",
            "created_at",
        )
