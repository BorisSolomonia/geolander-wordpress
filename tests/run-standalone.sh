#!/bin/sh
# Use the application image: XLSX tests need ZipArchive, not bare php:cli.
set -u
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
failed=0
for file in "$root"/tests/*.php; do
    if grep -q WP_CLI "$file"; then
        continue
    fi
    if php "$file" > /tmp/glc-standalone-result 2>&1; then
        printf 'PASS %s\n' "$(basename -- "$file")"
    else
        cat /tmp/glc-standalone-result
        printf 'FAIL %s\n' "$(basename -- "$file")"
        failed=1
    fi
done
exit "$failed"
