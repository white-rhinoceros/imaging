#!/usr/bin/env bash
set -euo pipefail

pecl install xdebug

docker-php-ext-enable xdebug
