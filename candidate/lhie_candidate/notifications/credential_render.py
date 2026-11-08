"""Transient channel projection of the shared authorized credential source.

Matches OrderCredentialMailService::groups/account/access. Nothing returned by
this module belongs in a job, audit event, cache, diagnostic, or log.
"""
from ..receipt.render import _legacy, _url

DEFAULT_INSTRUCTION = ('Login dari perangkat pribadi, ganti password setelah berhasil masuk, '
                       'lalu pindahkan MFA/Authenticator ke perangkat Anda sendiri jika produk mendukungnya.')


def presentation(source):
    groups, guides = [], []
    for group in source['groups']:
        accounts = []
        default = group.get('default_tutorial')
        for account in group['accounts']:
            result = {'label': 'Data akun ' + str(account['number']), 'kind': 'structured',
                      'text': None, 'parts': None, 'access': None, 'email': None, 'password': None,
                      'tutorial': account.get('tutorial') if account.get('tutorial') != default else None}
            if account['format'] == 'legacy':
                text, parts, _ = _legacy(account['secret'])
                result.update(kind='legacy', text=text, parts=dict(zip(('email', 'password', 'mfa'), parts)) if parts else None)
            else:
                result.update(email=account['email'], password=account['password'])
                method, value = account['access_method'], account['access_value']
                reader = account.get('reader_mail_link')
                if reader:
                    result['access'] = {'label': 'Akses Token', 'value': value,
                                        'url': reader if _url(reader) else None, 'domain': _url(reader)}
                elif method == 'otp_mail_link':
                    result['access'] = {'label': 'OTP Mail', 'value': None,
                                        'url': value if _url(value) else None, 'domain': _url(value)}
                elif method == 'auth_token':
                    result['access'] = {'label': 'Auth Token', 'value': value, 'url': None, 'domain': None}
            accounts.append(result)
        guide = group.get('guide')
        if guide and guide not in guides:
            guides.append(guide)
        groups.append({'product_name': group['product_name'], 'accounts': accounts,
                       'delivery_note': default, 'usage_guide': guide})
    invoice = source.get('invoice_url') or ''
    origin = invoice.split('/shop/orders/', 1)[0] if '/shop/orders/' in invoice else ''
    return {'invoice_number': source['invoice_number'], 'groups': groups, 'usage_guides': guides,
            'invoice_url': invoice, 'order_access_url': origin + '/akses-pesanan',
            'default_instruction': DEFAULT_INSTRUCTION}


def credential_text(source):
    value = presentation(source)
    lines = [f"Data akun pesanan {value['invoice_number']}", '',
             'Pembayaran pesanan ini sudah terverifikasi. Data akun untuk pesanan Anda ada di email ini. Simpan email ini di tempat aman.', '']
    for group in value['groups']:
        lines.append(group['product_name'])
        for account in group['accounts']:
            lines.append(account['label'])
            if account['kind'] == 'legacy':
                lines.append(account['text'])
                if account['parts']:
                    lines.extend(f'{key} : {field}' for key, field in account['parts'].items() if field)
            else:
                lines.extend(('email : ' + account['email'], 'password : ' + account['password']))
                access = account['access']
                if access:
                    lines.append(access['label'].lower() + ' : ' + (access['value'] or ''))
                    if access['url']:
                        lines.append('link : ' + access['url'])
            if account['tutorial']:
                lines.append('instruksi akun : ' + account['tutorial'])
            lines.append('')
        if group['delivery_note']:
            lines.append('instruksi produk : ' + group['delivery_note'])
        if group['usage_guide']:
            lines.append(group['usage_guide']['title'] + ' : ' + group['usage_guide']['url'])
        lines.append('')
    if value['groups']:
        lines.append('Instruksi penggunaan aman: ' + value['default_instruction'])
    if value['usage_guides']:
        lines.append('Panduan produk:')
        lines.extend('- ' + guide['title'] + ' : ' + guide['url'] for guide in value['usage_guides'])
    lines.extend(('', 'Halaman invoice: ' + value['invoice_url'],
                  'Akses pesanan (nomor invoice + Recovery Key): ' + value['order_access_url'], '',
                  'Jangan bagikan data akun, email ini, atau Recovery Key kepada siapa pun, termasuk pihak yang mengaku support. '
                  'Ganti password akun setelah login pertama bila produk mendukungnya.'))
    return '\n'.join(lines)
