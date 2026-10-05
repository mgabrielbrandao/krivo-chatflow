# Krivo ChatFlow

A WordPress plugin: a light floating chat that qualifies visitors with a short flow (one form, steps, or a
conversation), stores the lead in your own database and continues by WhatsApp link, e-mail or webhook.

* Plugin readme (what WordPress.org shows): [`readme.txt`](readme.txt)
* How it is built and tested: [`docs/BUILD.md`](docs/BUILD.md)
* Hooks for developers: [`docs/HOOKS.md`](docs/HOOKS.md) · Events, triggers and the webhook payload: [`docs/EVENTS.md`](docs/EVENTS.md)
* Everything that can leave your site: [`docs/EXTERNAL-SERVICES.md`](docs/EXTERNAL-SERVICES.md)
* WordPress.org audit: [`docs/WORDPRESS-ORG-COMPLIANCE.md`](docs/WORDPRESS-ORG-COMPLIANCE.md)
* Design documents (Portuguese): [`docs/`](docs/README.md)

```bash
composer install && npm ci      # development tools only
npm run build                   # compile src/ into assets/
vendor/bin/phpunit              # PHP tests (needs WP_CORE_DIR and a database, see docs/BUILD.md)
npm run test:js                 # shared-logic tests
npm run test:e2e                # browser tests (needs a throw-away WordPress site, see docs/BUILD.md)
php bin/build-zip.php && php bin/release-check.php
```

License: GPL-2.0-or-later (see `license.txt`).
