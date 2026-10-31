"""Framework migrations stay disabled; P06 navigation metadata is provisioned explicitly."""


METADATA_SCHEMA = "django_candidate"


class MetadataRouter:
    def db_for_read(self, model, **hints):
        return None

    def db_for_write(self, model, **hints):
        return None

    def allow_relation(self, obj1, obj2, **hints):
        return False

    def allow_migrate(self, db, app_label, model_name=None, **hints):
        return False
