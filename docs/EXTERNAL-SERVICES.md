# External services

Krivo ChatFlow does **not** call any service run by Krivo Works or by any third party on its own.
There is no telemetry, no licence check, no update server, no CDN, no web font service (the one font used by the admin screens ships inside the plugin), no analytics and no tracking pixel.
Everything the plugin stores stays in your WordPress database.

The only times data leaves your site are listed here. Each one happens because *you* configured it or because the
*visitor* clicked something, and each is described in the plugin's `readme.txt` in the form WordPress.org asks for.

## 1. WhatsApp (`wa.me`), opened by the visitor

| | |
|---|---|
| What | A link `https://wa.me/<number>?text=<message>` |
| When | Only if you turned on *Open WhatsApp after the form* for a chat, and only after the visitor finishes it. |
| Who contacts the service | **The visitor's browser** opens the link in a new tab. Your server does not call WhatsApp. |
| Data in the link | The destination's WhatsApp number (it is a public contact number you entered) and the message text, which can contain what the visitor typed. The visitor sees the message in WhatsApp and decides whether to send it. |
| Service | WhatsApp, operated by WhatsApp LLC (Meta Platforms), <https://www.whatsapp.com/>. Terms: <https://www.whatsapp.com/legal/terms-of-service> · Privacy: <https://www.whatsapp.com/legal/privacy-policy> |
| No paid API | The WhatsApp Business API is not used. Nothing is sent without the visitor's own action in WhatsApp. |

The number and the message text are built on your server and handed to the browser; the visitor can see the link.
If the browser blocks the new tab, the visitor is shown the same link as a button.

## 2. Webhooks, only to the addresses you add

| | |
|---|---|
| What | An HTTPS request (`POST`, `PUT` or `PATCH`) with a JSON body describing the new lead. |
| When | Only when an administrator created a *connection* in *Integrations*, turned it on after confirming which data is sent, and added a *Send webhook* automation to a chat. |
| Where | Exactly the address entered, for example an n8n, Make or Zapier webhook, or your own server. The plugin has no list of default destinations. |
| Data sent | Name, e-mail, phone, all answers, the destination chosen by routing, consent records, page address and title, referrer, campaign (UTM) parameters, device type, time. See [EVENTS.md](EVENTS.md#webhook-payload). |
| Who runs it | Whoever owns the address. Their terms and privacy policy apply. n8n: <https://n8n.io/legal/#terms> and <https://n8n.io/legal/#privacy>; Make: <https://www.make.com/en/terms-of-use> and <https://www.make.com/en/privacy-notice>; Zapier: <https://zapier.com/terms> and <https://zapier.com/privacy>. |
| Safeguards | The address and any token are stored encrypted and are never shown again. Requests are made by the server (never by the visitor's browser) from a background queue, with a short timeout, without following redirects, and refusing private and reserved network addresses unless explicitly allowed for that connection. Payloads can be signed (HMAC-SHA256). |

If you do not create a connection, no webhook is ever sent.

## 3. Your SMTP server, only if you configure one

| | |
|---|---|
| What | An SMTP connection to the server you enter in *Settings → E-mail delivery → My own SMTP server*. |
| When | Only for e-mails sent by this plugin (lead notifications, confirmations and the test message). |
| Data sent | The e-mail itself: recipients, subject, body, sender. |
| Who runs it | Your mail provider. The password is stored encrypted, or can be defined as a constant in `wp-config.php`. |
| Scope | The SMTP settings apply only while one of this plugin's messages is being sent. E-mail sent by WordPress or other plugins is not touched. |

Without this setting, e-mail goes through WordPress's own mailer (`wp_mail()`), or whatever SMTP plugin you already use.

## 4. The optional "Powered by Krivo Works" credit

Off by default. When an administrator turns it on, a short line is printed inside the chat window.
It is plain text unless the administrator makes a **second, separate** choice to make it a link to
<https://krivo.works/>. A link is only ever followed if a visitor clicks it. Neither the line nor the link causes any
request from your site or from the visitor's browser until then. The date and the user who agreed are recorded in the settings.

## What is never used

* No Google Fonts, no CDN-hosted scripts or styles. Every file is served from the plugin folder.
* No Gravatar or other avatar services for visitors. (WordPress core shows your own administrators' avatars in its own screens; that is core behaviour.)
* No cookies are set by the widget. Session information such as the first campaign parameters is kept in the browser's
  `sessionStorage` for that tab only, and a dismissed tooltip is remembered in `localStorage`. Neither is sent anywhere
  except as part of the lead the visitor submits.
* No IP address is stored with a lead. For rate limiting, a keyed hash of the address is used as the name of a short-lived
  transient and discarded within minutes.

## For your privacy policy

The plugin adds suggested text to *Settings → Privacy → Policy Guide* that describes the data a chat collects and these
services. It also registers WordPress's personal-data **exporter** and **eraser**, so requests made under GDPR/LGPD
are answered from *Tools → Export Personal Data* and *Tools → Erase Personal Data*.
