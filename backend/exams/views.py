from decimal import Decimal
from django.db import transaction
from django.db.models import Prefetch
from rest_framework import status, viewsets
from rest_framework.decorators import action
from rest_framework.permissions import AllowAny
from rest_framework.response import Response

from results.models import Result
from results.serializers import ResultSerializer
from schools.models import School
from users.permissions import IsAdmin, IsAdminOrAgent

from .models import Answer, Candidate, CandidateRegistration, Exam, Option, Question, Subject
from .serializers import (
    CandidateProfileSerializer,
    CandidateRegistrationSerializer,
    CandidateValidationSerializer,
    ExamSerializer,
    ExamStartSerializer,
    ExamSubmissionSerializer,
    QuestionBulkUploadSerializer,
    QuestionSerializer,
    SubjectSerializer,
)


class SubjectViewSet(viewsets.ModelViewSet):
    """Admin-managed subjects with public read access."""

    queryset = Subject.objects.all()
    serializer_class = SubjectSerializer

    def get_permissions(self):
        if self.action in {"list", "retrieve"}:
            return [AllowAny()]
        return [IsAdmin()]


class ExamViewSet(viewsets.ModelViewSet):
    """Exam management with public listing and candidate-facing actions."""

    serializer_class = ExamSerializer

    def get_queryset(self):
        queryset = (
            Exam.objects.select_related("subject", "created_by")
            .prefetch_related(Prefetch("questions", queryset=Question.objects.prefetch_related("options")))
        )
        exam_year = self.request.query_params.get("exam_year")
        subject_id = self.request.query_params.get("subject")
        if exam_year:
            queryset = queryset.filter(exam_year=exam_year)
        if subject_id:
            queryset = queryset.filter(subject_id=subject_id)
        return queryset

    def get_permissions(self):
        if self.action in {"list", "retrieve", "start", "submit"}:
            return [AllowAny()]
        return [IsAdmin()]

    def perform_create(self, serializer):
        serializer.save(created_by=self.request.user)

    @action(detail=True, methods=["post"])
    def start(self, request, pk=None):
        exam = self.get_object()
        serializer = ExamStartSerializer(exam)
        return Response(serializer.data)

    @action(detail=False, methods=["post"])
    def submit(self, request):
        submission_serializer = ExamSubmissionSerializer(data=request.data)
        submission_serializer.is_valid(raise_exception=True)
        data = submission_serializer.validated_data

        exam = data["exam"]
        try:
            school = School.objects.get(center_number=data["center_number"])
        except School.DoesNotExist:
            return Response({"detail": "Invalid center number."}, status=status.HTTP_400_BAD_REQUEST)

        try:
            candidate = Candidate.objects.get(
                school=school, candidate_number=data["candidate_number"]
            )
        except Candidate.DoesNotExist:
            return Response({"detail": "Candidate not found."}, status=status.HTTP_400_BAD_REQUEST)

        try:
            registration = CandidateRegistration.objects.select_related("candidate", "exam").get(
                candidate=candidate, exam=exam
            )
        except CandidateRegistration.DoesNotExist:
            return Response(
                {"detail": "Candidate is not registered for this exam."},
                status=status.HTTP_400_BAD_REQUEST,
            )

        if Result.objects.filter(registration=registration).exists():
            return Response({"detail": "Exam already submitted."}, status=status.HTTP_400_BAD_REQUEST)

        answers_payload = data["answers"]
        question_ids = [answer["question"].id for answer in answers_payload]
        if len(question_ids) != len(set(question_ids)):
            return Response(
                {"detail": "Duplicate answers submitted for the same question."},
                status=status.HTTP_400_BAD_REQUEST,
            )

        with transaction.atomic():
            score = 0
            for answer_payload in answers_payload:
                question = answer_payload["question"]
                if question.exam_id != exam.id:
                    return Response(
                        {"detail": f"Question {question.id} does not belong to exam."},
                        status=status.HTTP_400_BAD_REQUEST,
                    )

                selected_option_ids = answer_payload.get("selected_option_ids", [])
                options = list(
                    Option.objects.filter(id__in=selected_option_ids, question=question)
                )
                if len(options) != len(selected_option_ids):
                    return Response(
                        {"detail": f"Invalid options for question {question.id}."},
                        status=status.HTTP_400_BAD_REQUEST,
                    )

                text_answer = answer_payload.get("text_answer", "") or ""

                is_correct = False
                if question.question_type == Question.QuestionType.SINGLE_CHOICE:
                    is_correct = len(options) == 1 and options[0].is_correct
                elif question.question_type == Question.QuestionType.MULTIPLE_CHOICE:
                    correct_ids = set(
                        question.options.filter(is_correct=True).values_list("id", flat=True)
                    )
                    is_correct = set(selected_option_ids) == correct_ids and bool(correct_ids)
                elif question.question_type == Question.QuestionType.TRUE_FALSE:
                    is_correct = text_answer.strip().lower() == question.correct_text.strip().lower()
                elif question.question_type == Question.QuestionType.FILL_BLANK:
                    is_correct = text_answer.strip().lower() == question.correct_text.strip().lower()

                answer = Answer.objects.create(
                    registration=registration,
                    question=question,
                    text_answer=text_answer,
                    is_correct=is_correct,
                )
                if options:
                    answer.selected_options.set(options)
                if is_correct:
                    score += 1

            total_questions = exam.questions.count()
            percentage = (
                (Decimal(score) / Decimal(total_questions) * Decimal("100"))
                if total_questions
                else Decimal("0")
            )
            result = Result.objects.create(
                registration=registration,
                exam=exam,
                score=score,
                total_questions=total_questions,
                percentage=percentage,
            )

        result_serializer = ResultSerializer(result)
        return Response(result_serializer.data, status=status.HTTP_201_CREATED)


