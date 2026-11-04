"""Owner-controlled, audited provider selection; never rewrites a Payment."""
import json

from django.db import connections, transaction

from ..checkout.db import Database
from ..checkout.errors import CheckoutError
from ..checkout.service import CheckoutService
from ..domain.money import require_integer


NEW_QRIS_PROVIDERS = frozenset(('manual', 'klikqris', 'sakurupiah'))


class ProviderSettings:
    def __init__(self, *, readiness, sakurupiah=None, using='default'):
        self.readiness = readiness
        self.using = using
        self.db = Database(using)
        self.sakurupiah = sakurupiah

    def current(self):
        return CheckoutService(using=self.using)._setting(lock=False)

    def change(self, provider, *, expected_version, actor_user_id):
        if provider not in NEW_QRIS_PROVIDERS:
            raise CheckoutError('provider_retired')
        require_integer(expected_version, minimum=0)
        require_integer(actor_user_id, minimum=1)
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)):
            raise RuntimeError('Provider selection owns its outer transaction')
        actor = self.db.one('SELECT role FROM public.users WHERE id=%s', (actor_user_id,))
        if actor is None or actor['role'] not in ('owner', 'admin'):
            raise CheckoutError('operator_forbidden')
        ready = self.readiness.get(provider)
        if ready is None or not ready():
            raise CheckoutError('provider_unavailable')
        with transaction.atomic(using=self.using):
            current_actor = self.db.one('SELECT role FROM public.users WHERE id=%s', (actor_user_id,))
            if current_actor is None or current_actor['role'] not in ('owner', 'admin'):
                raise CheckoutError('operator_forbidden')
            # Serialize even the first selection; no implicit configuration insert.
            self.db.one("SELECT pg_advisory_xact_lock(hashtextextended('payment_provider.switch',0))")
            row = self.db.one('''SELECT id,payment_mode,payment_provider_version,is_active
                FROM public.manual_payment_settings ORDER BY is_active DESC,id LIMIT 1 FOR UPDATE''')
            if row is None:
                raise CheckoutError('payment_setting_missing')
            if row['payment_provider_version'] != expected_version:
                raise CheckoutError('provider_switch_conflict')
            if row['is_active'] and row['payment_mode'] == provider:
                return expected_version
            version = expected_version + 1
            self.db.execute('''UPDATE public.manual_payment_settings SET payment_mode=%s,
                payment_provider_version=%s,is_active=true,updated_at=now() WHERE id=%s''',
                (provider, version, row['id']))
            self.db.execute('''INSERT INTO public.audit_logs
                (user_id,action,subject_type,subject_id,new_values,created_at)
                VALUES (%s,'payment_provider.switched','App\\Models\\ManualPaymentSetting',%s,%s,now())''',
                (actor_user_id, str(row['id']), json.dumps({'old_provider': row['payment_mode'],
                                                           'provider': provider, 'version': version})))
            return version

    def change_channel(self, channel, *, expected_version, actor_user_id):
        require_integer(expected_version, minimum=0)
        require_integer(actor_user_id, minimum=1)
        if any(c.in_atomic_block or not c.get_autocommit() for c in connections.all(initialized_only=True)):
            raise RuntimeError('Channel selection owns its outer transaction')
        actor = self.db.one('SELECT role FROM public.users WHERE id=%s', (actor_user_id,))
        if actor is None or actor['role'] != 'owner':
            raise CheckoutError('operator_forbidden')
        if channel not in ('QRIS', 'QRISC', 'QRISMU', 'QRIS2') or self.sakurupiah is None:
            raise CheckoutError('provider_unavailable')
        configuration = getattr(self.sakurupiah, 'config', None)
        try:
            observed = self.sakurupiah.list_channels()
        except Exception:
            raise CheckoutError('provider_unavailable') from None
        matches = [value for value in observed if value.code == channel]
        if len(matches) != 1 or not matches[0].active:
            raise CheckoutError('provider_unavailable')
        with transaction.atomic(using=self.using):
            current_actor = self.db.one('SELECT role FROM public.users WHERE id=%s', (actor_user_id,))
            if current_actor is None or current_actor['role'] != 'owner':
                raise CheckoutError('operator_forbidden')
            setting = CheckoutService(using=self.using)._setting(lock=True)
            if setting is None:
                raise CheckoutError('payment_setting_missing')
            if expected_version != setting.version:
                raise CheckoutError('provider_switch_conflict')
            if configuration != getattr(self.sakurupiah, 'config', None):
                raise CheckoutError('provider_unavailable')
            if channel == setting.sakurupiah_channel:
                return expected_version
            self.db.execute('''UPDATE public.manual_payment_settings SET sakurupiah_channel=%s,
                payment_provider_version=%s,updated_at=now() WHERE id=%s''',
                (channel, expected_version + 1, setting.id))
            self.db.execute('''INSERT INTO public.audit_logs
                (user_id,action,subject_type,subject_id,new_values,created_at)
                VALUES (%s,'sakurupiah_channel.updated','App\\Models\\ManualPaymentSetting',%s,%s,now())''',
                (actor_user_id, str(setting.id), json.dumps({'old_channel': setting.sakurupiah_channel,
                    'sakurupiah_channel': channel, 'payment_provider_version': expected_version + 1})))
            return expected_version + 1
