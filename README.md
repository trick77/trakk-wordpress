# Trakk for WordPress

The WordPress plugin for [Trakk](https://github.com/trick77/trakk), self-hosted web traffic observability. It adds two
things to a site, both to your own Trakk server:

- **The browser script** on every page: page views, clicks, forms, errors and web vitals.
- **The server feed**: every request WordPress answers, so bots, sign-in attempts, XML-RPC and probes for missing pages
  show up too.

Without settings it loads nothing and sends nothing.

## Setup

1. On the Trakk server, list the site in `sites.json` and create a feed key
   (`docker compose run --rm trakk -new-feed-key`), then restart Trakk. `domain` is the exact host of the pages: with
   and without `www` are two sites. A start for a WordPress site:

   ```json
   {
     "domain": "www.example.com",
     "product": "Blog",
     "kind": "site",
     "feed_key_sha256": "the hash -new-feed-key printed",
     "path_rules": [
       {"pattern": "/author/{seg}/", "template": "/author/:name"},
       {"pattern": "/tag/{seg}/", "template": "/tag/:name"},
       {"pattern": "/page/{int}/", "template": "/page/:n"}
     ]
   }
   ```

   Leave `/wp-content/` and `/wp-includes/` without a rule: which plugin's files a scanner asks for is the clue.
2. Install the ZIP of the [latest release](https://github.com/trick77/trakk-wordpress/releases) under Plugins → Add
   Plugin → Upload. WordPress 6.5 and PHP 8.1 or newer.
3. Settings → Trakk (on a network: for super admins, the URL becomes a script on every page): the URL alone turns on the script; site and feed key add the feed. **Test connection** asks Trakk
   about what the form holds, before anything is saved.

### Behind a proxy

Trakk needs the visitor's address. Where the web server does not resolve it, set **Trusted proxies** to the number of
proxies that append to `X-Forwarded-For`; the page shows your address as it is read. The official `wordpress:apache`
image resolves it already (mod_remoteip): leave 0. The FPM image does not.

### Caches, optimizers, CSP

- The script reads its collector off its own address. Exclude `trakk.js` from anything that bundles, delays or
  self-hosts scripts (WP Rocket, LiteSpeed Cache, Autoptimize); Cloudflare's Rocket Loader is told by the tag.
- A Content-Security-Policy needs the Trakk host in `script-src` and `connect-src`.

## What the feed does not see

- Whatever never reaches PHP: page cache hits, a CDN's answers, static files. People on cached pages come in through
  the script, bots on them do not. nginx with `try_files $uri =404` also answers missing `.php` files itself.
- Queries, which Trakk does not store: `/?author=1`, `/?rest_route=…` and plain permalinks (`/?p=1`) all read as `/`.
- Trakk takes a person without a script beacon for automation: one who blocks the script, or only opens
  `wp-login.php`, where it does not run. Somebody signed in is recognized without it.

## How it sends

A request is written to a small table after WordPress is done with it, 5000 rows at most, and sent to Trakk at once
where PHP can go on after the answer (FPM, LiteSpeed). Under mod_php WP-Cron sends, within a minute or so while the
site has visitors; on a quiet one the last requests wait for the next visit. What was not delivered within 55 minutes
is dropped: Trakk would date it wrongly. A system cron on `wp-cron.php` every few minutes prevents that. On FPM and
LiteSpeed WP-Cron only picks up what a failure left behind.

If Trakk is down, the site does not notice. Signed-in users are reported as `u_<id>`, never by name or address.

## Development

```sh
composer install
composer lint            # PHP-CS-Fixer; composer fix writes
test/wordpress.sh        # once: WordPress's source, for PHPStan
composer stan            # PHPStan, level 8
composer test            # PHPUnit
test/e2e.sh              # Docker: WordPress on mod_php and FPM, Trakk, a stub that fails on request, Plugin Check
```

CI runs all of it on every pull request, the unit tests on PHP 8.1 to 8.6, the end-to-end test on WordPress 6.5 and the
current one.

Release: set the version in `trakk/trakk.php` (header and constant) and `trakk/readme.txt` (`Stable tag`), merge, run
the Release workflow.

## License

MIT
