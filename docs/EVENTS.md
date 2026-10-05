# Events

Three kinds of events exist: **browser events** (JavaScript, for analytics and tag managers),
**automation triggers** (PHP, what starts an automation) and the **webhook payload** (JSON sent to a connection).

None of them ever carries what the visitor typed in the browser events: they contain identifiers, counters and
field *keys*, never names, e-mails, phone numbers or answers.

---

## Browser events

The widget dispatches a `CustomEvent` on `document` for each step of the journey. The event name is
`krivo_chatflow_<name>` and the data is in `event.detail`.

```js
document.addEventListener( 'krivo_chatflow_complete', function ( event ) {
	console.log( event.detail.flowId, event.detail.channel );
} );

// Or through the small API (returns a function that removes the listener):
const stop = window.KrivoChatFlow.on( 'open', ( detail ) => console.log( detail ) );
```

| Event | When | `detail` |
|---|---|---|
| `krivo_chatflow_open` | The chat window opened | `flowId`, `rev`, `trigger` (`click`, `auto`, `api`) |
| `krivo_chatflow_close` | It closed | `flowId`, `rev` |
| `krivo_chatflow_start` | The visitor answered the first question | `flowId`, `rev` |
| `krivo_chatflow_step` | A new step or question was shown | `flowId`, `rev`, `step`, `stepCount`, `fieldKey` |
| `krivo_chatflow_submit` | The visitor sent the form | `flowId`, `rev`, `channel` (`whatsapp` or `message`) |
| `krivo_chatflow_whatsapp` | The browser was sent to WhatsApp | `flowId`, `rev` |
| `krivo_chatflow_complete` | The server accepted the lead | `flowId`, `rev`, `leadId` (an opaque UUID), `channel` |

### JavaScript API

```js
window.KrivoChatFlow.version       // string
window.KrivoChatFlow.open()        // open the chat window
window.KrivoChatFlow.close()
window.KrivoChatFlow.toggle()
window.KrivoChatFlow.on( name, fn ) // listen; returns a function that stops listening
```

Calling them on a page without a chat does nothing.

### Google Tag Manager / `dataLayer`

Off by default. When the site owner turns on *Settings → Publish chat events to the browser data layer*,
each event is also pushed as `{ event: 'krivo_chatflow_<name>', krivo_chatflow: <detail> }`
to `window.dataLayer` (if it exists). The plugin itself never loads Google Tag Manager or any other script.

---

## Automation triggers

An automation is "when **trigger**, do **action**". Triggers are evaluated in this order for each new lead.

| Trigger | Fires |
|---|---|
| `lead.created` | A lead was stored (not for spam). |
| `routing.matched` | A routing rule (not the default destination) decided the destination. |
| `form.submitted` | The submission was accepted. The usual choice. |
| `whatsapp.clicked` | The visitor is being sent to WhatsApp. |
| `flow.completed` | Everything above finished. |
| `lead.updated` | The status of an existing lead changed. Automations started by another automation do not trigger this one (no loops). |

Actions: `send_email`, `open_whatsapp`, `send_webhook`, `change_lead_status`, `route_lead`.
Synchronous actions (`open_whatsapp`, `change_lead_status`, `route_lead`) run in the visitor's request and may hand
the browser a *directive*; asynchronous ones (`send_email`, `send_webhook`) are queued and run in the background
(WP-Cron), with up to 3 attempts and a growing delay (1 min, 5 min, 30 min).

Plugins can add triggers and actions: see [HOOKS.md](HOOKS.md#extending-the-builder).

---

## Webhook payload

Sent as `POST`, `PUT` or `PATCH` (the connection decides) with `Content-Type: application/json`. A custom JSON
template can replace it; the template can use `{name}`, `{email}`, `{all_fields}`… and is rendered as data, so a
visitor cannot break out of the JSON.

```json
{
  "event": "form.submitted",
  "delivery_id": "rn_5f2c1c9a",
  "occurred_at": "2026-10-05T14:03:11Z",
  "site":  { "url": "https://example.com", "name": "Example" },
  "flow":  { "id": 12, "name": "Request a quote" },
  "lead": {
    "id": 301, "public_id": "9f0c…", "status": "new", "channel": "whatsapp",
    "name": "Maria Silva", "email": "maria@example.com", "phone": "+5511988887777",
    "destination": { "key": "dst_vip01", "label": "Big accounts" },
    "in_hours": true, "created_at": "2026-10-05T14:03:10Z"
  },
  "answers": { "name": "Maria Silva", "budget_range": "high" },
  "answers_labeled": [ { "key": "budget_range", "label": "Budget", "value": "high", "display": "Above R$ 5k" } ],
  "tracking": { "page_url": "…", "page_title": "…", "referrer": "…", "utm_source": "…", "device": "desktop" },
  "consent": [ { "key": "consent", "accepted": true, "at": "2026-10-05T14:03:10Z" } ]
}
```

For `lead.updated` a `changes` object is added. The "Send a test" button sends the same shape with obviously fake data
and `"test": true` at the top.

### Headers

| Header | Value |
|---|---|
| `X-KrivoChatFlow-Event` | The trigger, for example `form.submitted`. |
| `X-KrivoChatFlow-Delivery` | The same `delivery_id` on every retry of one delivery. Use it to ignore duplicates. |
| `X-KrivoChatFlow-Signature` | Only when signing is on: `t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`. |

### Verifying the signature (Node.js)

```js
const crypto = require( 'crypto' );

function verify( rawBody, header, secret, toleranceSeconds = 300 ) {
	const [ , t, v1 ] = /^t=(\d+),v1=([0-9a-f]{64})$/.exec( header ) || [];
	if ( ! t || Math.abs( Date.now() / 1000 - Number( t ) ) > toleranceSeconds ) return false;
	const expected = crypto.createHmac( 'sha256', secret ).update( t + '.' + rawBody ).digest( 'hex' );
	return crypto.timingSafeEqual( Buffer.from( expected ), Buffer.from( v1 ) );
}
```

### Delivery

A response with a `2xx` status is a success. `408`, `425`, `429` and `5xx`, timeouts and connection errors are retried
(honouring `Retry-After`, up to 3 attempts); any other `4xx`, and any redirect, is a permanent failure, shown in
*Integrations → Automation log*, where an administrator can try again. Redirects are never followed.
