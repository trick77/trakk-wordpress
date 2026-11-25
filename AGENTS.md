# AGENTS.md

WordPress plugin for Trakk: browser script tag and server feed. Contract: `INTEGRATION.md` in trick77/trakk. No ship
here (user: ship has nothing to do with PHP); CI is the gate.

## Decisions
- Settings page only, no env or constants (user). Trakk's other products take the same values from the environment.
- Slug `trakk` is an exception (user) to Trakk's rule that stored names never carry the product's name: WordPress
  stores folder and file in `active_plugins`. Options, table and cron hook stay neutral (`traffic_observability*`).
- Never harms the site: everything after the response where the SAPI allows it, every entry point in try/catch.
- No Composer packages.

## Workflow
- Commit subjects Go style: `area: what`.
- Release: Actions "Release" by hand, by the user.
