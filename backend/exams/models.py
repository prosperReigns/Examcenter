from django.conf import settings
from django.db import models

from schools.models import School


class Subject(models.Model):
    """Subjects are centrally managed by Admins and reused across exams."""

    name = models.CharField(max_length=255, unique=True)

    def __str__(self) -> str:
        return self.name


class Exam(models.Model):
    """An exam belongs to a subject and is scoped by exam_year."""

    subject = models.ForeignKey(Subject, on_delete=models.CASCADE, related_name="exams")
    exam_year = models.CharField(max_length=9, db_index=True)
    title = models.CharField(max_length=255)
    duration_minutes = models.PositiveIntegerField()
    is_active = models.BooleanField(default=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.PROTECT,
        related_name="created_exams",
    )
    created_at = models.DateTimeField(auto_now_add=True)

    def __str__(self) -> str:
        return f"{self.title} ({self.exam_year})"


class Candidate(models.Model):
    """Candidate identity anchored to a school and unique candidate number."""

    school = models.ForeignKey(School, on_delete=models.CASCADE, related_name="candidates")
    candidate_number = models.CharField(max_length=50)
    full_name = models.CharField(max_length=255)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["school", "candidate_number"],
                name="unique_candidate_per_center",
            )
        ]

    def __str__(self) -> str:
        return f"{self.full_name} ({self.candidate_number})"


class CandidateRegistration(models.Model):
    """Registration ties a candidate to a specific exam and subject."""

    candidate = models.ForeignKey(Candidate, on_delete=models.CASCADE, related_name="registrations")
    exam = models.ForeignKey(Exam, on_delete=models.CASCADE, related_name="registrations")
    subject = models.ForeignKey(Subject, on_delete=models.PROTECT, related_name="registrations")
    exam_year = models.CharField(max_length=9)
    registered_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["candidate", "exam", "subject"],
                name="unique_candidate_exam_subject",
            )
        ]

    def __str__(self) -> str:
        return f"{self.candidate} - {self.exam}"


class Question(models.Model):
    """Questions are owned by exams and authored by Agents."""

    class QuestionType(models.TextChoices):
        SINGLE_CHOICE = "single_choice", "Single Choice"
        MULTIPLE_CHOICE = "multiple_choice", "Multiple Choice"
        TRUE_FALSE = "true_false", "True/False"
        FILL_BLANK = "fill_blank", "Fill in the Blank"

    exam = models.ForeignKey(Exam, on_delete=models.CASCADE, related_name="questions")
    text = models.TextField()
    question_type = models.CharField(max_length=32, choices=QuestionType.choices)
    correct_text = models.TextField(blank=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.PROTECT,
        related_name="created_questions",
    )
    created_at = models.DateTimeField(auto_now_add=True)

    def __str__(self) -> str:
        return f"{self.exam}: {self.text[:50]}"


class Option(models.Model):
    """Options represent choices for single/multiple choice questions."""

    question = models.ForeignKey(Question, on_delete=models.CASCADE, related_name="options")
    text = models.TextField()
    is_correct = models.BooleanField(default=False)

    def __str__(self) -> str:
        return f"{self.question_id}: {self.text[:40]}"


class Answer(models.Model):
    """Answers submitted by candidates, linked to registration and question."""

    registration = models.ForeignKey(
        CandidateRegistration, on_delete=models.CASCADE, related_name="answers"
    )
    question = models.ForeignKey(Question, on_delete=models.CASCADE, related_name="answers")
    selected_options = models.ManyToManyField(Option, blank=True)
    text_answer = models.TextField(blank=True)
    is_correct = models.BooleanField(default=False)
    submitted_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["registration", "question"],
                name="unique_registration_question",
            )
        ]
