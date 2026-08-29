<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer"><title>Memproses link login</title></head><body><form id="login-link" method="POST" action="{{ $consumeUrl }}">@csrf<input type="hidden" name="token" id="token"></form><script>
const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
if (/^[a-f0-9]{64}$/i.test(token)) {
  document.getElementById('token').value = token;
  document.getElementById('login-link').submit();
} else {
  location.replace(@json($accountUrl));
}
</script><noscript><p>JavaScript diperlukan untuk memproses link login. <a href="{{ $accountUrl }}">Minta link baru</a>.</p></noscript></body></html>
