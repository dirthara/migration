ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli

COPY --from=ghcr.io/mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo \
    && install-php-extensions pdo_mysql pdo_pgsql pdo_sqlsrv xdebug

# Xdebug is installed but inactive, so the suite runs at full speed. Enable it
# per command with XDEBUG_MODE, which is what the coverage script does.
RUN printf 'xdebug.mode=off\n' > /usr/local/etc/php/conf.d/zzz-xdebug-mode.ini

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
COPY --from=ghcr.io/carthage-software/mago:1.47.3 /usr/local/bin/mago /usr/local/bin/mago

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

RUN groupadd --gid "${LOCAL_GID}" developer \
    && useradd --uid "${LOCAL_UID}" --gid developer --create-home developer

USER developer

WORKDIR /app
