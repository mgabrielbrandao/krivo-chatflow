=== Krivo ChatFlow ===
Contributors: gabrielbrandaoai
Tags: chat widget, lead capture, contact form, whatsapp link, webhook
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A floating chat that qualifies visitors with a short flow, saves the lead on your site and continues by WhatsApp link, e-mail or webhook.

== Description ==

**Krivo ChatFlow** puts a light chat bubble on your site. A visitor opens it, answers a few questions, and you get a qualified lead: stored in your own WordPress database, sent to the right person, and (if you want) continued on WhatsApp with the message already written.

It is built for fast contact and conversion: small, accessible, and honest about where data goes.

= What you can do =

* **Four ways to show it**: one form, several steps with a progress bar, a conversation (one question at a time, like a chat), or **only a button**: a floating button with a customisable welcome message (title, text and button) that opens WhatsApp, a page, a phone call or an e-mail. No form, no lead, nothing stored about the visitor.
* **Questions that adapt**: show or hide fields, jump to another step or finish early depending on the answers.
* **Real routing**: send each lead to a different person by budget, service, device, page or campaign. Each destination has its own WhatsApp number and e-mail addresses.
* **WhatsApp without a paid API**: when the visitor finishes (or taps the button), their browser opens a `wa.me` link with the message pre-filled. Your site never talks to WhatsApp, and the number is not printed in the page of a "button only" chat: it is resolved by your server, through your routing rules, when the visitor taps.
* **E-mail**: a notification for your team and an optional confirmation for the visitor, sent in the background. Use WordPress's mailer, your SMTP plugin, or an SMTP server configured only for this plugin.
* **Webhooks** for n8n, Make, Zapier or your own system: signed (HMAC), retried automatically, with a log and a "send a test" button.
* **Leads inside WordPress**: search, filter, change status, bulk actions, CSV export that Excel opens correctly.
* **Business hours** and **display rules** (by page, content type, device, logged-in state).
* **Analytics from your own site**: how many saw the bubble, opened it, started, finished and went to WhatsApp. No third party.
* **Privacy tools**: consent field, retention period, export and erase through WordPress's own privacy tools, suggested privacy-policy text.
* **Live preview**: the editor shows the real chat, with your changes, as you type.
* **Templates** to start in a minute: WhatsApp button, simple contact, request a quote, support, clinic, real estate.
* **Shortcode** `[krivo_chatflow id="123"]` to place a chat inside a page, and a small JavaScript API and events for developers.

= Built to be light and safe =

* The bubble is printed by the server and costs one small script and one small stylesheet. The chat window code is only downloaded when a visitor shows interest.
* No jQuery, no CDN, no cookies set by the widget, and no web font on your visitors' pages. The admin screens of the plugin use a font that ships inside the plugin.
* Works with keyboard and screen readers; tested with an automated accessibility checker and in a real browser.
* Every admin action checks capabilities and nonces; SQL is prepared; output is escaped; the REST API has real permission callbacks.
* No telemetry. The plugin never contacts Krivo Works or anyone else on its own. See "External services" below.

= Optional credit =

A small "Powered by Krivo Works" line inside the chat window is **off by default**. You can turn it on in Settings, and, as a separate choice, make it a link. Nothing is shown or sent unless you choose it.

= Trademarks =

WhatsApp is a trademark of WhatsApp LLC. Krivo ChatFlow is not affiliated with, endorsed by or sponsored by WhatsApp or Meta Platforms. The plugin only builds a standard `wa.me` link; no WhatsApp API is used.

== Installation ==

1. Upload the `krivo-chatflow` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin. You are taken to the Krivo ChatFlow dashboard, which guides you through four steps: pick a template, say where leads go, choose the look, publish.
3. Open **Krivo ChatFlow > ChatFlows** to edit your chat at any time. Nothing appears on your site until you publish.
4. Optional: add a WhatsApp number or e-mail addresses in **Routing**, and check e-mail delivery with the test button in **Settings**.

If you use a cache plugin or a CDN, clear its cache after publishing so visitors get the latest version.

== Frequently Asked Questions ==

= Does it need the WhatsApp Business API or any paid service? =

No. The visitor's browser opens `https://wa.me/<number>?text=<message>`, the standard click-to-chat link. Nothing is sent by your server.

= Where are the leads stored? =

In custom tables in your own WordPress database. Nothing is sent to Krivo Works or any other third party.

= Does the plugin send data anywhere by itself? =

No. Data leaves your site only through things you set up: a webhook connection you create, an SMTP server you enter, or a WhatsApp link the visitor opens. See "External services".

= Does it set cookies? =

No. It keeps a few flags in the visitor's `sessionStorage` (for example the first campaign parameters) and remembers a dismissed tooltip in `localStorage`. They are never sent anywhere except inside the lead the visitor submits.

= Is it GDPR / LGPD friendly? =

It gives you the tools: a consent field, only the data you ask for, a retention period, deletion, CSV export, and integration with WordPress's personal-data exporter and eraser. You are still responsible for your own privacy policy; suggested text is added to Settings > Privacy > Policy Guide.

= Can I show the chat only on some pages? =

Yes: by URL, content type, a specific page, category or tag, kind of page (home, shop, cart…), device and logged-in state.

= Can I limit what other administrators can do? =

Yes. The plugin defines its own capabilities (create, edit and delete chats; view, edit, delete and export leads; manage routing, e-mail, integrations, analytics and settings). Changing automations, e-mail recipients or webhooks needs `krivo_chatflow_manage_integrations`, because it decides where visitor data goes.

