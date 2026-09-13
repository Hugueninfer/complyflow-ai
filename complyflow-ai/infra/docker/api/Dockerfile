FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libcurl4-openssl-dev \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        procps \
        unzip \
    && docker-php-ext-install bcmath curl dom mbstring pcntl pdo_pgsql xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace/apps/api

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY infra/docker/api/entrypoint.sh /usr/local/bin/complyflow-api-entrypoint
RUN chmod +x /usr/local/bin/complyflow-api-entrypoint

COPY infra/docker/api/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

ENTRYPOINT ["complyflow-api-entrypoint"]
