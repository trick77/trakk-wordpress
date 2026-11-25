<?php

namespace Trakk;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/pure.php';
require_once __DIR__ . '/includes/queue.php';

$trakk_remove = static function (): void {
    wp_clear_scheduled_hook(CRON_HOOK);
    (new Queue())->uninstall();
    delete_option(OPTION);
};

if (is_multisite()) {
    /** @var list<int> $trakk_blogs */
    $trakk_blogs = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($trakk_blogs as $trakk_blog) {
        switch_to_blog($trakk_blog);
        $trakk_remove();
        restore_current_blog();
    }
} else {
    $trakk_remove();
}
