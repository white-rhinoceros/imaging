FROM php:8.3-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libmagickwand-dev \
        imagemagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        exif \
        gd \
        pdo_sqlite \
        zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid "${GID}" --shell /bin/bash --create-home app

WORKDIR /app

RUN chown app:app /app

ENV PATH="/app/vendor/bin:${PATH}" \
    COMPOSER_HOME=/home/app/.composer \
    COMPOSER_ALLOW_SUPERUSER=1

USER app

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php", "-v"]
