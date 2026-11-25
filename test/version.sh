#!/bin/sh
# Prints the plugin's version; fails when trakk.php and readme.txt disagree on it or on what the plugin needs.
set -eu
cd "$(dirname "$0")/../trakk"
header() {
  sed -n "s/^ \* $1: *//p" trakk.php
}
readme() {
  sed -n "s/^$1: *//p" readme.txt
}
same() {
  if [ -z "$2" ] || [ "$2" != "$3" ]; then
    echo "$1: '$2' and '$3'" >&2
    exit 1
  fi
}
version=$(header Version)
if ! printf %s "$version" | grep -Eqx '[0-9]+\.[0-9]+\.[0-9]+'; then
  echo "Version: '$version'" >&2
  exit 1
fi
same "Version and constant" "$version" "$(sed -n "s/^const VERSION = '\(.*\)';$/\1/p" trakk.php)"
same "Version and Stable tag" "$version" "$(readme 'Stable tag')"
same "Requires at least" "$(header 'Requires at least')" "$(readme 'Requires at least')"
same "Requires PHP" "$(header 'Requires PHP')" "$(readme 'Requires PHP')"
echo "$version"
