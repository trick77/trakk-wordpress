<?php

namespace Trakk;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/pure.php';
require_once __DIR__ . '/includes/queue.php';
require_once __DIR__ . '/includes/update.php';

delete_site_transient(RELEASE_TRANSIENT);
each_site(is_multisite(), static function (): void {
    (new Queue())->close();
    delete_option(OPTION);
});