= What happens when I uninstall? =

Nothing is deleted when you deactivate. When you delete the plugin, your data is kept unless you turned on "Delete ALL plugin data when the plugin is deleted" in Settings.

= It does not send e-mails =

Open Settings > E-mail delivery and use "Send test". If your host cannot send mail, choose "My own SMTP server" and enter your provider's details. WordPress's WP-Cron must be working (or a real cron job must call wp-cron.php): e-mails and webhooks are sent from a background queue. The dashboard warns you when the queue is late.

= Does it work with Elementor, WooCommerce and page builders? =

The widget is independent of any builder and does not use hacks for any of them. It can be limited to WooCommerce pages (shop, cart, checkout, account) with the display rules.

== Screenshots ==

1. The chat bubble and the chat window on a site.
2. The editor with the live preview.
3. Steps mode with a progress bar.
4. Conversation mode.
5. Routing: destinations and rules.
6. Leads list with the lead drawer.
7. Integrations: webhook connections and the automation log.
8. Analytics: funnel and daily numbers.

== Changelog ==

= 0.1.0 =
* First release. Includes the "button only" mode and the Krivo visual identity (admin and chat window).

== Upgrade Notice ==

= 0.1.0 =
First release.

== External services ==

Krivo ChatFlow does not connect to any service on its own. There is no telemetry, licence check, update server, CDN or web font. The only times data leaves your site are the three below (plus an optional credit link), and each happens because you configured it or because the visitor clicked a link. The same information, in more detail, is in `docs/EXTERNAL-SERVICES.md`.

= WhatsApp (click-to-chat link, opened by the visitor) =

* **What it is used for**: continuing the conversation on WhatsApp after the visitor finishes the chat.
* **When it connects**: only if you turned on "Open WhatsApp after the form" for a chat and the visitor finishes it. The **visitor's browser** opens the link in a new tab; your server does not contact WhatsApp. No WhatsApp API is used.
* **What is sent**: the destination phone number you entered in Routing, and the pre-filled message text (which can include what the visitor typed). The visitor sees it in WhatsApp and decides whether to send it.
* **Service**: WhatsApp, operated by WhatsApp LLC (Meta Platforms) - https://www.whatsapp.com/
* **Terms of Service**: https://www.whatsapp.com/legal/terms-of-service
* **Privacy Policy**: https://www.whatsapp.com/legal/privacy-policy

= Webhook endpoints you configure (for example n8n, Make, Zapier or your own server) =

* **What it is used for**: sending each new lead to the automation tool or CRM you choose.
* **When it connects**: only after an administrator creates a connection in Integrations, confirms which data is sent, turns it on, and adds a "Send webhook" automation to a chat. With no connection, no webhook request is ever made. Requests are made by your server, from a background queue.
* **What is sent**: the lead (name, e-mail, phone, answers, destination chosen by routing, consent records), the page address and title, referrer, campaign (UTM) parameters, device type and time. Exactly the address you entered receives it; the plugin has no default destinations.
* **Services commonly used** (the plugin works with any HTTPS endpoint):
  * n8n - https://n8n.io/ - Terms: https://n8n.io/legal/#terms - Privacy: https://n8n.io/legal/#privacy
  * Make - https://www.make.com/ - Terms: https://www.make.com/en/terms-of-use - Privacy: https://www.make.com/en/privacy-notice
  * Zapier - https://zapier.com/ - Terms: https://zapier.com/terms - Privacy: https://zapier.com/privacy
  * Any other service or your own server: its own terms and privacy policy apply.

= Your own SMTP server (optional) =

* **What it is used for**: sending the e-mails of this plugin (lead notifications, confirmations, the test message) through the mail server you choose instead of WordPress's default.
* **When it connects**: only if you choose "My own SMTP server" in Settings > E-mail delivery and enter its address, and only while one of this plugin's e-mails is being sent. E-mail from WordPress or other plugins is not affected.
* **What is sent**: the e-mail itself (recipients, subject, body).
* **Service**: your own mail provider. Its terms and privacy policy apply. The password is stored encrypted, or can be defined in `wp-config.php`.

= "Powered by Krivo Works" credit (optional, off by default) =

* **What it is used for**: a small credit line in the chat window.
* **When it connects**: never on its own. If an administrator turns the line on, it is plain text. If, as a second and separate choice, they make it a link to https://krivo.works/, a request is made only if a visitor clicks it.
* **What is sent**: nothing by the plugin. A visitor who follows the link visits the Krivo Works website.
* **Service**: Krivo Works - https://krivo.works/

== Source code and building ==

The compiled files in `assets/` are built from the readable sources in the plugin's `src/` folder with esbuild (`npm install` then `npm run build`; Node 20 or newer). They are not obfuscated; they are only minified. `package.json`, the lock file and the build script (`bin/build.mjs`) are included, and the instructions are in `docs/BUILD.md`.

The development repository, with the same sources and the automated tests, is at https://github.com/mgabrielbrandao/krivo-chatflow

Third-party libraries bundled in the plugin: none. The admin app uses the copies of React, `wp-i18n` and `wp-api-fetch` that ship with WordPress.

Bundled font: **Albert Sans** (Copyright 2021 The Albert Sans Project Authors, https://github.com/usted/Albert-Sans), licensed under the SIL Open Font License 1.1. The two `.woff2` files and the licence text are in `assets/fonts/albert-sans/`. They are served from your own site, only on the plugin's admin screens; no font service is contacted.
