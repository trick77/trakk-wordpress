#!/bin/sh
# WordPress's source for PHPStan, in wordpress/. The version the plugin is tested up to.
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's/^Tested up to: *//p' trakk/readme.txt)
mkdir -p wordpress
curl -fsSL "https://wordpress.org/wordpress-$version.tar.gz" | tar -xz -C wordpress --strip-components=1
