"""Non-activated production protocol; credentials and egress require explicit admission."""
from dataclasses import dataclass
import hashlib
import hmac

from .sakurupiah import ProviderUnavailable, SakurupiahSandboxAdapter
from .sakurupiah_coordination import SakurupiahCoordination


@dataclass(frozen=True, slots=True, repr=False)
class SakurupiahProductionConfig:
    api_id: str = ''
    api_key: str = ''
    sandbox_api_id: str = ''
    sandbox_api_key: str = ''
    enabled: bool = False
    qualified: bool = False
    callback_url: str = 'https://lhiestore.web.id/api/webhook/sakurupiah'
    return_url: str = 'https://lhiestore.web.id/shop/orders'

    def readiness(self):
        problems = []
        if not self.enabled:
            problems.append('disabled')
        if not self.qualified:
            problems.append('qualification_missing')
        if any(type(value) is not str or not value or value.strip() != value
               or any(ord(c) < 32 or ord(c) == 127 for c in value) for value in (self.api_id, self.api_key)):
            problems.append('credentials')
        if ((self.api_id and self.api_id == self.sandbox_api_id)
                or (self.api_key and self.api_key == self.sandbox_api_key)):
            problems.append('cross_environment')
        if self.callback_url != 'https://lhiestore.web.id/api/webhook/sakurupiah':
            problems.append('callback_url')
        if self.return_url != 'https://lhiestore.web.id/shop/orders':
            problems.append('return_url')
        return tuple(problems)


class _ProductionTransport:
    def __init__(self, transport, config):
        self.transport, self.config = transport, config

    def post(self, url, *, fields, timeout, max_response_bytes):
        if self.config.readiness():
            raise ProviderUnavailable('configuration_unavailable', dispatched=False)
        prefix = 'https://sakurupiah.id/api-sanbox/'
        if not url.startswith(prefix) or url[len(prefix):] not in (
                'list-payment.php', 'create.php', 'status-transaction.php', 'transaction.php'):
            raise ProviderUnavailable('request_invalid', dispatched=False)
        body = {**fields, 'api_id': self.config.api_id}
        if url.endswith('/create.php'):
            if fields.get('callback_url') != self.config.callback_url or fields.get('return_url') != self.config.return_url:
                raise ProviderUnavailable('request_invalid', dispatched=False)
            material = self.config.api_id + fields['method'] + fields['merchant_ref'] + fields['amount']
            body['signature'] = hmac.new(self.config.api_key.encode(), material.encode(), hashlib.sha256).hexdigest()
        return self.transport.request('POST', 'https://sakurupiah.id/api/' + url[len(prefix):],
            fields=body, headers={'Authorization': 'Bearer ' + self.config.api_key},
            timeout=timeout, max_response_bytes=max_response_bytes, allow_redirects=False,
            allowed_hosts=('sakurupiah.id',))


class SakurupiahProductionAdapter(SakurupiahSandboxAdapter):
    """Same provider protocol, separate fixed endpoint, credentials, cooldown and callback key."""
    environment = 'production'

    def __init__(self, *, transport, config=None, coordination=None):
        self.config = config or SakurupiahProductionConfig()
        super().__init__(transport=_ProductionTransport(transport, self.config), coordination=coordination)

    def enable_coordination(self, *, using='default'):
        if self.coordination is None:
            self.coordination = SakurupiahCoordination(using=using)
            self.coordination.key = 'candidate:sakurupiah:production:' + hashlib.sha256(self.config.api_id.encode()).hexdigest()

    def list_channels(self):
        if self.config.readiness():
            raise ProviderUnavailable('configuration_unavailable', dispatched=False)
        return super().list_channels()

    def authenticate_callback(self, body, signature):
        if (self.config.readiness() or type(body) is not bytes or not 0 < len(body) <= 16384
                or type(signature) is not str or len(signature) != 64):
            return False
        expected = hmac.new(self.config.api_key.encode(), body, hashlib.sha256).hexdigest()
        return hmac.compare_digest(expected, signature)
