"""Reviewed P03 mappings; never installed or migrated by candidate startup.

These are compatibility projections, not domain write services. Database grants
remain the write boundary; P07/P19 must supply transaction/ownership authority.
"""
from datetime import UTC
from decimal import Decimal
from zoneinfo import ZoneInfo

from django.db import models


LEGACY_ZONE = ZoneInfo("Asia/Jakarta")


class LegacyTimestamp(models.DateTimeField):
    """Laravel timestamp without time zone stores application-local wall time."""

    def db_type(self, connection):
        return "timestamp(0) without time zone"

    def from_db_value(self, value, expression, connection):
        if value is None:
            return None
        if value.tzinfo is not None:
            raise ValueError("Legacy timestamp must be a local wall time")
        return value.replace(tzinfo=LEGACY_ZONE).astimezone(UTC)

    def get_prep_value(self, value):
        if value is None or hasattr(value, "resolve_expression"):
            return value
        if not hasattr(value, "utcoffset") or value.utcoffset() is None:
            raise ValueError("Legacy timestamp requires an aware instant")
        return value.astimezone(LEGACY_ZONE).replace(tzinfo=None, microsecond=0)

    def get_db_prep_value(self, value, connection, prepared=False):
        return value if prepared else self.get_prep_value(value)


class ExactBigInteger(models.BigIntegerField):
    def get_prep_value(self, value):
        if value is None:
            return None
        if type(value) is not int or not -(2 ** 63) <= value < 2 ** 63:
            raise ValueError("Legacy bigint requires an exact signed 64-bit integer")
        return value


class ExactUnits(models.DecimalField):
    def get_prep_value(self, value):
        if value is None:
            return None
        if not isinstance(value, (int, Decimal)) or isinstance(value, bool):
            raise ValueError("Exact units require integer or Decimal")
        value = Decimal(value)
        if not value.is_finite() or value != value.to_integral_value() or value.copy_abs() >= 10 ** self.max_digits:
            raise ValueError("Exact units exceed database precision")
        return value

    def get_db_prep_value(self, value, connection, prepared=False):
        return self.get_prep_value(value)


class LegacyCustomer(models.Model):
    id = models.BigAutoField(primary_key=True)
    telegram_id = models.BigIntegerField(null=True, unique=True)
    first_name = models.CharField(max_length=255)
    username = models.CharField(max_length=255, null=True)
    phone_number = models.CharField(max_length=255, null=True)
    email = models.CharField(max_length=255, null=True, unique=True)
    email_verified_at = LegacyTimestamp(null=True)
    password = models.CharField(max_length=255, null=True)
    password_scheme = models.CharField(max_length=255, db_default="raw")
    auth_version = models.BigIntegerField(db_default=0)
    enrollment_version = models.BigIntegerField(db_default=0)
    remember_token = models.CharField(max_length=100, null=True)
    created_at = LegacyTimestamp(null=True)
    updated_at = LegacyTimestamp(null=True)

    class Meta:
        app_label = "legacy_compat"
        managed = False
        db_table = '"public"."customers"'


class LegacyCustomerIdentity(models.Model):
    id = models.BigAutoField(primary_key=True)
    customer = models.ForeignKey(LegacyCustomer, models.DO_NOTHING, db_column="customer_id")
    provider = models.CharField(max_length=255)
    provider_subject = models.CharField(max_length=255)
    provider_email = models.CharField(max_length=255, null=True)
    last_used_at = LegacyTimestamp(null=True)
    created_at = LegacyTimestamp(null=True)
    updated_at = LegacyTimestamp(null=True)

    class Meta:
        app_label = "legacy_compat"
        managed = False
        db_table = '"public"."customer_identities"'


class LegacyUsdtAccount(models.Model):
    id = models.BigAutoField(primary_key=True)
    customer = models.OneToOneField(LegacyCustomer, models.DO_NOTHING, db_column="customer_id")
    currency = models.CharField(max_length=4, db_default="USDT")
    balance_micro = ExactBigInteger(db_default=0)
    version = models.BigIntegerField(db_default=0)
    last_entry_id = models.BigIntegerField(null=True)
    frozen_reason = models.CharField(max_length=191, null=True)
    created_at = LegacyTimestamp(null=True)
    updated_at = LegacyTimestamp(null=True)

    class Meta:
        app_label = "legacy_compat"
        managed = False
        db_table = '"public"."customer_usdt_balance_accounts"'


class LegacyBscTransferEvidence(models.Model):
    id = models.BigAutoField(primary_key=True)
    amount_units = ExactUnits(max_digits=78, decimal_places=0)

    class Meta:
        app_label = "legacy_compat"
        managed = False
        db_table = '"public"."bsc_payment_transfer_evidence"'
