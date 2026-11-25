<?php

/**
 * New versions from the plugin's GitHub releases: WordPress asks wordpress.org alone.
 */

namespace Trakk;

defined('ABSPATH') || exit;

const SLUG = 'trakk';
const UPDATE_URI = 'https://github.com/trick77/trakk-wordpress';
const RELEASE_TRANSIENT = 'traffic_observability_release';

/**
 * WordPress asks every plugin whose Update URI is on github.com. Only for the folder the ZIP unpacks to: in another
 * one the update would replace the plugin by one WordPress does not know as active.
 */
function update_offer(mixed $update, mixed $plugin, mixed $file): mixed
{
    if ($file !== SLUG . '/trakk.php' || !is_array($plugin) || ($plugin['UpdateURI'] ?? '') !== UPDATE_URI) {
        return $update;
    }
    $release = latest_release();
    if ($release === null) {
        return $update;
    }
    return ['slug' => SLUG, 'url' => notes_url($release['version']), 'package' => package_url($release['version'])]
        + $release;
}

/**
 * "View details", which would look the slug up on wordpress.org.
 */
function update_details(mixed $result, mixed $action, mixed $args): mixed
{
    if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== SLUG) {
        return $result;
    }
    $release = latest_release();
    if ($release === null) {
        return new \WP_Error('trakk_release', 'GitHub does not tell about the release right now.');
    }
    $notes = esc_url(notes_url($release['version']));
    return (object) ($release + [
        'name' => 'Trakk',
        'slug' => SLUG,
        'author' => 'trick77',
        'homepage' => UPDATE_URI,
        'external' => true,
        'download_link' => package_url($release['version']),
        'sections' => ['changelog' => "<p><a href=\"$notes\" target=\"_blank\">Release notes on GitHub</a></p>"],
    ]);
}

/**
 * WordPress's own user agent names the site: GitHub has no need to know it.
 */
function update_request(mixed $args, mixed $url): mixed
{
    if (is_array($args) && is_string($url) && str_starts_with($url, UPDATE_URI . '/releases/')) {
        $args['user-agent'] = 'trakk-wordpress/' . VERSION;
    }
    return $args;
}

function notes_url(string $version): string
{
    return UPDATE_URI . '/releases/tag/' . $version;
}

function package_url(string $version): string
{
    return UPDATE_URI . '/releases/download/' . $version . '/trakk.zip';
}

/**
 * The newest release's own description, a file beside its ZIP: GitHub's API allows 60 calls an hour and address.
 * Without an answer the last one: an offer already shown does not vanish. Asked once a request.
 *
 * @return array{version: string, requires: string, requires_php: string, tested: string}|null
 */
function latest_release(): ?array
{
    static $asked = false;
    static $release = null;
    if ($asked) {
        return $release;
    }
    $asked = true;
    $res = wp_remote_get(UPDATE_URI . '/releases/latest/download/update.json', [
        'timeout' => wp_doing_cron() ? TIMEOUT : 2, // per redirect, and somebody waits for the page
        'limit_response_size' => 4096,
    ]);
    $json = is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200 ? '' : wp_remote_retrieve_body($res);
    $release = release_from($json);
    if ($release !== null) {
        set_site_transient(RELEASE_TRANSIENT, $json, 30 * 24 * 3600);
    } else {
        $release = release_from((string) get_site_transient(RELEASE_TRANSIENT));
    }
    if ($release === null) {
        return null;
    }
    // An automatic update checks PHP by itself, and the plugin list tells. Not so for WordPress: as if up to date.
    if (!is_wp_version_compatible($release['requires'])) {
        $release['version'] = VERSION;
    }
    // "7.1" covers 7.1.1, which WordPress would take for untested.
    $running = get_bloginfo('version');
    if (str_starts_with($running, $release['tested'] . '.')) {
        $release['tested'] = $running;
    }
    return $release;
}
