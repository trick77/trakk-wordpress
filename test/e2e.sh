#!/bin/sh
# The whole chain in containers (compose.yaml). Needs Docker; takes a few minutes.
set -eu
cd "$(dirname "$0")"
mkdir -p data/apache data/fpm

dc() {
  docker compose "$@"
}

# shellcheck disable=SC2329 # the trap's
down() {
  # Their files belong to the container's user: removed by a container.
  dc run --rm --no-deps -T -v "$PWD/data:/data" driver sh -c 'rm -rf /data/apache /data/fpm' >/dev/null 2>&1 || true
  dc --profile tools down -v --remove-orphans >/dev/null 2>&1 || true
}
trap down EXIT INT TERM

dc up -d --quiet-pull
failed=0
# Plugin Check needs a current WordPress; once is enough.
for kind in apache fpm; do
  check=
  [ "$kind" = fpm ] || [ "${E2E_WORDPRESS:-}" = 6.5-php8.1 ] || check=1
  wp() {
    dc run --rm -T "cli-$kind" wp "$@"
  }
  # The entrypoint copies WordPress in, the configuration last.
  n=0
  until [ -f "data/$kind/wp-config.php" ]; do
    n=$((n + 1))
    if [ "$n" -gt 60 ]; then
      echo "$kind: WordPress is not ready"
      dc logs "$kind" db
      exit 1
    fi
    sleep 2
  done
  wp core install --url="http://$kind.wp.test" --title=e2e --admin_user=admin --admin_password=e2e-password \
    --admin_email=admin@example.com --skip-email
  wp rewrite structure '/%postname%/'
  wp plugin activate trakk
  dc run --rm -T driver php test/e2e.php "$kind" || failed=1

  if dc exec -T "$kind" grep -E 'plugins/trakk|Trakk:' /tmp/debug.log | grep -v 'refused and dropped'; then
    echo "$kind: PHP complains about the plugin"
    failed=1
  fi
  if [ "$check" ] && ! wp plugin install plugin-check --activate >/dev/null; then
    failed=1
  elif [ "$check" ]; then
    # Warnings fail too: wordpress.org's reviewers see them.
    report=$(wp plugin check trakk --exclude-checks=plugin_updater 2>&1 || true)
    if ! echo "$report" | grep -q 'No errors found' || echo "$report" | grep -qE 'WARNING|ERROR'; then
      echo "$report"
      failed=1
    fi
  fi
  wp plugin deactivate trakk
  wp plugin uninstall trakk --skip-delete
  # shellcheck disable=SC2016 # PHP's
  left=$(wp eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"%traffic_observability%\"")),
    ":", count($wpdb->get_col("SELECT 1 FROM $wpdb->options WHERE option_name LIKE \"%traffic_observability%\"")),
    ":", (int) wp_next_scheduled("traffic_observability_send");')
  if [ "$left" != "0:0:0" ]; then
    echo "$kind: left behind after uninstall (tables:options:cron): $left"
    failed=1
  fi
done
exit "$failed"
