#!/usr/bin/env bash
set -euo pipefail

packages=()

if [ -n "${LIBPNG_VERSION:-}" ]; then
    packages+=("libpng-dev=${LIBPNG_VERSION}")
else
    packages+=("libpng-dev")
fi

if [ -n "${LIBJPEG_VERSION:-}" ]; then
    packages+=("libjpeg62-turbo-dev=${LIBJPEG_VERSION}")
else
    packages+=("libjpeg62-turbo-dev")
fi

if [ -n "${LIBFREETYPE_VERSION:-}" ]; then
    packages+=("libfreetype6-dev=${LIBFREETYPE_VERSION}")
else
    packages+=("libfreetype6-dev")
fi

if [ -n "${LIBWEBP_VERSION:-}" ]; then
    packages+=("libwebp-dev=${LIBWEBP_VERSION}")
else
    packages+=("libwebp-dev")
fi

apt-get install -y --no-install-recommends "${packages[@]}"

docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

docker-php-ext-install -j"$(nproc)" \
    exif \
    gd
