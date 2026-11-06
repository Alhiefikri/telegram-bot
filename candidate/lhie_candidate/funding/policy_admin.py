"""Versioned, audited Store Balance policy changes for authenticated operators."""
from dataclasses import dataclass
import json

from django.db import DatabaseError, connections, transaction

from lhie_candidate.checkout.db import Database
from lhie_candidate.domain.money import MAX_UNITS, require_integer

from .policy import FundingPolicy, PolicyError, SINGLETON_KEY, parse_amount, policy_from_row


@dataclass(frozen=True, slots=True)
class PolicyChange:
    previous: FundingPolicy
    current: FundingPolicy
    changed: bool


class FundingPolicyAdmin:
    def __init__(self, using='default'):
        self.using = using
        self.db = Database(using)

    def change(self, minimum_amount, preset_amounts, *, expected_version, actor_user_id, ip=None):
        require_integer(expected_version)
        require_integer(actor_user_id, minimum=1)
        if connections[self.using].in_atomic_block or not connections[self.using].get_autocommit():
            raise RuntimeError('Funding policy change owns its outer transaction')
        minimum = parse_amount(minimum_amount)
        if minimum is None or not isinstance(preset_amounts, list):
            raise PolicyError('invalid funding policy')
        amounts = tuple(parse_amount(amount) for amount in preset_amounts)
        if any(amount is None for amount in amounts):
            raise PolicyError('invalid funding policy')
        definition = FundingPolicy(0, minimum, amounts)
        for attempt in range(5):
            try:
                with transaction.atomic(using=self.using):
                    return self._change_locked(definition, expected_version, actor_user_id, ip)
            except DatabaseError as error:
                if getattr(error.__cause__, 'sqlstate', None) not in ('40001', '40P01') or attempt == 4:
                    raise
        raise RuntimeError('Unreachable policy retry state')

    def _change_locked(self, definition, expected_version, actor_user_id, ip):
        self.db.require_transaction()
        actor = self.db.one('SELECT id,role FROM public.users WHERE id=%s FOR SHARE', (actor_user_id,))
        if actor is None or actor['role'] not in ('owner', 'admin'):
            raise PolicyError('operator_forbidden')
        row = self.db.one('''SELECT id,version,minimum_amount,preset_amounts
            FROM public.store_balance_funding_policies WHERE singleton_key=%s FOR UPDATE''', (SINGLETON_KEY,))
        old = policy_from_row(row)
        if old is None:
            raise PolicyError('funding_policy_missing')
        if old.version != expected_version:
            raise PolicyError('funding_policy_conflict')
        if (old.minimum_amount, old.preset_amounts) == (definition.minimum_amount, definition.preset_amounts):
            return PolicyChange(old, old, False)
        if old.version == MAX_UNITS:
            raise PolicyError('funding_policy_version_overflow')
        new = FundingPolicy(old.version + 1, definition.minimum_amount, definition.preset_amounts)
        self.db.execute('''UPDATE public.store_balance_funding_policies
            SET minimum_amount=%s,preset_amounts=%s,version=%s,
                updated_at=CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta' WHERE id=%s''',
            (new.minimum_amount, json.dumps(new.preset_amounts), new.version, row['id']))
        def values(policy):
            return json.dumps({'minimum_amount': policy.minimum_amount, 'preset_amounts': policy.preset_amounts})
        self.db.execute('''INSERT INTO public.audit_logs
            (user_id,action,subject_type,subject_id,old_values,new_values,ip_address,created_at)
            VALUES (%s,'store_balance.funding_policy.updated','App\\Models\\StoreBalanceFundingPolicy',
                    %s,%s,%s,%s,CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Jakarta')''',
            (actor_user_id, str(row['id']), values(old), values(new), ip))
        return PolicyChange(old, new, True)
