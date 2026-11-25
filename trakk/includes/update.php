<?php

/**
 * New versions from the plugin's GitHub releases: WordPress asks wordpress.org alone.
 */

namespace Trakk;

defined('ABSPATH') || exit;

const SLUG = 'trakk';
const UPDATE_URI = 'https://github.com/trick77/trakk-wordpress';

/**
 * WordPress asks every plugin whose Update URI is on github.com.
 */
function update_offer(mixed $update, mixed $plugin, mixed $file): mixed
{
    if ($file !== plugin_basename(PLUGIN_FILE)) {
        return $update;
    }
    $release = latest_release();
    return $release === null ? $update : $release + [
        'slug' => SLUG,
        'url' => UPDATE_URI . '/releases/tag/' . $release['version'],
        'package' => UPDATE_URI . '/releases/download/' . $release['version'] . '/trakk.zip',
    ];
}

/**
 * "View details", which would look the plugin up on wordpress.org.
 */
function update_details(mixed $result, mixed $action, mixed $args): mixed
{
    if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== SLUG) {
        return $result;
    }
    $release = latest_release();
    if ($release === null) {
        return $result;
    }
    $notes = UPDATE_URI . '/releases/tag/' . $release['version'];
    return (object) ($release + [
        'name' => 'Trakk',
        'slug' => SLUG,
        'author' => 'trick77',
        'homepage' => UPDATE_URI,
        'sections' => [
            'changelog' => '<p><a href="' . esc_url($notes) . '" target="_blank">Release notes on GitHub</a></p>',
        ],
    ]);
}

/**
 * The newest release's own description, a file beside its ZIP: GitHub's API allows 60 calls an hour and address.
 *
 * @return array{version: string, requires: string, requires_php: string, tested: string}|null
 */
function latest_release(): ?array
{
    $res = wp_remote_get(UPDATE_URI . '/releases/latest/download/update.json', [
        'timeout' => TIMEOUT,
        'user-agent' => 'trakk-wordpress/' . VERSION,
        'limit_response_size' => 4096,
    ]);
    return is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200
        ? null : release_from(wp_remote_retrieve_body($res));
}
