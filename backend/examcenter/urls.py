"""
URL configuration for examcenter project.

The `urlpatterns` list routes URLs to views. For more information please see:
    https://docs.djangoproject.com/en/6.0/topics/http/urls/
Examples:
Function views
    1. Add an import:  from my_app import views
    2. Add a URL to urlpatterns:  path('', views.home, name='home')
Class-based views
    1. Add an import:  from other_app.views import Home
    2. Add a URL to urlpatterns:  path('', Home.as_view(), name='home')
Including another URLconf
    1. Import the include() function: from django.urls import include, path
    2. Add a URL to urlpatterns:  path('blog/', include('blog.urls'))
"""
from django.contrib import admin
from django.urls import include, path
from rest_framework.routers import DefaultRouter
from rest_framework_simplejwt.views import TokenObtainPairView, TokenRefreshView

from exams.views import CandidateViewSet, ExamViewSet, QuestionViewSet, SubjectViewSet
from results.views import ResultViewSet
from schools.views import SchoolViewSet
from users.views import AdminUserViewSet, AgentUserViewSet

router = DefaultRouter()
router.register("admins", AdminUserViewSet, basename="admin-users")
router.register("agents", AgentUserViewSet, basename="agent-users")
router.register("schools", SchoolViewSet, basename="schools")
router.register("subjects", SubjectViewSet, basename="subjects")
router.register("exams", ExamViewSet, basename="exams")
router.register("questions", QuestionViewSet, basename="questions")
router.register("candidates", CandidateViewSet, basename="candidates")
router.register("results", ResultViewSet, basename="results")

urlpatterns = [
    path("admin/", admin.site.urls),
    path("api/auth/token/", TokenObtainPairView.as_view(), name="token_obtain_pair"),
    path("api/auth/token/refresh/", TokenRefreshView.as_view(), name="token_refresh"),
    path("api/", include(router.urls)),
]
