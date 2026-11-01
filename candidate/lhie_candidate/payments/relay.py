"""P08 relay client: only the local boundary can access the sandbox credential."""
import base64
import http.client
import json


_OPERATIONS = {
    'https://sakurupiah.id/api-sanbox/list-payment.php': 'list',
    'https://sakurupiah.id/api-sanbox/create.php': 'create',
    'https://sakurupiah.id/api-sanbox/status-transaction.php': 'status',
    'https://sakurupiah.id/api-sanbox/transaction.php': 'history',
}


class SandboxRelay:
    def post(self, url, *, fields, timeout, max_response_bytes):
        if url not in _OPERATIONS or not 0 < timeout <= 5 or max_response_bytes != 1048576:
            raise ValueError('Sandbox request denied')
        body = json.dumps({'operation': _OPERATIONS[url], 'fields': fields}, separators=(',', ':')).encode()
        if len(body) > 16384:
            raise ValueError('Sandbox request denied')
        connection = http.client.HTTPConnection('127.0.0.1', 8025, timeout=timeout + 1)
        try:
            connection.request('POST', '/sandbox/sakurupiah', body, {'Content-Type': 'application/json'})
            response = connection.getresponse()
            payload = response.read(1400001)
            if response.status != 200 or len(payload) > 1400000:
                raise ValueError('Sandbox relay unavailable')
            data = json.loads(payload)
            if type(data) is not dict or set(data) != {'status', 'body'} or type(data['status']) is not int:
                raise ValueError('Sandbox relay unavailable')
            decoded = base64.b64decode(data['body'], validate=True)
            if len(decoded) > max_response_bytes:
                raise ValueError('Sandbox relay unavailable')
            return data['status'], decoded
        finally:
            connection.close()
