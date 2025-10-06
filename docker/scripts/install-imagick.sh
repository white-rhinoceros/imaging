#!/usr/bin/env bash
set -euo pipefail

packages=()

if [ -n "${LIBMAGICK_VERSION:-}" ]; then
    packages+=("libmagickwand-dev=${LIBMAGICK_VERSION}")
else
    packages+=("libmagickwand-dev")
fi

if [ -n "${IMAGEMAGICK_VERSION:-}" ]; then
    packages+=("imagemagick=${IMAGEMAGICK_VERSION}")
else
    packages+=("imagemagick")
fi

apt-get install -y --no-install-recommends "${packages[@]}"

pecl install imagick

docker-php-ext-enable imagick
