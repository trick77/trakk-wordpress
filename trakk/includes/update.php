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
    $release = latest_release(true);
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
    if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== SLUG
        || plugin_basename(dirname(__DIR__) . '/trakk.php') !== SLUG . '/trakk.php') {
        return $result;
    }
    $release = latest_release(false);
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
 * The newest release as last told, the one source of the offer and its details. Its own description is a file beside
 * its ZIP: GitHub's API allows 60 calls an hour and address. $ask: ask again, once a request. Without an answer what
 * was told stands, an offer already shown does not vanish; a release that was pulled goes.
 *
 * @return array{version: string, requires: string, requires_php: string, tested: string}|null
 */
function latest_release(bool $ask): ?array
{
    static $asked = false;
    $told = static fn(): ?array => release_from((string) get_site_transient(RELEASE_TRANSIENT));
    $release = $told();
    if (($ask || $release === null) && !$asked) {
        $asked = true;
        ask_github();
        $release = $told();
    }
    if ($release === null) {
        return null;
    }
    // An automatic update checks PHP by itself, and the plugin list tells. Not so for WordPress: as if up to date.
    if (!is_wp_version_compatible($release['requires'])) {
        $own = ['requires' => 'Requires at least', 'requires_php' => 'Requires PHP'];
        $own = get_file_data(dirname(__DIR__) . '/trakk.php', $own);
        $release = ['version' => VERSION, 'requires' => $own['requires'], 'requires_php' => $own['requires_php']]
            + $release;
    }
    // "7.1" covers 7.1.1, which WordPress would take for untested.
    $running = get_bloginfo('version');
    if (str_starts_with($running, $release['tested'] . '.')) {
        $release['tested'] = $running;
    }
    return $release;
}

function ask_github(): void
{
    $res = wp_remote_get(UPDATE_URI . '/releases/latest/download/update.json', [
        'timeout' => wp_doing_cron() ? TIMEOUT : 2, // per redirect, and somebody waits for the page
        'limit_response_size' => 4096,
    ]);
    if (is_wp_error($res)) {
        return;
    }
    $json = wp_remote_retrieve_body($res);
    $status = wp_remote_retrieve_response_code($res);
    if ($status === 200 && release_from($json) !== null) {
        set_site_transient(RELEASE_TRANSIENT, $json, 30 * 24 * 3600);
    } elseif ($status === 404) {
        delete_site_transient(RELEASE_TRANSIENT);
    }
}
