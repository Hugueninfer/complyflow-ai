# syntax=docker/dockerfile:1
FROM node:22-bookworm-slim AS web
WORKDIR /build
COPY apps/web/package*.json ./
RUN npm ci --ignore-scripts
COPY apps/web/ ./
RUN npm run build && mkdir -p dist/licenses \
    && cp node_modules/@fontsource-variable/manrope/LICENSE dist/licenses/manrope.txt \
    && cp node_modules/@fontsource-variable/inter/LICENSE dist/licenses/inter.txt \
    && cp node_modules/@fontsource-variable/jetbrains-mono/LICENSE dist/licenses/jetbrains-mono.txt \
    && cp node_modules/@lucide/vue/LICENSE dist/licenses/lucide.txt \
    && cp node_modules/vue/LICENSE dist/licenses/vue.txt \
    && cp node_modules/pinia/LICENSE dist/licenses/pinia.txt \
    && cp node_modules/vue-router/LICENSE dist/licenses/vue-router.txt

FROM python:3.12-slim-bookworm AS wheels
WORKDIR /build
COPY services/processor/requirements.lock ./
RUN pip wheel --no-cache-dir --wheel-dir /wheels -r requirements.lock

FROM python:3.12-slim-bookworm AS python-runtime
COPY --from=wheels /wheels /wheels
COPY services/processor/requirements.lock /tmp/requirements.lock
RUN pip install --no-cache-dir --no-index --find-links=/wheels -r /tmp/requirements.lock \
    && rm -rf /wheels /tmp/requirements.lock /usr/local/lib/python3.12/site-packages/pip* /usr/local/lib/python3.12/ensurepip \
    && find /usr/local -type d -name __pycache__ -exec rm -rf '{}' +

FROM php:8.4-fpm-bookworm AS php-runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
      libonig-dev libpq-dev libzip-dev nginx supervisor curl libexpat1 \
    && docker-php-ext-install -j2 bcmath mbstring pcntl pdo_pgsql zip opcache \
    && apt-mark manual libonig5 libpq5 libzip4 \
    && apt-get purge -y --auto-remove libonig-dev libpq-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && groupadd --gid 10001 app && useradd --uid 10001 --gid app --no-create-home app

FROM php-runtime AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app/apps/api
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY apps/api/app ./app
COPY apps/api/database/seeders ./database/seeders
RUN composer dump-autoload --no-dev --no-scripts --classmap-authoritative

FROM php-runtime AS php-notices
RUN docker-php-source extract && mkdir -p /tmp/php-notices \
    && cp /usr/src/php/LICENSE /tmp/php-notices/PHP-LICENSE \
    && cp /usr/src/php/Zend/LICENSE /tmp/php-notices/ZEND-LICENSE

FROM debian:bookworm-slim AS production
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl nginx supervisor libonig5 libpq5 libzip4 libexpat1 \
      libreadline8 libxml2 libsqlite3-0 libargon2-1 libsodium23 \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && groupadd --gid 10001 app && useradd --uid 10001 --gid app --no-create-home app
COPY --from=php-runtime /usr/local/bin/php /usr/local/bin/php
COPY --from=php-runtime /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm
COPY --from=php-runtime /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php-runtime /usr/local/etc /usr/local/etc
COPY --from=php-notices /tmp/php-notices /usr/share/doc/php
COPY LICENSE /app/LICENSE
ENV APP_ENV=production APP_DEBUG=false APP_NAME="ComplyFlow AI" \
    DB_CONNECTION=pgsql DB_QUEUE_RETRY_AFTER=120 \
    QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database \
    SESSION_LIFETIME=1440 SESSION_EXPIRE_ON_CLOSE=false \
    SESSION_SECURE_COOKIE=true SESSION_SAME_SITE=lax \
    LOG_CHANNEL=stderr LOG_LEVEL=warning \
    PROCESSOR_URL=http://127.0.0.1:8001 AI_PROVIDER=fake \
    PROCESSOR_ANALYSIS_TIMEOUT_SECONDS=45 PROCESSOR_HTTP_TIMEOUT_SECONDS=60 \
    PORT=10000 PGCONNECT_TIMEOUT=2 PGOPTIONS="-c statement_timeout=5000" \
    PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 \
    PATH="/opt/python/bin:${PATH}" LD_LIBRARY_PATH=/opt/python/lib
COPY --from=python-runtime /usr/local /opt/python
WORKDIR /app/apps/api
COPY --from=vendor /app/apps/api/vendor ./vendor
COPY apps/api/artisan ./artisan
COPY apps/api/composer.json ./composer.json
COPY apps/api/app ./app
COPY apps/api/bootstrap/app.php apps/api/bootstrap/providers.php ./bootstrap/
COPY apps/api/config ./config
COPY apps/api/routes ./routes
COPY apps/api/database/migrations ./database/migrations
COPY apps/api/database/seeders ./database/seeders
COPY apps/api/resources/views ./resources/views
COPY apps/api/public/index.php ./public/index.php
COPY --from=web /build/dist ./public
COPY services/processor/app /app/services/processor/app
COPY demo-assets/manifest.json demo-assets/*.pdf /app/demo-assets/
COPY output/pdf /app/output/pdf
COPY infra/docker/production/entrypoint.sh infra/docker/production/bootstrap.php infra/docker/production/render-nginx.php infra/docker/production/nginx.conf infra/docker/production/php.ini infra/docker/production/php-fpm.conf infra/docker/production/supervisord.conf /etc/complyflow/
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod 700 storage bootstrap/cache \
    && cp /etc/complyflow/php.ini /usr/local/etc/php/conf.d/zz-production.ini \
    && rm /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
    && cp /etc/complyflow/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf \
    && chmod +x /etc/complyflow/entrypoint.sh \
    && php -m && python3.12 -c "import ssl, sqlite3, fastapi, pypdf"
USER 10001:10001
RUN php-fpm -t
EXPOSE 10000
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl --fail --silent --max-time 4 "http://127.0.0.1:${PORT}/api/health" || exit 1
ENTRYPOINT ["/etc/complyflow/entrypoint.sh"]
