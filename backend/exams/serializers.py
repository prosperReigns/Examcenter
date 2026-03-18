from django.db import transaction
from rest_framework import serializers

from schools.models import School
from schools.serializers import SchoolSerializer

from .models import (
    Answer,
    Candidate,
    CandidateRegistration,
    Exam,
    Option,
    Question,
    Subject,
)


class SubjectSerializer(serializers.ModelSerializer):
    class Meta:
        model = Subject
        fields = ("id", "name")


class ExamSerializer(serializers.ModelSerializer):
    created_by = serializers.StringRelatedField(read_only=True)

    class Meta:
        model = Exam
        fields = (
            "id",
            "subject",
            "exam_year",
            "title",
            "duration_minutes",
            "is_active",
            "created_by",
            "created_at",
        )
        read_only_fields = ("id", "created_by", "created_at")


class OptionSerializer(serializers.ModelSerializer):
    class Meta:
        model = Option
        fields = ("id", "text", "is_correct")


class OptionPublicSerializer(serializers.ModelSerializer):
    class Meta:
        model = Option
        fields = ("id", "text")


class QuestionSerializer(serializers.ModelSerializer):
    options = OptionSerializer(many=True, required=False)
    created_by = serializers.StringRelatedField(read_only=True)

    class Meta:
        model = Question
        fields = (
            "id",
            "exam",
            "text",
            "question_type",
            "correct_text",
            "options",
            "created_by",
            "created_at",
        )
        read_only_fields = ("id", "created_by", "created_at")

    @transaction.atomic
    def create(self, validated_data):
        options_data = validated_data.pop("options", [])
        question = Question.objects.create(**validated_data)
        Option.objects.bulk_create(
            [Option(question=question, **option) for option in options_data]
        )
        return question

    @transaction.atomic
    def update(self, instance, validated_data):
        options_data = validated_data.pop("options", None)
        for attr, value in validated_data.items():
            setattr(instance, attr, value)
        instance.save()
        if options_data is not None:
            instance.options.all().delete()
            Option.objects.bulk_create(
                [Option(question=instance, **option) for option in options_data]
            )
        return instance


class QuestionBulkItemSerializer(serializers.Serializer):
    text = serializers.CharField()
    question_type = serializers.ChoiceField(choices=Question.QuestionType.choices)
    correct_text = serializers.CharField(required=False, allow_blank=True)
    options = OptionSerializer(many=True, required=False)


class QuestionBulkUploadSerializer(serializers.Serializer):
    exam = serializers.PrimaryKeyRelatedField(queryset=Exam.objects.all())
    questions = QuestionBulkItemSerializer(many=True)


class QuestionPublicSerializer(serializers.ModelSerializer):
    options = OptionPublicSerializer(many=True, read_only=True)

    class Meta:
        model = Question
        fields = ("id", "text", "question_type", "options")


class ExamStartSerializer(serializers.ModelSerializer):
    questions = QuestionPublicSerializer(many=True, read_only=True)

    class Meta:
        model = Exam
        fields = ("id", "subject", "exam_year", "title", "duration_minutes", "questions")


class CandidateRegistrationSerializer(serializers.Serializer):
    center_number = serializers.CharField()
    candidate_number = serializers.CharField()
    full_name = serializers.CharField()
    exam = serializers.PrimaryKeyRelatedField(queryset=Exam.objects.select_related("subject"))
    subject = serializers.PrimaryKeyRelatedField(queryset=Subject.objects.all())

    def validate(self, attrs):
        if attrs["exam"].subject_id != attrs["subject"].id:
            raise serializers.ValidationError(
                "Subject does not match the exam's subject."
            )
        return attrs

    @transaction.atomic
    def create(self, validated_data):
        center_number = validated_data["center_number"]
        candidate_number = validated_data["candidate_number"]
        full_name = validated_data["full_name"]
        exam = validated_data["exam"]
        subject = validated_data["subject"]

        try:
            school = School.objects.get(center_number=center_number)
        except School.DoesNotExist as exc:
            raise serializers.ValidationError("Invalid center number.") from exc

        candidate, created = Candidate.objects.get_or_create(
            school=school,
            candidate_number=candidate_number,
            defaults={"full_name": full_name},
        )
        if not created and candidate.full_name != full_name:
            candidate.full_name = full_name
            candidate.save(update_fields=["full_name"])

        registration, registration_created = CandidateRegistration.objects.get_or_create(
            candidate=candidate,
            exam=exam,
            subject=subject,
            defaults={"exam_year": exam.exam_year},
        )
        if not registration_created:
            raise serializers.ValidationError("Candidate already registered for this exam.")
        return registration


class CandidateRegistrationDetailSerializer(serializers.ModelSerializer):
    exam = ExamSerializer(read_only=True)
    subject = SubjectSerializer(read_only=True)

    class Meta:
        model = CandidateRegistration
        fields = ("id", "exam", "subject", "exam_year", "registered_at")


class CandidateProfileSerializer(serializers.ModelSerializer):
    school = SchoolSerializer(read_only=True)
    registrations = CandidateRegistrationDetailSerializer(many=True, read_only=True)

    class Meta:
        model = Candidate
        fields = ("id", "school", "candidate_number", "full_name", "registrations")


class CandidateValidationSerializer(serializers.Serializer):
    center_number = serializers.CharField()
    candidate_number = serializers.CharField()


class ExamAnswerSerializer(serializers.Serializer):
    question_id = serializers.PrimaryKeyRelatedField(
        queryset=Question.objects.select_related("exam"),
        source="question",
    )
    selected_option_ids = serializers.ListField(
        child=serializers.IntegerField(), required=False, allow_empty=True
    )
    text_answer = serializers.CharField(required=False, allow_blank=True)


class ExamSubmissionSerializer(serializers.Serializer):
    center_number = serializers.CharField()
    candidate_number = serializers.CharField()
    exam = serializers.PrimaryKeyRelatedField(queryset=Exam.objects.all())
    answers = ExamAnswerSerializer(many=True)


class AnswerSerializer(serializers.ModelSerializer):
    class Meta:
        model = Answer
        fields = ("id", "question", "selected_options", "text_answer", "is_correct", "submitted_at")
