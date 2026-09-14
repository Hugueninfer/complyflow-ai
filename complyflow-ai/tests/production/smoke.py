"""Real HTTP checks, no fixtures or mocked responses; catches broken SPA/API wiring."""
import http.cookiejar
import json
import os
import pathlib
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = os.environ.get('SMOKE_BASE_URL', 'http://127.0.0.1:18080')
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))


def request(path, data=None, content_type='application/json'):
    headers = {'Accept': 'application/json', 'Referer': base + '/', 'Origin': base}
    if data is not None:
        headers['Content-Type'] = content_type
        headers['Idempotency-Key'] = str(uuid.uuid4())
        headers['X-XSRF-TOKEN'] = urllib.parse.unquote(next(c.value for c in cookies if c.name == 'XSRF-TOKEN'))
    payload = data if isinstance(data, bytes) else None if data is None else json.dumps(data).encode()
    response = client.open(urllib.request.Request(base + path, data=payload, headers=headers), timeout=30)
    return response, response.read()


response, body = request('/api/health')
assert json.loads(body) == {'status': 'ready', 'checks': {'laravel': 'ready', 'database': 'ready', 'processor': 'ready'}}
for path in ['/', '/login', '/fornecedores', '/analises/' + str(uuid.uuid4()) + '/matriz']:
    response, body = request(path)
    assert b'<div id="app">' in body and b'/assets/' in body, path
    assert response.headers['X-Content-Type-Options'] == 'nosniff'
for path in ['/.env', '/index.php', '/api/does-not-exist', '/assets/missing.js']:
    try:
        request(path)
        raise AssertionError('Private/missing path unexpectedly served: ' + path)
    except urllib.error.HTTPError as error:
        assert error.code in (403, 404)

# Two independent browser cookie jars reach the same Nginx peer address.
identity = str(uuid.uuid4()) + '@example.invalid'
visitors = []
for _ in range(2):
    jar = http.cookiejar.CookieJar()
    visitor = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    visitor.open(base + '/sanctum/csrf-cookie').close()
    visitors.append((visitor, jar))


def login_status(visitor, jar, email, forwarded_ip):
    token = urllib.parse.unquote(next(c.value for c in jar if c.name == 'XSRF-TOKEN'))
    try:
        visitor.open(urllib.request.Request(base + '/api/v1/login', data=json.dumps({'email': email}).encode(), headers={
            'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token,
            'X-Forwarded-For': forwarded_ip, 'Origin': 'https://untrusted.invalid',
        })).close()
        raise AssertionError('Login without password succeeded')
    except urllib.error.HTTPError as error:
        return error.code


for attempt in range(5):
    assert login_status(*visitors[0], identity, f'192.0.2.{attempt}') == 422
assert login_status(*visitors[0], identity, '203.0.113.1') == 429
assert login_status(*visitors[1], ' ' + identity.upper() + ' ', '203.0.113.2') == 429
assert login_status(*visitors[1], str(uuid.uuid4()) + '@example.invalid', '203.0.113.2') == 422
print('PASS: independent visitor cookies behind one proxy; normalized identity cannot evade limit with forged XFF/Origin')
request('/sanctum/csrf-cookie')
# Real middleware remains enabled here (Laravel unit/feature harness bypasses CSRF).
for path, data, expected in [('/api/v1/me', None, 401), ('/api/v1/demo-sessions', b'{}', 419)]:
    try:
        client.open(urllib.request.Request(base + path, data=data, headers={
            'Accept': 'application/json', 'Content-Type': 'application/json',
            'Origin': 'https://attacker.invalid', 'X-Forwarded-For': '203.0.113.99',
            'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'attacker.invalid',
        }))
        raise AssertionError('Missing authentication/CSRF unexpectedly accepted')
    except urllib.error.HTTPError as error:
        assert error.code == expected, (path, error.code)
response, body = request('/api/v1/demo-sessions', {})
assert response.status == 201
response, body = request('/api/v1/suppliers')
assert len(json.loads(body)['data']) == 3
response, body = request('/api/v1/dashboard')
assert json.loads(body)['data']['suppliers_analyzed'] == 2
print('PASS: health, deep links, security headers, private paths, CSRF, isolated demo and persisted dashboard')

# Exercise the actual queue/HMAC/parser/persistence path, independently of curated demo findings.
request('/api/v1/logout', {})
request('/sanctum/csrf-cookie')
password = str(uuid.uuid4()) + str(uuid.uuid4())
request('/api/v1/register', {'name': 'Smoke Fictício', 'email': str(uuid.uuid4()) + '@example.invalid',
    'password': password, 'password_confirmation': password, 'organization_name': 'Smoke Fictício'})
_, body = request('/api/v1/suppliers', {'name': 'Fornecedor Smoke Fictício', 'risk_level': 'low'})
supplier_id = json.loads(body)['data']['id']
_, body = request('/api/v1/requirement-sets', {'name': 'Smoke', 'requirements': [
    {'code': 'SMOKE-01', 'title': 'Regularidade documental', 'category': 'Fictício',
     'evaluation_text': 'Verificar declaração cadastral para 2026.', 'is_required': True}]})
set_id = json.loads(body)['data']['id']
request('/api/v1/requirement-sets/' + set_id + '/publish', {})
boundary = 'smoke-' + uuid.uuid4().hex
pdf = pathlib.Path('output/pdf/certidao-ficticia.pdf').read_bytes()
multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="ficticio.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()
    + pdf + f'\r\n--{boundary}--\r\n'.encode())
_, body = request('/api/v1/suppliers/' + supplier_id + '/documents', multipart, 'multipart/form-data; boundary=' + boundary)
document_id = json.loads(body)['data']['id']
_, body = request('/api/v1/suppliers/' + supplier_id + '/analyses', {'requirement_set_id': set_id, 'document_ids': [document_id]})
run_id = json.loads(body)['data']['id']
deadline = time.monotonic() + 90
while True:
    _, body = request('/api/v1/analyses/' + run_id)
    run = json.loads(body)['data']
    assert run['status'] != 'failed', 'Real pipeline failed: ' + str(run.get('error_code'))
    if run['status'] == 'completed':
        break
    assert time.monotonic() < deadline, 'Worker did not complete within smoke budget'
    time.sleep(1)
_, body = request('/api/v1/analyses/' + run_id + '/findings')
findings = json.loads(body)['data']
assert len(findings) == 1 and findings[0]['requires_human_review'] is True
assert findings[0]['citations'] or findings[0]['status'] == 'missing'
print('PASS: owner registration, PDF upload, queued analysis, signed processor call, extraction and persisted finding')
