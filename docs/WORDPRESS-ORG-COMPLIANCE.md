# WordPress.org compliance audit

This is the audit of Krivo ChatFlow 0.1.0 against the WordPress Plugin Directory guidelines, written from the point
of view of a reviewer. Every claim below is backed by something that can be re-run:

| Check | How to re-run | Result for 0.1.0 |
|---|---|---|
| Plugin Check (the official checker, all categories, experimental and low-severity findings included), run on the **extracted ZIP** | `wp plugin check <extracted-folder> --include-experimental --include-low-severity-errors --include-low-severity-warnings --severity=0` | No errors, no warnings |
| WordPress Coding Standards (WordPress-Extra, WordPress-Docs) and PHP 7.4+ compatibility | `composer lint` | No errors, no warnings |
| Release check over the ZIP (security patterns, WordPress.org rules, regressions from an earlier Krivo plugin) | `php bin/build-zip.php && php bin/release-check.php` | All checks pass |
| Reproducible build (the compiled files equal a rebuild of `src/`) | part of the release check | Identical, byte for byte |
| PHP tests against real WordPress and MariaDB | `vendor/bin/phpunit` | 539 tests, all passing |
| Shared PHP/JS logic tests | `npm run test:js` | 144 tests, all passing |
| Browser tests (Chromium, real WordPress, real SMTP server, webhook receiver, axe) | `npm run test:e2e` | All passing, also on a clean install made from the ZIP with `WP_DEBUG` on |
| Size budget | `npm run size` | Within budget (front end loader 2.1 KB gzip) |

What this audit could **not** do is listed at the end ("Limits"). Please read it.

---

## The guidelines

### 1. Plugins must be compatible with the GNU General Public License
* **How**: GPL-2.0-or-later, stated in the plugin header, `readme.txt` and `license.txt` (the unmodified GPL v2 text).
  All code is original. No third-party code is bundled: PHP uses WordPress core's own libraries (PHPMailer, wp-json, etc.);
  the admin app uses the React, `wp-i18n` and `wp-api-fetch` that ship with WordPress.
  The logo is original artwork by the author (`brand/`, `assets/images/`).
* **Files**: `krivo-chatflow.php`, `readme.txt`, `license.txt`, `package.json` (devDependencies only; none is shipped).
* **Verified by**: release check "License", "no third-party libraries" (the ZIP contains no `vendor/` or `node_modules/`).
* **Risk**: none known. The `devDependencies` (esbuild, axe-core, playwright-core, smtp-server, jsdom) are used only to
  build and test and are not part of the package.

### 2. Developers are responsible for the contents and actions of their plugins
* **How**: no bundled libraries to track. Secrets are encrypted; capability and nonce checks everywhere; see the sections below.
* **Verified by**: Plugin Check, PHPCS, the release check, PHPUnit security tests (permissions on every REST route,
  per-section capability checks, SSRF, CSV injection, header injection, XSS in e-mails and templates).

### 3. A stable version must be available from the plugin directory page
* Not applicable until publication. `Stable tag` = `0.1.0` = plugin header = constant = `package.json` (release check).

### 4. Code must be (mostly) human readable
* **How**: the PHP is shipped as written. The browser code is minified by esbuild from readable sources that are
  **shipped in the package** (`src/`), with the build script (`bin/build.mjs`), `package.json` and the lock file.
  `docs/BUILD.md` explains the commands and `readme.txt` names the location and the public repository.
  The release check rebuilds `src/` into a temporary folder and compares every compiled file with the one in the ZIP.
  No obfuscation (checked for hexadecimal identifiers, `eval`, `Function()`, `atob`, `base64_decode`, `str_rot13`…).
* **Files**: `src/**`, `bin/build.mjs`, `docs/BUILD.md`, `assets/**`.
* **Risk**: the public repository named in `readme.txt` (`https://github.com/mgabrielbrandao/krivo-chatflow`) must be
  public at submission time. This could not be checked from the build environment.

### 5. Trialware is not permitted
* No locked features, no licence keys, no time limits, no upsell code.

### 6. Software as a Service is permitted
* The plugin is not a service wrapper: everything works locally. Optional integrations (webhooks, SMTP) go to the
  addresses the site owner enters.

### 7. Plugins may not track users without their consent
* **How**: no telemetry, no analytics service, no "phone home", no update or licence checks. The plugin's own numbers
  (views, opens, leads) are counted on the site, in its own table, as daily totals without identifiers; this can be
  turned off in Settings. The widget sets **no cookies**; it uses `sessionStorage` for a few flags of the visit and
  `localStorage` for a dismissed greeting, and says so in `readme.txt` and in the suggested privacy-policy text.
  No IP address is stored with a lead (a keyed hash of the address names a short-lived rate-limit transient).
  Consent: a consent field type that records acceptance and time; retention period; export and erase through
  WordPress's personal-data tools.
