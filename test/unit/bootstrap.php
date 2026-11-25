<?php

define('ABSPATH', '/');

/**
 * @return array<string, int|string>|false
 */
function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

require dirname(__DIR__, 2) . '/trakk/includes/pure.php';
