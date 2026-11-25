# AGENTS.md

WordPress plugin for Trakk: browser script tag and server feed. Contract: `INTEGRATION.md` in trick77/trakk. No ship
here (user: ship has nothing to do with PHP); CI is the gate.

## Decisions
- Settings page only, no env or constants (user). Trakk's other products take the same values from the environment.
- Slug `trakk` is an exception (user) to Trakk's rule that stored names never carry the product's name: WordPress
  stores folder and file in `active_plugins`. Options, table and cron hook stay neutral (`traffic_observability*`).
- Never harms the site: after the response where the SAPI can (`fastcgi_finish_request`), else one INSERT.
- FPM sends at once, no interval (user): a quiet site's last request would wait for the next visitor. Waiting over 55
  minutes: dropped, never sent late (user).
- 10 s timeout where INTEGRATION.md says 30: the sender holds a PHP worker.
- No Composer packages. No updater, no `Update URI`: wordpress.org refuses both kinds of self-update.
- `route` stays empty: Trakk's path rules name requests and page views alike.

## Traps
- The object cache is closed before the last shutdown functions, `exit` in one ends all later ones.
- `wp_schedule_single_event` writes the whole cron list as the request read it: never after a slow POST.
- A key belongs to one Trakk: a changed URL needs it typed again. A malformed one once kept the old key with the new
  URL.
- Plugin Check (wordpress.org's reviewers run it) must stay clean; PHP-CS-Fixer may move a `phpcs:ignore` off its line.

## Workflow
- Commit subjects Go style: `area: what`.
- Release: Actions "Release" by hand, by the user.
