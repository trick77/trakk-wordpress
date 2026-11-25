#!/bin/sh
# Prints the plugin's version; fails when its three places disagree.
set -eu
cd "$(dirname "$0")/../trakk"
header=$(sed -n 's/^ \* Version: *//p' trakk.php)
constant=$(sed -n "s/^const VERSION = '\(.*\)';$/\1/p" trakk.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
if ! printf %s "$header" | grep -Eqx '[0-9]+\.[0-9]+\.[0-9]+' || [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
  echo "versions: header '$header', constant '$constant', stable tag '$stable'" >&2
  exit 1
fi
echo "$header"
