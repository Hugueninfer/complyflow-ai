// A loopback origin exposes browser Web Crypto while forwarding actual HTTP to Compose.
// No fixture responses or application data are supplied by this transport.
import http from 'node:http'

const target = new URL(process.env.E2E_UPSTREAM || 'http://web:5173')
http.createServer((request, response) => {
  const upstream = http.request(new URL(request.url, target), {
    method: request.method,
    headers: { ...request.headers, host: target.host },
  }, incoming => {
    response.writeHead(incoming.statusCode, incoming.headers)
    incoming.pipe(response)
  })
  upstream.on('error', () => { response.writeHead(502); response.end('Upstream unavailable') })
  request.pipe(upstream)
}).listen(4173, '127.0.0.1')
