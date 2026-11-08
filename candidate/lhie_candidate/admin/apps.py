"""Explicit app label keeps the custom workspace separate from Django admin."""

from django.apps import AppConfig


class AdminWorkspaceConfig(AppConfig):
    name = "lhie_candidate.admin"
    label = "lhie_admin"
    verbose_name = "LHIE admin workspace"
    default_auto_field = "django.db.models.BigAutoField"
