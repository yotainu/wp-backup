#!/usr/bin/env bash
# Generate random fixture files in the wp-env development site for backup tests.
#
#   bin/make-fixture-site.sh <MB>
#
# Replaces wp-content/uploads/fixture/ (inside the wp-env "cli" container) with
# files totalling about <MB> MB. File sizes are a mix of 1 KB - 8 MB, spread over
# nested directories, including Japanese names and names with spaces. The list of
# names and sizes is deterministic (fixed seed); the contents are random bytes
# (plus some compressible text files).
#
# The script re-runs itself inside the container through the plugin mount
# (wp-content/plugins/<this directory name>/bin/make-fixture-site.sh).
set -euo pipefail

if [ "${1:-}" = "--in-container" ]; then
  MB="$2"
  ROOT=/var/www/html/wp-content/uploads/fixture
  rm -rf "$ROOT"
  mkdir -p "$ROOT"
  cd "$ROOT"
  LIST="$(mktemp)"
  awk -v total="$((MB * 1024 * 1024))" 'BEGIN {
    n = split("images|images/2026/10|docs/deep/nested/level|画像 フォルダ|データ/月次 レポート|misc files", d, "|");
    srand(20261025); sum = 0; i = 0;
    while (sum < total) {
      r = rand();
      if (r < 0.70)      size = 1024 + int(rand() * 255 * 1024);
      else if (r < 0.90) size = 256 * 1024 + int(rand() * 1792 * 1024);
      else               size = 2 * 1024 * 1024 + int(rand() * 6 * 1024 * 1024);
      if (sum + size > total) size = total - sum;
      if (size < 1024) size = 1024;
      i++; sum += size;
      dir = d[(i % n) + 1];
      if (i % 11 == 0)     name = sprintf("写真 %05d.bin", i);
      else if (i % 7 == 0) name = sprintf("report %05d.txt", i);
      else                 name = sprintf("file-%05d.bin", i);
      print size "\t" dir "\t" name;
    }
  }' > "$LIST"
  count=0
  while IFS="$(printf '\t')" read -r size dir name; do
    mkdir -p "$dir"
    case "$name" in
      *.txt) ( set +o pipefail; head -c "$(( size * 3 / 4 + 3 ))" /dev/urandom | base64 | head -c "$size" ) > "$dir/$name" ;;
      *)     head -c "$size" /dev/urandom > "$dir/$name" ;;
    esac
    count=$((count + 1))
  done < "$LIST"
  rm -f "$LIST"
  echo "fixture: $count files, $(du -sk . | cut -f1) KB in $ROOT"
  exit 0
fi

cd "$(dirname "$0")/.."

MB="${1:-}"
if ! [[ "$MB" =~ ^[0-9]+$ ]] || [ "$MB" -lt 1 ]; then
  echo "usage: $0 <MB>" >&2
  exit 1
fi

WP_ENV="${WP_ENV:-npx -y @wordpress/env@12.0.0}"
$WP_ENV run cli bash "/var/www/html/wp-content/plugins/$(basename "$PWD")/bin/make-fixture-site.sh" --in-container "$MB"