* **Files**: `includes/Core/Stats/*`, `includes/Core/Leads/PrivacyTools.php`, `includes/Core/Submission/AntiSpam/*`,
  `docs/EXTERNAL-SERVICES.md`.
* **Verified by**: release check "no telemetry", "no hosts other than the documented ones"; `PrivacyTest`.

### 8. Plugins may not send executable code via third-party systems
* No remote code, no remote updates, no CDN scripts, no remote fonts or styles. The webhook payload is data (JSON),
  never code. Verified by the release check (no external hosts in JS/CSS; no `eval`).

### 9. Developers and their plugins must not do anything illegal, dishonest or morally offensive
* The WhatsApp feature builds a standard click-to-chat link; no scraping, no unofficial API, no automated sending.
  The visitor sends the message from WhatsApp themselves.

### 10. Plugins may not embed external links or credits on the public site without explicitly asking
* **How**: the only credit is the optional "Powered by Krivo Works" line inside the chat window. It is **off by default**.
  Turning it on is a deliberate action in Settings, and making the line a link is a **second, separate** choice that is
  only available once the first is on. The user and the time of the consent are recorded; withdrawing the line also
  withdraws the link. The onboarding wizard shows both choices unticked. No other external link, credit or image is
  ever printed.
* **Files**: `includes/Core/Settings/Settings.php` (consistency rules), `includes/Core/Flow/Manifest.php` (signature),
  `src/frontend/panel.js` (rendered as semantic text/link, not a pseudo-element), `src/admin/screens/SettingsScreen.jsx`.
* **Verified by**: `FoundationTest`, `FlowTest`, `RestAdminTest`, and the browser tests "opt-in credit" (visitor) and "two consents" (admin).

### 11. Plugins should not hijack the admin dashboard
* **How**: one top-level menu item that does not claim a fixed position (it is appended after core menus, so it cannot
  displace anything), shown only to people with a capability of the plugin. The plugin prints **one** dismissible
  notice, only on the Plugins screen, only until a chat exists or it is dismissed. The post-activation redirect happens
  once, never on bulk activation, in the network admin, in AJAX or WP-CLI, and never for people without access.
  The plugin does not print notices on other screens or touch the dashboard widgets, and its CSS is scoped to its own screens.
* **Files**: `includes/Admin/*`. **Verified by**: `AdminTest`, release check "menu position".

### 12. Public pages should not spam
* `readme.txt` has accurate, non-promotional descriptions; tags: 5, relevant. No affiliate links.

### 13. Plugins must use WordPress's default libraries
* **How**: REST API, Settings API, `wpdb` with `prepare()`, `dbDelta`, WP-Cron, `wp_mail()`/PHPMailer (scoped),
  `wp_safe_remote_request()`, the media library (`wp.media`) for images, `wp.element`/`wp.i18n`/`wp.apiFetch`
  for the admin app, WordPress's personal-data exporter/eraser, privacy policy guide, `wp_set_script_translations`.
  Nothing from core is bundled again.

### 14. Frequent commits to SVN / 15. Version numbers must be incremented
* Release process, not code. `bin/release-check.php` verifies that all version numbers agree.

### 16. A complete plugin must be available at the time of submission
* The package is complete and functional; "no mocks" is enforced by the browser tests, which exercise the real
  pipeline (submission, queue, e-mail through a real SMTP server, signed webhooks, WhatsApp link).

### 17. Respect trademarks, copyrights and project names
* The name is **Krivo ChatFlow**; WhatsApp, n8n, Make and Zapier are only mentioned as things the plugin can work with.
  The logo does not use any WhatsApp artwork (it is a speech bubble whose negative space forms a "K"; see `brand/README.md`).
  `readme.txt` states that WhatsApp is a trademark of its owner and that the plugin is not affiliated with it.
  The release check fails if a third-party brand appears in the plugin name, slug or text domain.

### 18. The directory is maintained at the reviewers' discretion
* Nothing to do.

---

## The common review findings, and how each one is prevented

These came back from an earlier Krivo plugin and are treated as **forbidden regressions**: each is an automatic
check in `bin/release-check.php` that fails the release.