class QuestionViewSet(viewsets.ModelViewSet):
    """Admin/Agent question management, including bulk uploads."""

    serializer_class = QuestionSerializer
    permission_classes = (IsAdminOrAgent,)

    def get_queryset(self):
        queryset = Question.objects.select_related("exam", "created_by").prefetch_related("options")
        exam_id = self.request.query_params.get("exam")
        if exam_id:
            queryset = queryset.filter(exam_id=exam_id)
        return queryset

    def perform_create(self, serializer):
        serializer.save(created_by=self.request.user)

    @action(detail=False, methods=["post"])
    def bulk_upload(self, request):
        serializer = QuestionBulkUploadSerializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        exam = serializer.validated_data["exam"]
        questions = serializer.validated_data["questions"]

        created_questions = []
        with transaction.atomic():
            for question_data in questions:
                options_data = question_data.pop("options", [])
                question = Question.objects.create(
                    exam=exam, created_by=request.user, **question_data
                )
                Option.objects.bulk_create(
                    [Option(question=question, **option) for option in options_data]
                )
                created_questions.append(question)

        return Response(
            QuestionSerializer(created_questions, many=True).data,
            status=status.HTTP_201_CREATED,
        )


class CandidateViewSet(viewsets.ViewSet):
    """Candidate registration and validation (no authentication required)."""

    permission_classes = (AllowAny,)

    def create(self, request):
        serializer = CandidateRegistrationSerializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        registration = serializer.save()
        profile = CandidateProfileSerializer(registration.candidate)
        return Response(profile.data, status=status.HTTP_201_CREATED)

    @action(detail=False, methods=["post"], url_path="validate")
    def validate_candidate(self, request):
        serializer = CandidateValidationSerializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        data = serializer.validated_data
        try:
            school = School.objects.get(center_number=data["center_number"])
            candidate = Candidate.objects.prefetch_related("registrations__exam", "registrations__subject").get(
                school=school, candidate_number=data["candidate_number"]
            )
        except (School.DoesNotExist, Candidate.DoesNotExist):
            return Response(
                {"detail": "Candidate not found."},
                status=status.HTTP_404_NOT_FOUND,
            )
        profile = CandidateProfileSerializer(candidate)
        return Response(profile.data)
