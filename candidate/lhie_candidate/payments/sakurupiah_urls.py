"""Validation for sandbox Sakurupiah presentation references."""
import re


def validate_sakurupiah_qr_image_url(value):
    return (isinstance(value, str) and len(value) <= 2048
            and re.fullmatch(
                r'https://sakurupiah\.id/assets/(?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*/)*'
                r'[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.png', value) is not None)


def validate_sakurupiah_checkout_url(value, environment='sandbox'):
    path = {'sandbox': 'sanbox-payout', 'production': 'payout'}.get(environment)
    return (path is not None and isinstance(value, str) and len(value) <= 2048
            and '#' not in value and '\\' not in value
            and re.fullmatch(r'https://sakurupiah\.id/' + path + r'\.php\?[!-~]+', value) is not None)


def validate_sakurupiah_qris_payload(value, total):
    """Validate the PHP QrisEmvPayload byte-TLV/terminal CRC and exact IDR amount."""
    if type(value) is not str or type(total) is not int or total <= 0:
        return False
    try:
        payload = value.encode('utf-8')
        if not 8 <= len(payload) <= 4096 or re.search(rb'6304[0-9A-F]{4}$', payload) is None:
            return False
        crc = 0xFFFF
        for byte in payload[:-4]:
            crc ^= byte << 8
            for _ in range(8):
                crc = ((crc << 1) ^ 0x1021 if crc & 0x8000 else crc << 1) & 0xFFFF
        if payload[-4:] != f'{crc:04X}'.encode('ascii'):
            return False
        fields, offset = {}, 0
        while offset < len(payload):
            header = payload[offset:offset + 4]
            if len(header) != 4 or re.fullmatch(rb'[0-9]{4}', header) is None:
                return False
            tag, length = header[:2], int(header[2:])
            end = offset + 4 + length
            if tag in fields or end > len(payload):
                return False
            fields[tag] = payload[offset + 4:end]
            offset = end
        amount = fields.get(b'54', b'')
        return (fields.get(b'63') == payload[-4:] and fields.get(b'53') == b'360'
                and re.fullmatch(rb'[1-9][0-9]*(?:\.00)?', amount) is not None
                and int(amount.split(b'.')[0]) == total)
    except (UnicodeError, ValueError, OverflowError):
        return False
