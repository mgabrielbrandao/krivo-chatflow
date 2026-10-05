# Hooks reference

Krivo ChatFlow is extended with ordinary WordPress actions and filters. Every hook name starts with
`krivo_chatflow_`. Hooks never receive secrets: connection URLs, tokens, passwords and signing keys are
not available to any of them.

Lead, flow and run arrays are the plugin's own structures; their keys are listed where they matter.
Treat everything that comes from a visitor as untrusted input and escape it for the context you output it in.

Stability: the hooks below are the public API of version `1` (`KRIVO_CHATFLOW_API_VERSION`). A hook is
only removed or changed in a major release, and the change is announced in `readme.txt`.

---

## Life cycle

### `krivo_chatflow_loaded` (action)
Fires once the plugin finished loading its modules. Use it to register your own extensions.

---

## Submissions

### `krivo_chatflow_before_submit` (action)
Fires before a submission is processed (after the request was accepted by the REST route).

| Arg | Type | |
|---|---|---|
| `$payload` | array | The raw submission: `flow`, `token`, `answers`, `hp` (the hidden honeypot field), `context`. Not validated yet. |
| `$flow` | array | Published flow configuration. |

### `krivo_chatflow_submission_data` (filter)
Filters the validated submission before it is saved.

| Arg | Type | |
|---|---|---|
| `$validated` | array | `values`, `answers`, `errors`, `finished`, `in_hours`, `consent`, `ctx`. |
| `$flow` | array | Flow configuration. |
| `$tracking` | array | Page, referrer, UTM and device. |

Return the (possibly modified) `$validated`. Do not remove `errors`: a submission with errors is rejected.

### `krivo_chatflow_is_spam` (filter)
Decide whether a submission is held as spam. The lead is still stored (status `spam`), but no automation runs for it.

| Arg | Type | |
|---|---|---|
| `$is_spam` | bool | Verdict so far (token, honeypot, rate limit and circuit breaker already applied). |
| `$validated` | array | Validated submission. |
| `$tracking` | array | Tracking context. |
| `$flow` | array | Flow configuration. |

### `krivo_chatflow_lead_created` (action)
Fires when a lead was stored. It also fires for leads held as spam: check `$lead['status']`.

### `krivo_chatflow_after_submit` (action)
Fires after the submission was fully processed (automations queued, directives built).

| Arg | Type | |
|---|---|---|
| `$lead` | array | The lead row (see below). |
| `$response` | array | What the browser receives: `ok`, `lead`, `message`, `directives`, `duplicate`. |

### `krivo_chatflow_lead_updated` (action)
Fires after a lead's status changed (from the screen, in bulk, or by an automation). It is also the source of the `lead.updated` trigger.

| Arg | Type | |
|---|---|---|
| `$lead` | array | Updated lead. |
| `$changes` | array | `{ status: { from, to } }` |

**Lead array keys:** `id`, `public_id`, `flow_id`, `flow_revision`, `status` (`new`, `contacted`, `converted`, `lost`, `spam`),
`name`, `email`, `phone`, `destination_key`, `destination_label`, `channel` (`whatsapp` or `message`), `source`, `in_hours`,
`device`, `answers`, `consent`, `page_url`, `page_title`, `referrer`, `utm_*`, `created_at`, `updated_at`.

---

## Routing

### `krivo_chatflow_routing_result` (filter)
Change who receives a lead.

| Arg | Type | |
|---|---|---|
| `$result` | array | `destination` (id or empty), `rule_id`, `reason`. |
| `$flow_id` | int | |
| `$values` | array | Answers by field key. |
| `$ctx` | array | Page and visitor context (`device`, `page_path`, `utm_source`, `in_hours`…). |

---

## WhatsApp

### `krivo_chatflow_whatsapp_message` (filter)
The text that is pre-filled in WhatsApp. `($message, $lead, $flow)`

### `krivo_chatflow_whatsapp_url` (filter)
The final link. It must stay on `https://wa.me/`: anything else is refused and the visitor gets the
"could not open WhatsApp" message instead. `($url, $lead, $flow)`

