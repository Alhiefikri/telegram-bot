"""Validation for sandbox Sakurupiah presentation references."""
import re


def validate_sakurupiah_qr_image_url(value):
    return (isinstance(value, str) and len(value) <= 2048
            and re.fullmatch(
                r'https://sakurupiah\.id/assets/(?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*/)*'
                r'[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.png', value) is not None)


def validate_sakurupiah_checkout_url(value):
    return (isinstance(value, str) and len(value) <= 2048
            and '#' not in value and '\\' not in value
            and re.fullmatch(r'https://sakurupiah\.id/sanbox-payout\.php\?[!-~]+', value) is not None)
