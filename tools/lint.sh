#!/usr/bin/env bash
set -euo pipefail

shopt -s nullglob

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
cd "$project_root"

paths=(src tests stubs lang)
status=0

for dir in "${paths[@]}"; do
  while IFS= read -r -d '' file; do
    if ! php -d display_errors=1 -l "$file" > /dev/null; then
      echo "Syntax error in $file" >&2
      status=1
    fi
  done < <(find "$dir" -type f -name '*.php' -print0)
done

if [ "$status" -eq 0 ]; then
  echo "PHP syntax check passed"
fi

exit "$status"
