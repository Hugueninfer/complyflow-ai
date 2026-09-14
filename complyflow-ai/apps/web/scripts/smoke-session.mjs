import assert from 'node:assert/strict'

// A real HTTP regression for Compose → Vite proxy → Sanctum → PostgreSQL.
// This creates one temporary, isolated demo (automatically expires after 24h).
const origin = process.env.WEB_BASE_URL || 'http://localhost:5173'
const cookies = new Map()
async function request(path, method = 'GET') {
  const headers = { Accept: 'application/json', Origin: origin, 'X-Requested-With': 'XMLHttpRequest' }
  if (cookies.size) headers.Cookie = [...cookies].map(([key, value]) => `${key}=${value}`).join('; ')
  if (method !== 'GET' && cookies.has('XSRF-TOKEN')) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookies.get('XSRF-TOKEN'))
  const response = await fetch(`${origin}${path}`, { method, headers })
  for (const cookie of response.headers.getSetCookie()) {
    const pair = cookie.split(';', 1)[0]
    const separator = pair.indexOf('=')
    cookies.set(pair.slice(0, separator), pair.slice(separator + 1))
  }
  return response
}

assert.equal((await request('/sanctum/csrf-cookie')).status, 204, 'CSRF cookie initialization')
assert.ok(cookies.has('XSRF-TOKEN'), 'Sanctum must set an XSRF cookie')
const demoResponse = await request('/api/v1/demo-sessions', 'POST')
assert.equal(demoResponse.status, 201, 'Compose HTTP server must create a demo in the configured database')
const demo = (await demoResponse.json()).data
const restored = await request('/api/v1/me')
assert.equal(restored.status, 200, 'Cookie session must survive a separate HTTP request')
const session = (await restored.json()).data
assert.equal(session.organization.id, demo.organization_id)
assert.equal(session.demo.id, demo.id)
assert.equal(session.demo.expires_at, demo.expires_at)
assert.equal(session.role, 'reviewer')
assert.ok(session.permissions.includes('finding.review'))
assert.equal((await request('/api/v1/logout', 'POST')).status, 204)
assert.equal((await request('/api/v1/me')).status, 401)
console.log('PASS: real CSRF → isolated demo → restored session → logout → unauthorized')
