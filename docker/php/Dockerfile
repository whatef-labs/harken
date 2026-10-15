# Linux PHP CLI for running the test suite and the spikes next to
# docker/compose.yml (use --network host to reach the published ports).
#
#   docker build -t harken-php docker/php
#   docker run --rm --network host -v "$PWD":/app harken-php composer install
#   docker run --rm --network host -v "$PWD":/app harken-php vendor/bin/pest
#
# ext-uv is installed but not enabled; add `-d extension=uv` to a php
# command to run on the libuv loop instead of stream_select.

ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libuv1-dev \
    && docker-php-ext-install pcntl pgsql pdo_pgsql sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && (pecl install uv-beta || echo "ext-uv could not be built for PHP ${PHP_VERSION}") \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
