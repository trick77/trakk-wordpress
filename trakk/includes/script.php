<?php

/**
 * The browser script's tag, on the site's pages only: admin titles and addresses hold what is nobody's page view.
 */

namespace Trakk;

defined('ABSPATH') || exit;

const SCRIPT_HANDLE = 'trakk';

function enqueue_script(): void
{
    $settings = Settings::load();
    if ($settings->url === '' || is_customize_preview()) {
        return;
    }
    $args = ['strategy' => 'defer', 'in_footer' => false];
    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- another server's file, cached as it says
    wp_enqueue_script(SCRIPT_HANDLE, $settings->url . '/trakk.js', [], null, $args);
}

/**
 * The script reads its collector off its own address: Cloudflare's Rocket Loader would load it from elsewhere.
 */
function script_tag(mixed $tag, mixed $handle): mixed
{
    if ($handle !== SCRIPT_HANDLE || !is_string($tag)) {
        return $tag;
    }
    return str_replace('<script ', '<script data-cfasync="false" ', $tag);
}
