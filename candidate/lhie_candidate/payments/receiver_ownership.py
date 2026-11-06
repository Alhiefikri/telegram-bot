"""Permanent channel claims for BSC receiver addresses.

Call within the transaction that inserts the associated receiver. This module
never provisions, verifies, activates, or changes an existing claim.
"""
import re
from datetime import datetime
from zoneinfo import ZoneInfo


class ReceiverAddressConflict(ValueError):
    pass


def normalize_address(address):
    if not isinstance(address, str):
        raise ValueError('Invalid receiver address')
    normalized = re.sub(r'^[\s\ufeff]+|[\s\ufeff]+$', '', address).lower()
    if re.fullmatch(r'0x[0-9a-f]{40}', normalized) is None:
        raise ValueError('Invalid receiver address')
    return normalized


def find_address_claim(db, *, chain_id, address):
    if type(chain_id) is not int or chain_id <= 0:
        raise ValueError('Invalid receiver chain')
    address = normalize_address(address)
    return db.one('''SELECT id,chain_id,address_normalized,channel
        FROM public.usdt_receiver_address_claims WHERE chain_id=%s AND address_normalized=%s''',
        (chain_id, address))


def find_deposit_receiver(db, *, chain_id, address):
    """Resolve permanent ownership, including retired addresses monitored for late money."""
    if type(chain_id) is not int or chain_id <= 0:
        raise ValueError('Invalid receiver chain')
    address = normalize_address(address)
    return db.one('''SELECT r.id,r.customer_id,r.address_claim_id,r.chain_id,r.address_normalized,
        r.token_contract,r.token_decimals,r.custody_namespace,r.verification_status,r.retired_at
        FROM public.usdt_receiver_address_claims c
        JOIN public.customer_usdt_deposit_receivers r ON r.address_claim_id=c.id
        WHERE c.chain_id=%s AND c.address_normalized=%s AND c.channel='customer_deposit'
          AND r.chain_id=c.chain_id AND r.address_normalized=c.address_normalized''',
        (chain_id, address))


def claim_address(db, *, chain_id, address, channel, now=None):
    """Claim a channel, permitting only an unbound checkout claim to be reused."""
    db.require_transaction()
    if type(chain_id) is not int or chain_id <= 0 or channel not in ('checkout', 'customer_deposit', 'shared_top_up'):
        raise ValueError('Invalid receiver claim')
    address = normalize_address(address)
    now = now or datetime.now(ZoneInfo('Asia/Jakarta')).replace(tzinfo=None)
    db.one('SELECT pg_advisory_xact_lock(hashtextextended(%s, 0)) AS locked',
           (f'usdt-receiver|{chain_id}|{address}',))
    inserted = db.one('''INSERT INTO public.usdt_receiver_address_claims
        (chain_id,address_normalized,channel,claimed_at) VALUES (%s,%s,%s,%s)
        ON CONFLICT (chain_id,address_normalized) DO NOTHING RETURNING id,chain_id,address_normalized,channel''',
        (chain_id, address, channel, now))
    if inserted is not None:
        return inserted
    claim = db.one('''SELECT id,chain_id,address_normalized,channel FROM public.usdt_receiver_address_claims
        WHERE chain_id=%s AND address_normalized=%s''', (chain_id, address))
    if claim is None or claim['channel'] != channel or channel != 'checkout':
        raise ReceiverAddressConflict('Receiver address is permanently claimed')
    bound = db.one('SELECT id FROM public.bsc_payment_receivers WHERE address_claim_id=%s', (claim['id'],))
    if bound is not None:
        raise ReceiverAddressConflict('Checkout receiver is already registered')
    return claim


def shared_checkout_claim(db, *, chain_id, address, token_contract, token_decimals):
    """Return an eligible checkout claim for shared top-ups, without changing it."""
    db.require_transaction()
    address = normalize_address(address)
    token_contract = normalize_address(token_contract)
    if type(chain_id) is not int or chain_id <= 0 or type(token_decimals) is not int or not 0 <= token_decimals <= 255:
        raise ValueError('Invalid receiver token')
    return db.one('''SELECT c.id,c.chain_id,c.address_normalized,c.channel
        FROM public.usdt_receiver_address_claims c
        JOIN public.bsc_payment_receivers r ON r.address_claim_id=c.id
        WHERE c.chain_id=%s AND c.address_normalized=%s AND c.channel='checkout'
          AND r.chain_id=%s AND r.token_contract=%s AND r.token_decimals=%s
          AND r.is_active AND r.verification_status IN ('verified','manual_verified')
        FOR UPDATE OF r''', (chain_id, address, chain_id, token_contract, token_decimals))