| Earlier finding | Prevented by |
|---|---|
| CSS/JS not loaded with the enqueue APIs | Check: no `<script>`, `<style>` or `<link>` is printed by PHP; `wp_enqueue_*` is used. The only inline data are a JSON `<script type="application/json">` block printed with `wp_print_inline_script_tag()` and `wp_add_inline_script()`; styles use the CSSOM, so there are no `style` attributes either (browser test). |
| Admin requests without enough protection | Check: every `admin-post`/`admin-ajax` handler contains a nonce check **and** a capability check; every REST route has a real `permission_callback` (never `__return_true`). PHPUnit calls every admin route anonymously and as a user without capabilities and expects 401/403. |
| Invalid external URLs | `bin/check-links.mjs` fetches every URL in `readme.txt` and the docs (needs internet); the release check forbids placeholder legal URLs and unknown hosts in PHP, JS and CSS. |
| Wrong `Contributors` | Check: `Contributors` is exactly `gabrielbrandaoai`. |
| Compiled JS without public source | Source shipped in the ZIP, documented in `docs/BUILD.md` and `readme.txt`, and reproducibility is verified byte for byte. |
| External services not documented | `readme.txt` "External services" section (name, purpose, when, data, official URL, Terms, Privacy for each) and `docs/EXTERNAL-SERVICES.md`; checks that every host in the code is documented and that each service named is described. |
| Uploads outside the native APIs | Check: no `$_FILES`, no `move_uploaded_file`. Images come from the WordPress media library picker; custom SVG icons are pasted as text, sanitised on the server and stored in the flow, never uploaded. |
| Admin menu position | Check: `add_menu_page()` ends with a `null` position; `AdminTest` verifies it lands after the core menus. |

## Where the plugin talks to the outside world

See [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md). In short: nothing on its own; `wa.me` from the visitor's browser;
webhooks to addresses an administrator added and confirmed; an SMTP server the administrator entered. All server
requests go through one SSRF-safe client (`includes/Infrastructure/Http/SafeClient.php`): HTTPS by default, no
redirects, private and reserved addresses refused unless the connection allows them, short timeout, from a background
queue. The release check verifies that nothing else in the code can make an HTTP request.

## Security design, briefly

* **Capabilities**: 13 plugin capabilities (granted to administrators on activation, assignable to other roles).
  Changing automations, e-mail recipients or webhooks needs `manage_integrations` even for people who may edit chats.
* **Public endpoints**: stateless HMAC token (minimum age, maximum age), honeypot, per-visitor rate limit, a site-wide
  circuit breaker, Origin check, duplicate suppression; the manifest and the events endpoints are read-only/limited and
  never contain private data (destinations, e-mails, automations, secrets).
* **Data**: prepared SQL everywhere (dynamic placeholder lists are built from fixed fragments); values escaped on output
  for the context (HTML, attribute, JSON, URL, CSV with formula neutralisation, e-mail headers without line breaks).
* **Secrets** (webhook addresses and tokens, SMTP password, signing keys): encrypted with libsodium keyed from the site's
  salts, never returned by the API, never logged (a redactor removes them from every log line and error).
* **Uninstall**: nothing is deleted unless the administrator turned on "Delete all plugin data"; multisite is handled.

## Limits of this audit (please read)

* The guideline text could not be re-fetched from wordpress.org: the build environment has no general internet
  access. The audit follows the 18 guidelines as known at the time of writing and the **current Plugin Check code**
  (git commit `25dfd0f`), which does encode the current rules for common issues. Re-read the live guidelines before submitting.
* The legal and product links (WhatsApp, n8n, Make, Zapier terms and privacy pages, https://krivo.works/,
  the GitHub repository) **could not be verified** for the same reason. They were written from knowledge of those sites.
  Run `node bin/check-links.mjs` with internet access before submitting; the release must not ship a link that fails.
* Runtime tests ran on WordPress 7.1.2 and PHP 8.3 only, in Chromium only. PHP 7.4 and WordPress 6.5 (the declared
  minimums) are covered by static compatibility analysis (PHPCompatibilityWP, WordPress API usage), not by running them.
  Multisite and Firefox/Safari were not exercised.
* Conflict testing with Elementor, WooCommerce, popular SMTP plugins and cache/minification plugins could not be done:
  they cannot be downloaded in the build environment. The widget has no dependency on any of them, is isolated from theme
  CSS (tested against a deliberately hostile theme), and only reads WooCommerce/Elementor state through safe `class_exists` checks.
* The WhatsApp hand-off was tested up to the moment the browser opens `https://wa.me/…` (the test replaces that page with
  a stand-in). That the number and text show correctly inside WhatsApp itself was not tested.
* Not yet produced: WordPress.org banner images and screenshots for the plugin page. The 128/256 px icons are in `brand/export/`.
