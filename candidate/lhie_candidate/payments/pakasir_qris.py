"""Historical Pakasir QR integrity and provider-hosted link validation."""
import binascii
import hashlib
import re
from urllib.parse import parse_qsl, quote, unquote, urlencode, urlsplit

from ..domain.money import MAX_UNITS


def validate_qris_payload(payload, total):
    if type(payload) is not str or type(total) is not int or not 1 <= total <= MAX_UNITS:
        raise ValueError('Invalid Pakasir QR')
    raw = payload.encode('utf-8')
    if len(raw) > 8192 or not re.search(rb'6304[0-9A-Fa-f]{4}\Z', raw):
        raise ValueError('Invalid Pakasir QR CRC')
    crc = f'{binascii.crc_hqx(raw[:-4], 0xffff):04X}'.encode('ascii')
    if crc != raw[-4:].upper():
        raise ValueError('Invalid Pakasir QR CRC')
    fields, offset = {}, 0
    while offset < len(raw):
        header = raw[offset:offset + 4]
        if len(header) != 4 or not header.isdigit():
            raise ValueError('Invalid Pakasir QR TLV')
        tag, size = header[:2], int(header[2:])
        end = offset + 4 + size
        if tag in fields or end > len(raw):
            raise ValueError('Invalid Pakasir QR TLV')
        fields[tag] = raw[offset + 4:end]
        offset = end
    amount = fields.get(b'54', b'')
    if (fields.get(b'53') != b'360' or not re.fullmatch(rb'[1-9][0-9]*(?:\.00)?', amount)
            or int(amount.removesuffix(b'.00')) != total):
        raise ValueError('Invalid Pakasir QR amount')
    return payload


def hosted_payment_url(project, merchant_ref, amount):
    return ('https://app.pakasir.com/pay/' + quote(project, safe='') + '/' + str(amount)
            + '?' + urlencode({'order_id': merchant_ref}))


def validate_hosted_payment_url(value, *, project, merchant_ref, amount):
    if type(value) is not str or not value or len(value) > 2048 or any(ord(c) < 33 for c in value):
        return False
    try:
        url = urlsplit(value)
        if (url.scheme != 'https' or url.hostname != 'app.pakasir.com'
                or url.netloc != 'app.pakasir.com' or url.fragment or '\\' in value
                or re.search(r'%(?:2f|5c|00|0a|0d)', url.path, re.I)
                or unquote(url.path) != f'/pay/{project}/{amount}'):
            return False
        query = parse_qsl(url.query, keep_blank_values=True, strict_parsing=True)
        # A historical redirect is not trusted by the candidate; it is omitted
        # when reconstructing a link to the already existing transaction.
        return query == [('order_id', merchant_ref)]
    except (ValueError, UnicodeError):
        return False


def validate_payment_number(payload, digest, total):
    if (type(payload) is not str or type(digest) is not str
            or hashlib.sha256(payload.encode('utf-8')).hexdigest() != digest):
        raise ValueError('Invalid Pakasir QR snapshot hash')
    return validate_qris_payload(payload, total)
