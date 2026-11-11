"""Direct PHP-source oracle comparisons for P25 presentation outputs."""
import base64
from datetime import datetime, timedelta
from html.parser import HTMLParser
from io import BytesIO
from zoneinfo import ZoneInfo
from uuid import uuid4


class _VisibleHTML(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.text = []
        self.links = []
        self._link = None

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            self._link = [dict(attrs).get('href', ''), []]

    def handle_data(self, data):
        self.text.append(data)
        if self._link is not None:
            self._link[1].append(data)

    def handle_endtag(self, tag):
        if tag == 'a' and self._link is not None:
            href, text = self._link
            self.links.append((href, ' '.join(''.join(text).split())))
            self._link = None


def _html_signature(value):
    parser = _VisibleHTML()
    parser.feed(value)
    return (' '.join(''.join(parser.text).split()), tuple(parser.links))


def _wire(method, payload):
    from copy import deepcopy
    from types import SimpleNamespace

    requests = []

    def send(actual_method, actual_payload):
        requests.append((actual_method, deepcopy(actual_payload)))
        return {'ok': True, 'result': {'message_id': 707}}

    from lhie_candidate.notifications.transport import NotificationTransport
    transport = NotificationTransport()
    transport.relay = SimpleNamespace(_request=send)
    result = transport.execute(method, payload)
    assert result.outcome == 'success' and result.message_id == 707
    assert len(requests) == 1
    return requests[0][1]


def _without_php_icon_marker(value):
    return value.replace(' data-omp-icon="1"', '')


def _assert_wire(actual, expected):
    actual, expected = dict(actual), dict(expected)
    for field in ('text', 'caption'):
        if field in actual:
            actual[field] = _without_php_icon_marker(actual[field])
        if field in expected:
            expected[field] = _without_php_icon_marker(expected[field])
    assert actual == expected, {'candidate_wire': actual, 'php_wire': expected}


def exercise_php_notification_parity(fixtures, admin):
    from PIL import Image, ImageChops, ImageStat

    from hashlib import sha256
    from types import SimpleNamespace

    from django.test import override_settings

    from lhie_candidate.checkout.db import Database
    from lhie_candidate.notifications import balance, broadcast, lifecycle, price, proof_publication, stock, waitlist
    from lhie_candidate.notifications.bulk import PRICE_CHANNEL
    from lhie_candidate.notifications.bulk_content import icons as candidate_icons
    from lhie_candidate.notifications.guest_mail import milestone_content
    from lhie_candidate.notifications.mail import credential_context, credential_html, credential_text
    from lhie_candidate.notifications.policy import NotEligible, TestRecipients
    from lhie_candidate.notifications.publishing import BroadcastPublishing
    from lhie_candidate.notifications.proof import SalesProofEligibility
    from lhie_candidate.payments.presentation import QrPresentation

    oracle = fixtures['p25']
    icons = oracle['ui_icons']
    assert candidate_icons(Database()) == icons
    lifecycle_fixture = oracle['lifecycle_admin']
    lifecycle_service = SimpleNamespace(db=Database())
    lifecycle_adapter = lifecycle.LifecycleNotices(lifecycle_service)
    for case in lifecycle_fixture['cases'].values():
        base_url = case['dashboard_url'].split('/admin/orders/', 1)[0]
        with override_settings(CANDIDATE_LEGACY_APP_URL=base_url):
            candidate_payload, _ = lifecycle_adapter._render(
                case['kind'], case['order'], [case['payment']], case['items'], '1',
            )
        source_payload = case['wire']
        candidate_wire = _wire('sendMessage', candidate_payload)
        _assert_wire(candidate_wire, source_payload)
    owner_fixture = oracle['balance_owner']
    for paid, source_key in ((False, 'owner'), (True, 'paid')):
        owner_row = owner_fixture['paid_row'] if paid else owner_fixture['row']
        owner_payload = balance.render_owner(owner_row, '1', icons, paid=paid)
        owner_wire = _wire('sendMessage', owner_payload)
        expected_owner = owner_fixture[source_key]
        _assert_wire(owner_wire, expected_owner)
    proof_fixture = oracle['balance_proof']
    proof_row = proof_fixture['row']
    assert balance.proof_eligible(proof_row) is proof_fixture['eligible']
    for source_case in proof_fixture['eligibility_cases'].values():
        assert balance.proof_eligible(source_case['row']) is source_case['eligible'] is False
    proof_wire = _wire('sendPhoto', balance.render_proof(proof_row, proof_row['chat_id'], icons))
    expected_proof_wire = dict(proof_fixture['wire'], photo=proof_fixture['photo_file_id'])
    assert proof_wire == expected_proof_wire, {
        'candidate_wire': proof_wire, 'php_wire': expected_proof_wire,
    }
    receipt_fixture = oracle['balance_receipt']['by_locale']
    for locale in ('id', 'en', 'ms'):
        receipt_case = receipt_fixture[locale]
        assert receipt_case['delivery_outcome'] == 'sent'
        receipt_payload = balance.render_receipt(
            receipt_case['row'], receipt_case['recipient_chat_id'], locale, icons,
        )
        receipt_wire = _wire('sendMessage', receipt_payload)
        _assert_wire(receipt_wire, receipt_case['wire'])
    instruction_fixture = oracle['balance_instructions']
    instruction_payload = balance.render_instructions(instruction_fixture['row'], '1', 'en', icons)
    instruction_wire = _wire('sendPhoto', instruction_payload)
    instruction_request = {key: value for key, value in instruction_wire.items() if key != 'photo'}
    assert instruction_request == instruction_fixture['wire'], {
        'candidate_wire': instruction_request, 'php_wire': instruction_fixture['wire'],
    }
    manual_qr = QrPresentation(public_storage='/run/harness/public-assets').manual()
    assert sha256(manual_qr).hexdigest() == instruction_fixture['manual_qr_sha256']




    cart_icon = next(row for row in icons if row['key'] == 'message.cart')
    assert cart_icon['custom_emoji_id'] == '987654321' and cart_icon['fallback_emoji'] == '🛍️'
    broadcast_fixture = oracle['broadcast']
    authored = dict(broadcast_fixture['input'], ui_icons=icons)
    text_authored = dict(authored, photo_file_id=None)
    for locale in ('id', 'en', 'ms'):
        effect = {'telegram_chat_id': '1', 'locale': locale}
        expected = broadcast_fixture['by_locale'][locale]
        message_wire = _wire('sendMessage', broadcast.payload(text_authored, effect))
        _assert_wire(message_wire, expected['message'])
        photo_wire = _wire('sendPhoto', broadcast.payload(authored, effect))
        assert photo_wire['photo'] == authored['photo_file_id']
        photo_request = {key: value for key, value in photo_wire.items() if key != 'photo'}
        _assert_wire(photo_request, expected['photo'])
    publisher = BroadcastPublishing(SimpleNamespace(
        db=Database(), recipients=TestRecipients(frozenset({'1'})),
    ))
    purchase_source = oracle['broadcast_purchase_button']
    purchase_draft = publisher.draft(
        'Admin-authored product button',
        photo_file_id='p25-purchase-photo-reference',
        product_id=purchase_source['product_id'],
    )
    assert purchase_draft['product_name'] == purchase_source['name']
    for locale in ('id', 'en', 'ms'):
        purchase_wire = _wire('sendMessage', {
            'chat_id': '1',
            'text': 'Synthetic Broadcast product button',
            'parse_mode': 'HTML',
            'reply_markup': purchase_draft['reply_markup'][locale],
        })
        _assert_wire(purchase_wire, purchase_source['wire_by_locale'][locale])

    photo_only_source = oracle['broadcast_photo_only']
    assert photo_only_source['content']['error'] is None
    photo_only_draft = publisher.draft(
        photo_only_source['content']['message'],
        photo_only_source['content']['photo_file_id'],
    )
    photo_only_wire = _wire('sendPhoto', broadcast.payload(
        {'message': photo_only_draft['message'],
         'photo_file_id': photo_only_draft['photo_file_id'],
         'reply_markup': {}, 'ui_icons': icons},
        {'telegram_chat_id': '1', 'locale': 'en'},
    ))
    assert photo_only_wire['photo'] == photo_only_source['content']['photo_file_id']
    photo_only_request = {key: value for key, value in photo_only_wire.items() if key != 'photo'}
    _assert_wire(photo_only_request, photo_only_source['wire'])

    stock_fixture = oracle['stock']
    for locale in ('id', 'en', 'ms'):
        customer_text, customer_markup = stock.render(
            stock_fixture['product'], stock_fixture['movement'], stock_fixture['event'], locale, ui_icons=icons,
        )
        customer_wire = _wire('sendMessage', {
            'chat_id': '1', 'text': customer_text, 'parse_mode': 'HTML', 'reply_markup': customer_markup,
        })
        expected = stock_fixture['customer_by_locale'][locale]
        _assert_wire(customer_wire, expected)
    channel_text, channel_markup = stock.render(
        stock_fixture['product'], stock_fixture['movement'], stock_fixture['event'], 'en',
        store_channel=True, ui_icons=icons, bot_username=oracle['browser_username'],
    )
    channel_wire = _wire('sendMessage', {
        'chat_id': '1', 'text': channel_text, 'parse_mode': 'HTML', 'reply_markup': channel_markup,
    })
    _assert_wire(channel_wire, stock_fixture['channel'])

    price_fixture = oracle['price']
    event = dict(price_fixture['event'], ui_icons=icons)
    for locale in ('id', 'en', 'ms'):
        customer_effect = {'telegram_chat_id': '1', 'locale': locale, 'effect_type': 'price_change.recipient'}
        customer_wire = _wire('sendMessage', price.payload(event, customer_effect))
        expected = price_fixture['customer_by_locale'][locale]
        _assert_wire(customer_wire, expected)
    channel_effect = {'telegram_chat_id': '1', 'locale': 'en', 'effect_type': PRICE_CHANNEL}
    channel_wire = _wire('sendMessage', price.payload(event, channel_effect))
    _assert_wire(channel_wire, price_fixture['channel'])

    source_order = oracle['source_order']
    assert source_order['eligible'] is True, source_order['rejection_reason']
    candidate_order = SalesProofEligibility().load(source_order['order_id'])
    assert candidate_order['api'] is True
    assert candidate_order['invoice_number'] == source_order['invoice_number']
    assert candidate_order['buyer_name'] == source_order['buyer_name']
    assert candidate_order['payment_method'] == source_order['payment_method']
    assert candidate_order['currency'] == source_order['currency']
    assert candidate_order['item_currency'] == source_order['item_currency']
    assert candidate_order['provider_fee'] == source_order['provider_fee']
    assert candidate_order['payment_subtotal'] == source_order['payment_subtotal']
    assert candidate_order['total_paid'] == source_order['total_paid']
    candidate_paid = candidate_order['paid_at'].astimezone(ZoneInfo('Asia/Jakarta'))
    source_paid = datetime.fromisoformat(source_order['paid_at']).astimezone(ZoneInfo('Asia/Jakarta'))
    assert candidate_paid == source_paid
    assert len(candidate_order['items']) == len(source_order['items'])
    for candidate_item, source_item in zip(candidate_order['items'], source_order['items']):
        for key in ('product_name', 'product_icon', 'product_custom_emoji_id', 'quantity',
                    'quantity_price_tier_minimum', 'unit_price', 'subtotal'):
            assert candidate_item[key] == source_item.get(key)
    eligibility = SalesProofEligibility()
    assert source_order['origin'] == 'api'
    assert source_order['api_gates'] == {
        'open_review_rejected': True,
        'allocation_mismatch_rejected': True,
        'latest_unpaid_payment_rejected': True,
    }

    def rejected():
        try:
            eligibility.load(source_order['order_id'])
        except NotEligible:
            return True
        return False

    paid_payment = admin.execute(
        "SELECT id,amount,currency FROM public.payments WHERE order_id=%s AND status='paid' ORDER BY created_at DESC,id DESC LIMIT 1",
        (source_order['order_id'],),
    ).fetchone()
    review_id = admin.execute(
        """INSERT INTO public.manual_fulfillment_reviews
        (order_id,payment_id,amount,currency,reason,state,created_at,updated_at)
        VALUES (%s,%s,%s,%s,'late_payment','pending',now(),now()) RETURNING id""",
        (source_order['order_id'], paid_payment[0], paid_payment[1], paid_payment[2]),
    ).fetchone()[0]
    try:
        assert rejected()
    finally:
        admin.execute('DELETE FROM public.manual_fulfillment_reviews WHERE id=%s', (review_id,))

    stock_item_id = admin.execute(
        """SELECT s.id FROM public.product_stock_items s JOIN public.order_items i ON i.id=s.order_item_id
        WHERE i.order_id=%s AND s.retired_at IS NULL ORDER BY s.id LIMIT 1""",
        (source_order['order_id'],),
    ).fetchone()[0]
    admin.execute('UPDATE public.product_stock_items SET retired_at=now() WHERE id=%s', (stock_item_id,))
    try:
        assert rejected()
    finally:
        admin.execute('UPDATE public.product_stock_items SET retired_at=NULL WHERE id=%s', (stock_item_id,))

    pending_payment_id = str(uuid4())
    pending_created_at = datetime.now(ZoneInfo('UTC')) + timedelta(days=1)
    admin.execute(
        """INSERT INTO public.payments
        (id,order_id,provider,merchant_ref,method,amount,currency,status,paid_at,created_at,updated_at)
        VALUES (%s,%s,'manual',%s,'QR_MANUAL',%s,%s,'pending',NULL,%s,%s)""",
        (pending_payment_id, source_order['order_id'], 'p25-pending-' + pending_payment_id,
         paid_payment[1], paid_payment[2], pending_created_at, pending_created_at),
    )
    try:
        assert rejected()
    finally:
        admin.execute('DELETE FROM public.payments WHERE id=%s', (pending_payment_id,))
    non_api_release = source_order['non_api_after_release']
    assert (non_api_release['eligible'] is True
            and non_api_release['api'] is False
            and non_api_release['release_movement'] is True
            and non_api_release['sale_movement'] is True
            and non_api_release['release_before_paid_payment'] is True
            and non_api_release['paid_payment_before_sale'] is True
            and non_api_release['latest_unpaid_payment_present'] is True
            and non_api_release['origin'] is None)
    non_api_order_id = non_api_release['order_id']
    assert admin.execute('SELECT api_order_claim_id FROM public.orders WHERE id=%s',
                         (non_api_order_id,)).fetchone()[0] is None
    state = admin.execute(
        """SELECT
            EXISTS(SELECT 1 FROM public.inventory_movements WHERE order_id=%s AND type='sale'),
            EXISTS(SELECT 1 FROM public.inventory_movements WHERE order_id=%s AND type='release'),
            EXISTS(
                SELECT 1 FROM public.payments pending
                WHERE pending.order_id=%s AND pending.status='pending'
                AND pending.created_at > (
                    SELECT max(paid_at) FROM public.payments
                    WHERE order_id=%s AND status='paid'
                )
            ),
            EXISTS(
                SELECT 1 FROM public.inventory_movements released
                JOIN public.payments paid ON paid.order_id=released.order_id AND paid.status='paid'
                WHERE released.order_id=%s AND released.type='release'
                AND released.created_at < paid.paid_at
            ),
            EXISTS(
                SELECT 1 FROM public.inventory_movements sold
                JOIN public.payments paid ON paid.order_id=sold.order_id AND paid.status='paid'
                WHERE sold.order_id=%s AND sold.type='sale'
                AND sold.created_at > paid.paid_at
            )""",
        (non_api_order_id, non_api_order_id, non_api_order_id, non_api_order_id,
         non_api_order_id, non_api_order_id),
    ).fetchone()
    assert state == (True, True, True, True, True)
    try:
        candidate_non_api = eligibility.load(non_api_order_id)
        assert candidate_non_api['api'] is False
        assert candidate_non_api['invoice_number'] == non_api_release['invoice_number']
        assert candidate_non_api['buyer_name'] == non_api_release['buyer_name']
        assert candidate_non_api['payment_method'] == non_api_release['payment_method']
        assert candidate_non_api['currency'] == non_api_release['currency']
        assert candidate_non_api['item_currency'] == non_api_release['item_currency']
        assert candidate_non_api['provider_fee'] == non_api_release['provider_fee']
        assert candidate_non_api['payment_subtotal'] == non_api_release['payment_subtotal']
        assert candidate_non_api['total_paid'] == non_api_release['total_paid']
        assert candidate_non_api['paid_at'] == datetime.fromisoformat(
            non_api_release['paid_at']).astimezone(ZoneInfo('Asia/Jakarta'))
        assert len(candidate_non_api['items']) == len(non_api_release['items'])
        for candidate_item, source_item_proof in zip(candidate_non_api['items'], non_api_release['items']):
            for key in ('product_name', 'product_icon', 'product_custom_emoji_id', 'quantity',
                        'quantity_price_tier_minimum', 'unit_price', 'subtotal'):
                assert candidate_item[key] == source_item_proof.get(key)
    finally:
        admin.execute('DELETE FROM public.inventory_movements WHERE order_id=%s', (non_api_order_id,))
        admin.execute('DELETE FROM public.payments WHERE order_id=%s', (non_api_order_id,))
        admin.execute('DELETE FROM public.order_items WHERE order_id=%s', (non_api_order_id,))
        admin.execute('DELETE FROM public.orders WHERE id=%s', (non_api_order_id,))
    proof_fixture = oracle['sales_proof']

    proof_snapshot = dict(proof_fixture['snapshot'], api=True)
    proof_snapshot['paid_at'] = datetime.fromisoformat(proof_snapshot['paid_at'])
    icons = oracle['ui_icons']
    candidate_caption = proof_publication.format_caption(
        proof_snapshot, proof_fixture['number'], proof_fixture['remaining_stock'], icons,
    )
    assert _without_php_icon_marker(candidate_caption) == _without_php_icon_marker(proof_fixture['caption'])
    candidate_button = proof_publication.shopping_markup(icons, proof_fixture['shopping_url'])
    photo_wire = _wire('sendPhoto', {
        'chat_id': '1', 'photo': b'P25 synthetic image bytes', 'caption': candidate_caption,
        'parse_mode': 'HTML', 'reply_markup': candidate_button,
    })
    photo_request = {key: value for key, value in photo_wire.items() if key != 'photo'}
    _assert_wire(photo_request, proof_fixture['wire'])

    php_image = Image.open(BytesIO(base64.b64decode(proof_fixture['photo_base64'], validate=True))).convert('RGB')
    python_image = Image.open(BytesIO(proof_publication.render_invoice(proof_snapshot))).convert('RGB')
    assert php_image.size == tuple(proof_fixture['photo_dimensions']) == python_image.size
    difference = ImageChops.difference(php_image, python_image)
    mean_difference = sum(ImageStat.Stat(difference).mean) / 3
    high_difference = difference.convert('L').point(lambda value: 255 if value > 48 else 0)
    high_fraction = sum(high_difference.getdata()) / (255 * php_image.width * php_image.height)
    assert mean_difference < 6 and high_fraction < 0.05, (mean_difference, high_fraction)



    guest_context = oracle['guest_mail_context']
    guest_candidate = milestone_content(guest_context)
    guest_source = oracle['guest_mail']
    assert guest_candidate['subject'] == guest_source['subject']
    assert guest_candidate['text'].strip() == guest_source['text'].strip(), {
        'candidate_text': guest_candidate['text'].strip(),
        'php_text': guest_source['text'].strip(),
    }
    assert _html_signature(guest_candidate['html']) == _html_signature(guest_source['html'])

    credential_input = oracle['credential_mail_input']
    credential_source = oracle['credential_mail']
    credential_data = credential_context(credential_input)
    assert credential_data['app_name'] == oracle['app_name']
    assert credential_data['default_instruction'] == credential_source['default_instruction']
    assert credential_text(credential_input).strip() == credential_source['text'].strip()
    assert _html_signature(credential_html(credential_input)) == _html_signature(credential_source['html'])
    assert ('Data akun pesanan ' + credential_data['invoice_number'] + ' | ' + credential_data['app_name']) == credential_source['subject']

    confirmation_context = oracle['waitlist_confirmation_context']
    confirmation_candidate = waitlist.mail_content(waitlist.CONFIRMATION, confirmation_context)
    confirmation_source = oracle['waitlist_confirmation_mail']
    assert confirmation_candidate['subject'] == confirmation_source['subject']
    assert confirmation_candidate['text'].strip() == confirmation_source['text'].strip()
    assert _html_signature(confirmation_candidate['html']) == _html_signature(confirmation_source['html'])

    restock_context = oracle['waitlist_restock_context']
    restock_candidate = waitlist.mail_content(waitlist.RESTOCK, restock_context)
    restock_source = oracle['waitlist_restock_mail']
    assert restock_candidate['subject'] == restock_source['subject']
    assert restock_candidate['text'].strip() == restock_source['text'].strip()
    assert _html_signature(restock_candidate['html']) == _html_signature(restock_source['html'])

    return {
        'php_source_lifecycle_admin_message_and_button_parity': 'passed',
        'php_source_balance_receipt_locales_icons_buttons_and_sent_outcome': 'passed',
        'php_source_balance_owner_instruction_proof_eligibility_photo_caption_buttons_and_qr': 'passed',
        'php_source_broadcast_stock_price_copy_icons_buttons': 'passed',
        'php_source_sales_proof_eligibility_mask_snapshot_caption_photo_button': 'passed',
        'php_source_guest_credential_mail_copy': 'passed',
        'php_source_admin_broadcast_product_button_and_photo_only': 'passed',
        'php_source_waitlist_confirmation_and_restock_mail_copy': 'passed',
    }
