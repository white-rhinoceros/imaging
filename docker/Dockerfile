FROM php:8.3-cli

ARG UID=1000
ARG GID=1000
ARG LIBPNG_VERSION
ARG LIBJPEG_VERSION
ARG LIBFREETYPE_VERSION
ARG LIBWEBP_VERSION
ARG LIBMAGICK_VERSION
ARG IMAGEMAGICK_VERSION

COPY docker/scripts/ /tmp/docker-scripts/

RUN chmod +x /tmp/docker-scripts/*.sh \
    && /tmp/docker-scripts/install-system-packages.sh \
    && LIBPNG_VERSION="${LIBPNG_VERSION:-}" \
       LIBJPEG_VERSION="${LIBJPEG_VERSION:-}" \
       LIBFREETYPE_VERSION="${LIBFREETYPE_VERSION:-}" \
       LIBWEBP_VERSION="${LIBWEBP_VERSION:-}" \
       /tmp/docker-scripts/install-gd.sh \
    && LIBMAGICK_VERSION="${LIBMAGICK_VERSION:-}" \
       IMAGEMAGICK_VERSION="${IMAGEMAGICK_VERSION:-}" \
       /tmp/docker-scripts/install-imagick.sh \
    && /tmp/docker-scripts/install-xdebug.sh \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/docker-scripts

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
