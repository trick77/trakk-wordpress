#!/bin/sh
# The release's update.json, which the plugin reads (trakk/includes/update.php).
set -eu
cd "$(dirname "$0")/.."
version=$(test/version.sh)
readme() {
  sed -n "s/^$1: *//p" trakk/readme.txt
}
printf '{"version":"%s","requires":"%s","requires_php":"%s","tested":"%s"}\n' \
  "$version" "$(readme 'Requires at least')" "$(readme 'Requires PHP')" "$(readme 'Tested up to')"
