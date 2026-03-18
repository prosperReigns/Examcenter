from django.db import models

from exams.models import CandidateRegistration, Exam


class Result(models.Model):
    """Computed outcome for a candidate registration and exam submission."""

    registration = models.OneToOneField(
        CandidateRegistration, on_delete=models.CASCADE, related_name="result"
    )
    exam = models.ForeignKey(Exam, on_delete=models.CASCADE, related_name="results")
    score = models.PositiveIntegerField()
    total_questions = models.PositiveIntegerField()
    percentage = models.DecimalField(max_digits=5, decimal_places=2)
    created_at = models.DateTimeField(auto_now_add=True)
    status = models.CharField(max_length=50, default="completed")

    def __str__(self) -> str:
        return f"{self.registration} - {self.score}/{self.total_questions}"
