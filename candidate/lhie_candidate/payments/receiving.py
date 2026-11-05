"""Owner-managed public destinations, independent of provider credentials and activation."""
from dataclasses import dataclass, field
from datetime import datetime
import json
import re
from zoneinfo import ZoneInfo

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..domain.money import require_integer


def _binance_receiver(receiver_type, receiver_value):
    if receiver_type not in (None, '', 'pay_id', 'uid'):
        raise CheckoutError('binance_receiver_invalid')
    if receiver_value is None or receiver_value == '':
        return None, None
    if (receiver_type not in ('pay_id', 'uid') or type(receiver_value) is not str
            or re.fullmatch(r'[0-9]{1,57}', receiver_value) is None):
        raise CheckoutError('binance_receiver_invalid')
    return receiver_type, receiver_value


@dataclass(frozen=True, slots=True)
class PublicReceivingConfiguration:
    id: int
    version: int
    binance_receiver_type: str | None
    binance_receiver_value: str | None = field(repr=False)
    updated_at: datetime | None


class PublicReceivingSettings:
    def __init__(self, *, using='default'):
        self.using = using
        self.db = Database(using)

    def _require_outer_transaction(self):
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)):
            raise RuntimeError('Public receiving configuration owns its outer transaction')

    def _require_owner(self, actor_user_id, *, lock=False):
        suffix = ' FOR SHARE' if lock else ''
        actor = self.db.one('SELECT role FROM public.users WHERE id=%s' + suffix, (actor_user_id,))
        if actor is None or actor['role'] != 'owner':
            raise CheckoutError('operator_forbidden')

    def _configuration(self, *, lock=False):
        suffix = ' FOR UPDATE' if lock else ''
        row = self.db.one('''SELECT id,payment_provider_version,binance_receiver_type,
            binance_receiver_value,updated_at FROM public.manual_payment_settings
            ORDER BY is_active DESC,id LIMIT 1''' + suffix)
        if row is None:
            raise CheckoutError('payment_setting_missing')
        return PublicReceivingConfiguration(row['id'], row['payment_provider_version'],
            row['binance_receiver_type'], row['binance_receiver_value'], row['updated_at'])

    def current(self, *, actor_user_id):
        require_integer(actor_user_id, minimum=1)
        self._require_outer_transaction()
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            self._require_owner(actor_user_id, lock=True)
            return self._configuration()

    def change_binance(self, receiver_type, receiver_value, *, expected_version, actor_user_id):
        require_integer(expected_version, minimum=0)
        require_integer(actor_user_id, minimum=1)
        receiver_type, receiver_value = _binance_receiver(receiver_type, receiver_value)
        self._require_outer_transaction()
        self._require_owner(actor_user_id)
        with transaction.atomic(using=self.using):
            self.db.require_transaction()
            self._require_owner(actor_user_id, lock=True)
            self.db.one("SELECT pg_advisory_xact_lock(hashtextextended('payment_provider.switch',0))")
            setting = self._configuration(lock=True)
            if setting.version != expected_version:
                raise CheckoutError('provider_switch_conflict')
            if (setting.binance_receiver_type, setting.binance_receiver_value) == (receiver_type, receiver_value):
                return expected_version
            version = expected_version + 1
            at = datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
            self.db.execute('''UPDATE public.manual_payment_settings SET binance_receiver_type=%s,
                binance_receiver_value=%s,payment_provider_version=%s,updated_at=%s WHERE id=%s''',
                (receiver_type, receiver_value, version, at, setting.id))
            self.db.execute('''INSERT INTO public.audit_logs
                (user_id,action,subject_type,subject_id,new_values,created_at)
                VALUES (%s,'binance_receiver.updated','App\\Models\\ManualPaymentSetting',%s,%s,%s)''',
                (actor_user_id, str(setting.id), json.dumps({
                    'old_receiver_type': setting.binance_receiver_type,
                    'old_receiver_value': '[redacted]' if setting.binance_receiver_value is not None else None,
                    'receiver_type': receiver_type,
                    'receiver_value': '[redacted]' if receiver_value is not None else None,
                    'payment_provider_version': version}), at))
            return version
