"""Resolve a registered IDR refund without creating a second entitlement."""
from ..auth.types import legacy_now
from ..checkout.lifecycle import OrderLifecycle
from ..checkout.refunds import OrderRefunds
from ..domain.money import require_integer
from .password import AdjustmentRejected

REFUND_COLUMNS = ('id,order_id,payment_id,customer_id,warranty_claim_id,supplier_account_unit_id,'
                  'supplier_fulfillment_id,missing_quantity,amount,currency,state,external_reference,'
                  'external_reference_hash')


def eligible(refund, customer_id, amount):
    return (refund is not None and refund['customer_id'] == customer_id and refund['amount'] == amount
            and refund['currency'] == 'IDR' and refund['state'] == 'requested'
            and refund['external_reference_hash'] is None and refund['external_reference'] is None)


def apply(db, ledger, account, customer, preview, *, encrypter=None):
    db.require_transaction()
    initial = db.one('SELECT ' + REFUND_COLUMNS + ' FROM public.order_refunds WHERE id=%s', (preview['source_id'],))
    if not eligible(initial, customer['id'], preview['amount']):
        raise AdjustmentRejected('refund_resolution_unavailable')
    lifecycle = OrderLifecycle(using=db.using, encrypter=encrypter)
    order, payment, _, _ = lifecycle.lock_payment(initial['payment_id'])
    lifecycle.fulfillment.lock(order['id'])
    lifecycle.fulfillment.assert_refundable(order['id'], lifecycle.clock())
    if (order['customer_id'] != customer['id'] or payment['currency'] != 'IDR'
            or payment['status'] != 'paid' or payment['paid_at'] is None or order['payment_status'] != 'paid'
            or payment['provider'] == 'usdt_balance' or lifecycle._open_review(order['id'])):
        raise AdjustmentRejected('refund_resolution_unavailable')
    refunds = db.rows('SELECT ' + REFUND_COLUMNS + ' FROM public.order_refunds WHERE payment_id=%s ORDER BY id FOR UPDATE', (payment['id'],))
    refund = next((row for row in refunds if str(row['id']) == str(initial['id'])), None)
    if (not eligible(refund, customer['id'], preview['amount'])
            or str(refund['order_id']) != str(order['id'])
            or any(str(row['order_id']) != str(order['id']) or row['customer_id'] != customer['id']
                   or row['currency'] != 'IDR' or row['state'] not in ('requested', 'uncertain', 'confirmed') for row in refunds)
            or sum(require_integer(row['amount'], minimum=1) for row in refunds) > payment['amount']):
        raise AdjustmentRejected('refund_resolution_unavailable')
    authority = OrderRefunds(using=db.using, encrypter=encrypter)
    if refund['warranty_claim_id'] is not None:
        claim = db.one('SELECT settlement_state FROM public.warranty_claims WHERE id=%s FOR UPDATE',
                       (refund['warranty_claim_id'],))
        if claim is None or claim['settlement_state'] != 'refund_pending':
            raise AdjustmentRejected('refund_entitlement_unavailable')
    authority._assert_historical_safety(order['id'])
    authority._assert_entitlement(order, payment, refund)
    if payment['provider'] == 'store_balance':
        result = authority.apply_locked(order, payment, account=dict(account, customer_id=customer['id'], provider='store_balance'), refund_id=refund['id'])
        if not result.newly_applied or result.entry is None:
            raise AdjustmentRejected('original_source_already_resolved')
        entry = result.entry
        db.execute('UPDATE public.order_refunds SET actor_user_id=%s WHERE id=%s', (preview['owner_user_id'], refund['id']))
    else:
        source = str(refund['id'])
        key = 'order-refund:' + source
        if ledger._find_source_entry(account, 'refund_credit', refund['amount'], 'order_refund', source, key, refund_order_id=order['id']):
            raise AdjustmentRejected('original_source_already_resolved')
        entry = ledger._post(account, 'refund_credit', refund['amount'], source, key,
                             source_type='order_refund', refund_order_id=order['id'])
        now = legacy_now()
        refund = db.one("UPDATE public.order_refunds SET state='confirmed',confirmed_at=%s,actor_user_id=%s,updated_at=%s WHERE id=%s RETURNING " + REFUND_COLUMNS, (now, preview['owner_user_id'], now, refund['id']))
        authority._settle_origin(refund)
    confirmed = db.one("SELECT COALESCE(SUM(amount),0) AS amount FROM public.order_refunds WHERE payment_id=%s AND state='confirmed'", (payment['id'],))['amount']
    if confirmed == payment['amount']:
        lifecycle._statuses(order, payment, payment_status='refunded')
    return entry
