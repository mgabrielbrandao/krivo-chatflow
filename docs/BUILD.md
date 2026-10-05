# Building Krivo ChatFlow

The PHP code is shipped as written. The browser code (the chat widget and the admin app) is written in
modern JavaScript, JSX and CSS in `src/`, and **compiled and minified** into `assets/` with
[esbuild](https://esbuild.github.io/). The compiled files are not obfuscated; they are the same code with
whitespace and long names removed. Nothing is generated at run time.

| | |
|---|---|
| Source | `src/` |
| Output (shipped) | `assets/frontend/*` and `assets/admin/*` |
| Tool | esbuild (the exact version is in `package-lock.json`) |
| Build script | `bin/build.mjs` (one readable file, about 70 lines) |
| Node.js | 20 or newer (developed on 22) |

## Build

```bash
npm ci          # installs exactly what package-lock.json says
npm run build   # writes assets/frontend/* and assets/admin/*
```

`npm run build:dev` produces unminified files; `npm run watch` rebuilds on every change.

### What is built

| Source | Output | Loaded |
|---|---|---|
| `src/frontend/loader.js` | `assets/frontend/loader.js` | On every page that shows a chat (about 2 KB gzipped). |
| `src/frontend/bubble.css` | `assets/frontend/bubble.css` | Same. |
| `src/frontend/panel.js` (+ `src/shared/*`) | `assets/frontend/panel.js` | Only when a visitor shows interest in the chat. |
| `src/frontend/panel.css` | `assets/frontend/panel.css` | Same. |
| `src/admin/index.jsx` (+ `src/admin/**`, `src/shared/*`) | `assets/admin/admin.js` | Only on the plugin's own admin screens. |
| `src/admin/admin.css` | `assets/admin/admin.css` | Same. |

The admin app does **not** bundle React or any WordPress package. `@wordpress/element`, `@wordpress/i18n`
and `@wordpress/api-fetch` are replaced at build time by the globals WordPress already provides
(`wp.element`, `wp.i18n`, `wp.apiFetch`), and the PHP side declares them as script dependencies. No third-party
library is bundled in any shipped file.

### Verify that the shipped files come from the shipped sources

```bash
php bin/build-zip.php && php bin/release-check.php
```

The release check rebuilds `src/` into a temporary folder and compares every compiled file, byte for byte,
with the one in the ZIP.

## Development environment

```bash
composer install     # PHPUnit, PHP_CodeSniffer, WordPress Coding Standards (dev only, never shipped)
npm ci
```

| Command | What it does |
|---|---|
| `composer lint` | PHP_CodeSniffer with `phpcs.xml.dist`: WordPress-Extra, WordPress-Docs, PHPCompatibilityWP (PHP 7.4+), i18n and prefix rules. Must report nothing. |
| `composer test` / `vendor/bin/phpunit` | PHP tests against a real WordPress and database (see below). |
| `npm run test:js` | Tests of the code shared by the server and the browser (conditions, validation, templates, contrast) against the same JSON fixtures the PHP tests use. |
| `npm run test:e2e` | Browser tests (Chromium, real WordPress): editor, leads, routing, webhooks, e-mail through a real SMTP server, onboarding, the visitor's chat, accessibility with axe, a hostile theme, phone layout. |
| `npm run size` | Fails when a shipped file exceeds its gzip size budget. |
| `php bin/build-zip.php` | Builds `build/krivo-chatflow-<version>.zip` from an allowlist. |
| `php bin/release-check.php` | Checks that ZIP (see below). |

### PHP tests

They use the official WordPress test library (`wp-phpunit`) and need a MySQL/MariaDB database:

```bash
export WP_CORE_DIR=/path/to/a/wordpress          # a WordPress checkout with a wp-tests-config.php
vendor/bin/phpunit
```

The tests never reach the internet: HTTP requests that are not to the local stub server are blocked.

### Browser tests

They run against a throw-away WordPress site (they create and delete chats, leads, connections and one user):

```bash
export KRIVO_E2E_URL=http://localhost:8080
export KRIVO_E2E_USER=admin KRIVO_E2E_PASS='…'
export KRIVO_E2E_WP=/path/to/a/script/that/runs/wp-cli/for/that/site
npm run test:e2e                     # everything
node tests/e2e/run.mjs widget        # only files whose name contains "widget"
```

Chromium is found in `PLAYWRIGHT_BROWSERS_PATH` (or set `KRIVO_E2E_CHROMIUM`). `smtp-server` (a dev dependency)
plays the customer's mail server, and a tiny HTTP server plays the webhook receiver.

## The release check

`php bin/release-check.php` runs over the ZIP, not the working tree, and fails on:

* anything that is not an allowed file in the package (tests, vendor, dotfiles, logs, maps, backups);
* version mismatches between the plugin header, the constant, `readme.txt` and `package.json`;
* `readme.txt` problems (Contributors, tags, short description, required sections, External services with
  Terms and Privacy URLs for every service, source-code statement);
* `eval`, `create_function`, `base64_decode`, `curl_*`, shell execution, debug output, `unserialize`, `$_REQUEST`,
  `$_FILES` / `move_uploaded_file`, `ini_set`;
* `<script>`, `<style>` or `<link>` printed by PHP (all assets use the enqueue APIs);
* REST routes without a `permission_callback`, or with `__return_true`;
* admin-ajax / admin-post handlers without a nonce **and** a capability check;
* a numeric menu position (the menu must not displace WordPress or other plugins);
* HTTP requests made anywhere other than the SSRF-safe client, and external hosts in PHP, JS or CSS;
* undocumented external services, placeholder legal URLs, telemetry;
* credentials, private keys, TODOs, `console.log`, debugger statements;
* compiled files that do not rebuild from the shipped sources, obfuscation patterns, dangling source maps;
* missing translations, a wrong text domain, bundles over the size budget;
* any PHP_CodeSniffer finding.

`node bin/check-links.mjs` fetches every URL in `readme.txt` and `docs/*.md` and fails on any that does not answer
(it needs internet access, so it is a separate step run by a person before publishing).
