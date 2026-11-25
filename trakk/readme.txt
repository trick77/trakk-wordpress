=== Trakk ===
Contributors: trick77
Tags: traffic, monitoring, bots, security, self-hosted
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/license/mit

Reports your site's traffic to your own Trakk server: people, bots, sign-in attempts and probes.

== Description ==

Trakk is self-hosted web traffic observability: all incoming traffic, not only the part that runs JavaScript. This
plugin connects a WordPress site to a Trakk server you run yourself.

* The browser script reports page views, clicks, forms, errors and web vitals.
* The server feed reports every request WordPress answers, so bots, failed sign-ins, XML-RPC calls and probes for
  missing pages show up too.

Nothing is loaded on your pages and nothing about your visitors is sent until you enter the address of your Trakk
server under Settings → Trakk.

== External services ==

This plugin works with Trakk, free and open source software you install on a server of your own. There is no account
and no service of the plugin's author. The server is the one you enter under Settings → Trakk; without it nothing is
contacted.

With the Trakk URL set, every page of your site loads `trakk.js` from that server, and the visitor's browser sends it
page views (address, title, referrer), clicks on outbound links and downloads, form starts and submits (the form's id,
name and action, no values), search terms, script errors, web vitals, browser properties that tell people from
automated browsers, and a random visitor id kept in the browser's local storage. The server also sees the visitor's IP
address.

With the site and a feed key set too, your WordPress server sends it, for every request: time, IP address, method,
path without the query, status, duration, user agent, referrer without its query, language, client hint and fetch
metadata headers, the numeric id of a signed-in user, the AJAX action or XML-RPC method, and whether a sign-in failed.

The plugin also asks its GitHub releases (https://github.com/trick77/trakk-wordpress) for a newer version, when
WordPress looks for updates. GitHub sees your server's address and the plugin's version, nothing about visitors.

What that server keeps, for how long, and the privacy policy that applies to it are yours. Tell your visitors.
Trakk: https://github.com/trick77/trakk

== Installation ==

1. Run a Trakk server and list your site in its `sites.json`.
2. Activate the plugin and enter the server's address under Settings → Trakk.
3. For the server feed, create a key with `trakk -new-feed-key` and enter it with the site's domain.

== Frequently Asked Questions ==

= Does it slow my site down? =

The script loads deferred. A request is written to a small table once it is done and sent after the answer where PHP
can do that, otherwise by WP-Cron. If the Trakk server is down, requests are dropped, never held up.

= I use a page cache =

Cached pages do not reach WordPress: people on them come in through the script, bots on them do not.

== Changelog ==

= 0.2.0 =
* Updates from the plugin's GitHub releases.

= 0.1.0 =
* First release.
