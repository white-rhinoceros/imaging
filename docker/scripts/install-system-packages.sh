#!/usr/bin/env bash
set -euo pipefail

apt-get update

apt-get install -y --no-install-recommends \
    git \
    unzip \
    pkg-config \
    libzip-dev \
    libsqlite3-dev \
    build-essential \
    libtool \
    autoconf \
    curl

docker-php-ext-install -j"$(nproc)" \
    pdo_sqlite \
    zip
