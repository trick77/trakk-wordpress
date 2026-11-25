<?php

namespace Trakk;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/pure.php';
require_once __DIR__ . '/includes/queue.php';

delete_site_transient('traffic_observability_release');
each_site(is_multisite(), static function (): void {
    (new Queue())->close();
    delete_option(OPTION);
});
