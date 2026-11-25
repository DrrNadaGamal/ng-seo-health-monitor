# NG SEO Health Monitor

Finds broken URLs and broken images on your WordPress site, and fixes 404s with redirects that you approve.

## What it does
- Logs 404 errors with a hit counter (one row per URL, not one row per visit).
- Privacy: no IP address and no user agent are stored. The referrer host is stored only if you switch it on (host only, no path or query).
- Suggests a 301 redirect target for each 404: the published post or page whose slug is closest to the missing URL. Nothing is redirected until you press "Approve 301".
- Redirect table: add, list and delete redirects (301, 302, 307, 308), with hit counts. Loops and non-http(s) targets are refused.
- Broken image check: scans published posts and pages for local upload images whose file is missing, and featured images whose file is missing.
- Auto cleanup: row cap, retention days, daily purge. Asset extensions (png, css, js, ...) are not logged.
- Languages: English, Arabic, French, German (FR and DE are machine translations and need a native review). Menu labels and main buttons are translated; some settings labels are English only.
- Uninstall removes both tables and settings.

## Where
Tools > SEO Health.

## Tested
51 checks run in WordPress Playground (WP latest, PHP 8.2): logging, counters, privacy, suggestions, redirect validation, runtime redirect, row cap, purge, image scan, escaping, permission and nonce checks.

## Honest limits
- Image check covers local uploads only. External image URLs are not requested.
- Suggestion quality is slug similarity, not meaning. Always review before approving.
- Redirect runs only on requests WordPress treats as 404. Redirecting a URL that still resolves is not supported.
- Not tested with page-cache plugins that serve 404 pages without loading WordPress (those 404s are not logged).
- Settings screen is basic. Not tested on multisite.

Product direction, scope and delivery: Nada Gamal