### `krivo_chatflow_before_whatsapp_redirect` (action)
Fires right before the link is handed to the visitor's browser. `($url, $lead)`

---

## E-mail

### `krivo_chatflow_email_args` (filter)
Filters an e-mail before it is sent (notifications and confirmations).

| Arg | Type | |
|---|---|---|
| `$args` | array | `to` (array of addresses), `subject`, `html`, `text`, `options`. |
| `$lead` | array | |
| `$config` | array | Configuration of the automation that sends it. |

The plugin's own SMTP settings apply only while one of its e-mails is being sent; they never touch mail
sent by WordPress or other plugins.

---

## Webhooks and the queue

### `krivo_chatflow_webhook_payload` (filter)
Filters the JSON payload of a webhook. `($payload, $ctx, $event)`
The default payload is documented in [EVENTS.md](EVENTS.md#webhook-payload).

### `krivo_chatflow_webhook_request_args` (filter)
Filters the HTTP request right before it is sent: `$args` has `method`, `headers`, `body`, `timeout`.
`($args, $connection_id, $event)`. The address is not included, and the request is still sent through the
SSRF-safe client (private and reserved addresses are refused unless the connection allows them).

### `krivo_chatflow_before_webhook` (action)
`($summary)`: `connection`, `host`, `method`, `event`, `delivery_id`. Never contains the full URL.

### `krivo_chatflow_after_webhook` (action)
`($result, $summary)`. `$result`: `ok`, `status`, `category`, `retry`, `retry_after`, `duration_ms`, `summary`.

### `krivo_chatflow_max_attempts` (filter)
How many times an automation run is tried (1–5, default 3). `($attempts)`

### `krivo_chatflow_retry_backoff` (filter)
Seconds to wait before the next try (default 60, 300, 1800, 3600, 7200). `($seconds, $attempts_made)`

### `krivo_chatflow_run_failed` (action)
Fires when a run used all its attempts or failed permanently. `($run)` The run row never contains the payload or any secret.

### `krivo_chatflow_action_failed` (action)
Fires when a synchronous automation raised an exception. `($action_type, $message)`; the message is already redacted.

---

## Extending the builder

### `krivo_chatflow_register_field_types` (action)
Receives the field registry: `$registry->register( string $slug, array $definition )`.
Definition keys: `label`, `group`, `collects` (stores a value), `options`, `placeholder`, `length`, `range`
(`number`, `date` or `time`) and `normalize` (a callable). Types that you add are validated on the
server exactly like the built-in ones.

### `krivo_chatflow_register_triggers` (action)
Receives the automation registry: `$registry->register_trigger( string $key, string $label )`.

### `krivo_chatflow_register_actions` (action)
Receives the automation registry: `$registry->register_action( ActionInterface $action )`.
An action declares its `type()`, `label()`, `mode()` (`sync` runs in the visitor's request and may return a
browser directive; `async` runs from the queue), the triggers it allows, its defaults, a sanitiser for
its configuration, and `run()`.

---

## Display and front end

### `krivo_chatflow_should_render` (filter)
Last word on whether a chat's bubble is printed on the current page. `($render, $entry)`
`$entry`: `id`, `rev`, `priority`, `display`, `boot`.

### `krivo_chatflow_frontend_config` (filter)
The configuration block printed in the page (`<script type="application/json">`). Must contain nothing private.

### `krivo_chatflow_public_manifest` (filter)
The public description of a chat that the browser downloads when a visitor shows interest. Must contain nothing private.
`($manifest, $id, $config)`

### `krivo_chatflow_flow_published` (action)
Fires after a chat was published. Cache plugins can purge here. `($id, $config)`

---

## Anti-spam and networking

### `krivo_chatflow_rate_limits` (filter)
`array( max, window_seconds )` per visitor and chat. `($limits, $flow_id)`

### `krivo_chatflow_allowed_origins` (filter)
Hosts accepted as the `Origin` of public requests (the site's own hosts by default). `($hosts)`

### `krivo_chatflow_client_ip` (filter)
The visitor's IP address, used only for rate limiting (it is hashed and never stored in a lead).
`($ip, $remote_addr)`. Use it if your proxy setup is not covered by the setting in Settings → Spam protection.
