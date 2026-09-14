<?php

// Only deployment configuration defines the public origin. No client or LB header is trusted.
$url = parse_url(getenv('APP_URL') ?: '');
if (! is_array($url)
    || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
    || ! filter_var($url['host'] ?? '', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
    || isset($url['user']) || isset($url['pass'])
    || isset($url['query']) || isset($url['fragment'])
    || ! in_array($url['path'] ?? '', ['', '/'], true)) {
    fwrite(STDERR, "Invalid APP_URL: expected an HTTP(S) origin with a DNS hostname, no credentials/path/query.\n");
    exit(1);
}
$secure = $url['scheme'] === 'https';
$publicPort = $url['port'] ?? ($secure ? 443 : 80);
$host = strtolower($url['host']);
$authority = $host.($publicPort === ($secure ? 443 : 80) ? '' : ':'.$publicPort);
$config = strtr(file_get_contents(__DIR__.'/nginx.conf'), [
    '__PORT__' => getenv('PORT'),
    '__PUBLIC_SCHEME__' => $url['scheme'],
    '__PUBLIC_HTTPS__' => $secure ? 'on' : 'off',
    '__PUBLIC_PORT__' => (string) $publicPort,
    '__PUBLIC_HOST__' => $host,
    '__PUBLIC_AUTHORITY__' => $authority,
]);
if (file_put_contents('/tmp/complyflow-nginx.conf', $config) === false) {
    exit(1);
}
