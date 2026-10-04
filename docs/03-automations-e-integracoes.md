# 03 · Automations e integrações

Cobre itens 7, 10–12, 16–17, 46–57, 74, 86–87 do prompt. O ChatFlow é um **ponto de entrada de automações**:
um lead estruturado dispara *triggers*, que executam *actions* independentes. Webhook é cidadão de primeira
classe, mas **n8n, Make e Zapier são presets sobre uma única engine**, não três sistemas.

```
Pipeline de envio ──► Trigger ──► Engine ──► Actions
                                    │          ├─ síncronas (diretivas ao navegador, DB)   → resposta do submit
                                    │          └─ assíncronas → `runs` (fila) → WP-Cron → e-mail | webhook
                                    └─ filtra por flow + trigger + `enabled`
Webhook action ──► Connection (URL + auth + headers + assinatura) ──► preset: n8n | Make | Zapier | Custom
```

## 1. Modelo

- **Trigger**: evento nomeado emitido pelo núcleo (tabela 2).
- **Action**: unidade de trabalho com *schema* de configuração, modo `sync`/`async` e lista de triggers suportados.
- **Automation** (instância, dentro do flow): `{ id, trigger, type, enabled, config, managed? }`.
- **Connection** (global): endpoint + credenciais reutilizáveis por várias actions (hoje só `webhook`).
- **Run**: execução assíncrona de uma automation para um lead; é fila **e** log (tabela `runs`).
- **Zero requisição externa por padrão**: sem Connection habilitada e confirmada, **nenhuma** chamada de webhook ocorre; sem SMTP próprio configurado, nenhuma conexão SMTP.

## 2. Triggers (MVP)

| Chave | Rótulo na UI | Quando dispara | Notas |
|-------|--------------|----------------|-------|
| `lead.created` | Lead criado | Após persistir o lead | Também servirá a origens futuras (importação, API) |
| `form.submitted` | Formulário enviado | Envio aceito (não-spam) | **Gatilho padrão** da UI |
| `whatsapp.clicked` | Clique no WhatsApp | Envio com intenção WhatsApp e *directive* gerada | **1× por lead**; só o servidor dispara (nunca a partir de beacon do cliente → bot não aciona automações) |
| `flow.completed` | Fluxo concluído | Fim do fluxo (após as anteriores) | Cobre finalização por lógica (`finish`) |
| `lead.updated` | Lead atualizado | Mudança de status/dados (admin ou action) | `changes: {campo: {from, to}}`; **guarda de profundidade**: update gerado por automação não dispara novas automações (evita laço) |
| `routing.matched` | Regra de roteamento encontrada | Uma **regra** casou | Carrega `rule_id` e destino |

Ordem dentro de um envio: `lead.created` → `routing.matched` → `form.submitted` → `whatsapp.clicked` → `flow.completed`.
A UI mostra só **“Quando o formulário for enviado”** por padrão; os demais ficam em “Mais gatilhos”, com aviso quando
duas automations equivalentes (ex.: webhook em `lead.created` e `form.submitted`) causariam envio duplicado.

## 3. Actions (MVP)

| Tipo | Modo | Triggers | Config | Resultado |
|------|------|----------|--------|-----------|
| `send_email` | async | todos | `to[]` (`site_admin`, `destination`, `custom:<e-mail>`, `lead`), `subject`, `body`, `reply_to` (`lead`/`none`/e-mail) | `run` |
| `send_webhook` | async | todos | `connection_id`, `payload` (`default` \| `custom` + template JSON) | `run` |
| `open_whatsapp` | sync | `form.submitted`, `lead.created`, `routing.matched`, `flow.completed` | `message` (opcional; senão usa `whatsapp.message` do flow) | *directive* `{type:"open_whatsapp", url}` na resposta |
| `change_lead_status` | sync | todos | `status` | atualiza o lead; emite `lead.updated` (com guarda) |
| `route_lead` | sync | `lead.created`, `lead.updated` | — | reavalia o roteamento e atualiza o destino do lead |

- **Salvar lead não é action** (decisão C16): é a etapa 9 do pipeline, exibida na UI como “sempre ativo”.
- A action `open_whatsapp` **gerenciada** (`managed: true`) é criada/removida pelo seletor “Ao finalizar” da aba Flow; a aba Automations a mostra travada com a explicação. Única fonte de verdade: `automations[]`.
- **Autoresponder** = uma `send_email` com `to: ["lead"]` (a aba Email mostra como “Confirmação para o visitante”). `lead` é **exclusivo**: não pode coexistir com destinatários internos na mesma action (não expor e-mails da equipe ao visitante).
- Actions síncronas não geram `run` (sem I/O externa). Todo o resto passa pela fila.

## 4. Registries (extensão sem alterar o core)

```php
interface Action {
    public function type(): string;
    public function label(): string;
    public function mode(): string;            // 'sync' | 'async'
    public function triggers(): array;         // chaves de trigger suportadas
    public function schema(): array;           // schema declarativo da config (tipos, limites, `secret`, dicas de UI)
    public function sanitize( array $config ): array;
    public function run( Run_Context $ctx, array $config ): Result;
}
```

- `Registry::register_trigger()` / `register_action()` / `register_field_type()` — chamados em `do_action( 'krivo_chatflow_register_actions', $registry )`.
- O **schema** valida a config no servidor e gera formulários no admin (`SchemaForm`), então um add-on pode adicionar uma action **sem JavaScript**. As 5 actions do MVP usam o mesmo caminho (nada de código só para os nossos).
- Nada de dependência direta de plataformas: integrações futuras (RD Station, HubSpot, Sheets, Slack…) serão *Connection types + Actions* registrados, ou apenas o webhook genérico.

## 5. Fila, retentativas e logs

**Enfileirar** (no pipeline, após o lead existir): uma linha em `runs` por (lead, trigger, automation) com `status = pending`, depois:

```
if ( ! wp_next_scheduled( 'krivo_chatflow_process_queue' ) ) wp_schedule_single_event( time(), … );
spawn_cron();            // dispara o WP-Cron em segundo plano; o visitante não espera
```

**Worker** (`krivo_chatflow_process_queue`):
1. Trava (`add_option`/transient, TTL 60 s). 2. Orçamento de tempo (~20 s, respeita `max_execution_time`).
3. Seleciona até 10 runs com `status IN (pending, retrying)` e `next_attempt_at <= agora` (índice `queue`).
4. **Reivindica** cada run com `UPDATE … SET status='running', locked_at=…, attempts=attempts+1 WHERE id=%d AND status IN (…)` — só executa se `rows_affected = 1` (dois crons paralelos não duplicam envio).
5. Reconstrói o payload **a partir do lead + config atual** (nada de payload persistido).
6. Aplica o resultado; reagenda o próximo evento para `min(next_attempt_at)` ou `+5 s` se ainda há vencidos.

**Resultados e retentativa**

| Situação | Estado | Retenta? |
|----------|--------|----------|
| HTTP 2xx / `wp_mail` ok | `success` | — |
| `WP_Error` de timeout, DNS, conexão recusada | `retrying` → `failed` | **sim** |
| HTTP 408, 425, 429, 5xx (respeita `Retry-After` até 1 h) | `retrying` → `failed` | **sim** |
| HTTP 3xx (redirecionamento **não** é seguido) | `failed` (`redirect_not_followed`) | não |
| Outros 4xx, certificado SSL inválido, URL bloqueada pela política de segurança | `failed` | não (o admin corrige e usa “Tentar de novo”) |
| Connection/action desabilitada, destinatário ausente | `skipped` | não |

- **3 tentativas** (`max_attempts`, limitado a 1–5 por filtro), *backoff* **60 s → 5 min → 30 min** (`krivo_chatflow_retry_backoff`). Depois de esgotar: `failed`. Sem laços infinitos.
- **Resumo seguro por run**: `http_status`, `error_code` (categoria), `error_message` (curta, passada pelo `Redactor`), `duration_ms`, `attempts`. **Nunca** corpo de resposta, cabeçalhos, tokens, senha, URL completa.
- **Manutenção** (`krivo_chatflow_maintenance`, horária): reabre `running` travado há > 5 min; purga `runs` pela retenção (padrão 30 dias, em lotes de 500); reagenda fila vencida.
- **Saúde** (Dashboard): runs `failed` recentes, fila atrasada (> 10 min), `DISABLE_WP_CRON` ativo → orientação para cron real.
- A ação “Tentar de novo” (capability de integrações) recoloca o run em `pending` com tentativas zeradas.

## 6. Webhooks (módulo profissional, engine única)

### 6.1 Connection (global, em Integrations)

| Campo | Regra |
|-------|-------|
| `preset` | `custom` \| `n8n` \| `make` \| `zapier` — só **preenche padrões e dicas**; a execução é a mesma |
| `url` | **Tratada como segredo parcial** (URLs de n8n/Make/Zapier carregam token no caminho). Guardada no repositório de segredos; a UI e a REST mostram só host + trecho mascarado; editar = digitar de novo. Nunca em logs |
| `method` | `POST` (padrão), `PUT`, `PATCH` |
| `auth` | `none` \| `bearer` \| `basic` \| `header` (nome + valor secreto, ex.: `X-Webhook-Secret`) |
| `headers[]` | pares nome/valor; nome validado (token RFC 7230); **valor sem CR/LF**; valores marcados `secret` vão ao repositório de segredos |
| `signing` | opcional: HMAC-SHA256 do corpo com *secret* próprio |
| `enabled` + `acknowledged_at/by` | **só habilita após** o admin confirmar: *“Entendo que os dados dos leads serão enviados para esta URL”* (lista dos campos enviados exibida) |
| `timeout` | padrão 10 s (máx. 20) |
| avançado | `allow_http`, `allow_private_hosts` (opt-in explícito; D-12) |

### 6.2 Payload

- **`default`** (contrato estável, versionado):

```json
{
  "event": "form.submitted",
  "delivery_id": "<uuid do run>",
  "occurred_at": "2026-10-04T18:31:00Z",
  "site": { "url": "…", "name": "…" },
  "flow": { "id": 12, "name": "Contato principal" },
  "lead": { "id": 456, "public_id": "<uuid>", "status": "new", "channel": "whatsapp",
            "name": "…", "email": "…", "phone": "…", "destination": { "key": "dst_ab12", "label": "Comercial" },
            "created_at": "…" },
  "answers": { "nome": "…", "servico": "…" },
  "answers_labeled": [ { "key": "servico", "label": "Qual serviço você procura?", "value": "Site" } ],
  "tracking": { "page_url": "…", "page_title": "…", "referrer": "…", "utm_source": "…", "device": "mobile" },
  "consent": [ { "key": "lgpd", "accepted": true } ]
}
```

- **`custom`**: template **JSON** (com tokens `{nome}`, `{lead_id}`, `{flow_id}`, `{event}`…), validado ao salvar. O servidor *decodifica* o JSON e substitui tokens apenas nas **folhas string** — tipos literais (`"flow_id": 123`) são preservados e valor do visitante não quebra a estrutura. É assim que se “mapeia” `telefone → phone_number`; a UI oferece *chips* que inserem `"chave": "{token}"`.
- **Preview**: `POST /automations/preview` renderiza com um lead de exemplo (sem segredos, sem cabeçalhos).
- **Cabeçalhos enviados**: `Content-Type: application/json`, `X-KrivoChatFlow-Event`, `X-KrivoChatFlow-Delivery` (idempotência nas retentativas) e, com `signing`, `X-KrivoChatFlow-Signature: t=<unix>,v1=<hex(hmac_sha256(secret, t + "." + corpo))>` (anti-replay no receptor).

### 6.3 Execução (somente WordPress HTTP API)

- `wp_safe_remote_request()` (**nunca cURL direto**): bloqueia loopback/IPs privados e portas fora de 80/443/8080; `redirection => 0`; `sslverify => true` (sem opção de desligar); `timeout` da Connection; `blocking => true` (precisamos do status).
- `allow_private_hosts`/portas customizadas (n8n em `:5678`, rede interna) aplicam-se **só àquela requisição** (filtros registrados e removidos no mesmo escopo).
- Tratamento explícito de: `WP_Error`, timeout, status HTTP, JSON inválido na resposta (informativo), SSL, indisponibilidade, resposta inesperada → tabela da seção 5.
- *Hooks*: `krivo_chatflow_before_webhook`, `krivo_chatflow_after_webhook`, filtros `krivo_chatflow_webhook_payload` e `krivo_chatflow_webhook_request_args`.

### 6.4 “Testar Webhook”

`POST /integrations/test` (capability de integrações; limite 1 a cada 2 s). Envia `event: "webhook.test"`, `test: true` e um lead **fictício**.
Retorna **apenas**: `ok`, `http_status`, `duration_ms`, `category` (ex.: `timeout`, `dns`, `ssl`, `http_4xx`, `http_5xx`, `blocked`) e um `summary` curto traduzido.
Nunca devolve cabeçalhos, corpo da resposta, mensagem bruta do `WP_Error` nem detalhes do servidor.

### 6.5 Presets (só se melhoram a UX)

| Preset | O que muda | Observação |
|--------|-----------|-----------|
| n8n | rótulos (“URL do Webhook”), dica *Test URL × Production URL*, método POST/JSON, botão *Test Connection*, *Payload Preview* | Links de documentação **só entram após verificação** (item 72) |
| Make | idem, dica do módulo *Custom webhook* | idem |
| Zapier | idem, dica *Catch Hook* | idem |
| Custom | sem dicas | — |

## 7. E-mail e SMTP

### 7.1 Estratégia (itens 10, 87)

Dois modos, **sem alterar o e-mail do resto do site**:

| Modo | Comportamento |
|------|---------------|
| **Usar a configuração do WordPress** (padrão) | `wp_mail()` puro. Se o site já usa WP Mail SMTP/FluentSMTP/Post SMTP, o ChatFlow herda — zero conflito |
| **SMTP próprio do ChatFlow** | Servidor, porta, criptografia (`nenhuma`/`SSL`/`TLS`), usuário, senha, nome e e-mail do remetente, `Reply-To` |

**SMTP escopado** (`Mail\Mailer::send()`): imediatamente antes de chamar `wp_mail()` registra `phpmailer_init` (prioridade `PHP_INT_MAX`),
`wp_mail_from`, `wp_mail_from_name` e um ouvinte de `wp_mail_failed`; no `finally` remove tudo. Resultado: **só os e-mails do ChatFlow**
usam o SMTP próprio; o PHPMailer é o do core (**não embarcamos cópia**). A senha é lida no momento do envio (constante
`KRIVO_CHATFLOW_SMTP_PASSWORD` ou segredo criptografado) e não fica em propriedade de objeto.
Verificação de certificado **sempre ligada**. Limitação documentada: plugins SMTP que substituem o transporte por API podem prevalecer — o e-mail de teste mostra o transporte efetivo.

### 7.2 E-mail de teste (`POST /email/test`)

Envia ao usuário atual (ou a um endereço informado, validado). Captura a conversa SMTP com `SMTPDebug = 2` num *buffer* (via `Debugoutput`),
passa pelo `Redactor` (credenciais, linhas `AUTH`, base64), limita a 50 linhas/4 KB e devolve:
`{ ok, transport: 'smtp-chatflow' | 'wp-default' | 'overridden', host, port, encryption, server_response, error, duration_ms }`.
A UI mostra **Conectado** / **Erro** / **Resposta do servidor**, com mensagens em linguagem simples e o detalhe técnico recolhido.

### 7.3 Templates e entrega

- Assunto e corpo com variáveis (seção 9.2 do doc 02); HTML com *alt body* de texto; layout mínimo em `templates/email/`; **escape por contexto** (`esc_html` + `nl2br`); assunto sem CR/LF; `Reply-To` validado e sanitizado (sem `<>,"` nem CR/LF no nome).
- Padrão de **notificação interna**: `Reply-To = {email}` do lead; corpo padrão usa `{all_fields}` (funciona com **qualquer** flow).
- Destinatários: `site_admin` (e-mail do administrador do site), `destination` (e-mails do destino roteado), `custom:` (lista do admin, máx. 10, `is_email`), `lead`.
- Entrega **sempre via fila** (item 53): SMTP lento ou fora do ar não atrasa nem quebra o visitante.

## 8. Segredos (itens 50, 86)

| Aspecto | Decisão |
|---------|---------|
| O que é segredo | Senha SMTP, *secret*/token/Bearer/Basic/valores de cabeçalho marcados, **URL do webhook** |
| Onde | Option `krivo_chatflow_secrets` (**sem autoload**), separada dos JSON de flow/connection (que são exportados, duplicados, listados) |
| Como | `sodium_crypto_secretbox` (libsodium; o core fornece *polyfill*), chave derivada com `hash_hkdf` de `wp_salt('auth')` + `wp_salt('secure_auth')`; formato `k1:` + base64(nonce ‖ cifra) |
| Alternativa | Constante `KRIVO_CHATFLOW_SMTP_PASSWORD` no `wp-config.php` (nunca toca o banco) |
| Rotação de salts / migração de site | Segredo ilegível → a UI mostra “reinsira esta credencial”, sem erro fatal |
| API | Resposta REST traz só `is_set: true` e `updated_at`. **Omitido = manter; string = trocar; `clear: true` = remover** |
| Nunca | em HTML, JS, REST, logs, e-mails, mensagens de erro, *payload preview* |
| `Redactor` | substitui valores de segredos conhecidos e padrões (`Bearer …`, `Basic …`, `password=…`, query strings) por `[redacted]` antes de qualquer log/resposta |
| Duplicar/exportar flow | Segredos **não** acompanham; o admin reinsere |
| Honestidade | Criptografia em repouso protege vazamento de banco/backup; **não** protege contra execução de código no site. Declarado em `SECURITY.md` |

## 9. Hooks para desenvolvedores (inventário inicial; todos viram `docs/HOOKS.md`)

**Actions**

| Nome | Argumentos | Quando |
|------|-----------|--------|
| `krivo_chatflow_loaded` | `$plugin` | Plugin pronto (ponto de entrada do add-on Pro) |
| `krivo_chatflow_register_triggers` / `_actions` / `_field_types` | `$registry` | Registro de extensões |
| `krivo_chatflow_before_submit` | `$submission` | Antes do pipeline gravar |
| `krivo_chatflow_after_submit` | `$lead, $result` | Após o pipeline concluir |
| `krivo_chatflow_lead_created` | `$lead` | Lead persistido |
| `krivo_chatflow_lead_updated` | `$lead, $changes` | Status/dados alterados |
| `krivo_chatflow_routing_matched` | `$result, $lead` | Regra de roteamento casou |
| `krivo_chatflow_before_whatsapp_redirect` | `$url, $lead` | Antes de devolver a *directive* |
| `krivo_chatflow_before_webhook` / `_after_webhook` | `$request, $run` / `$summary, $run` | Em torno do envio |
| `krivo_chatflow_run_failed` | `$run` | Run esgotou tentativas |
| `krivo_chatflow_flow_published` | `$flow` | Publicação (integrações de limpeza de cache) |

**Filters**

| Nome | Valor filtrado |
|------|----------------|
| `krivo_chatflow_submission_data` | Dados validados do envio |
| `krivo_chatflow_is_spam` | `bool` de spam (+ contexto) |
| `krivo_chatflow_routing_result` / `krivo_chatflow_destination` | Resultado do roteamento / destino |
| `krivo_chatflow_whatsapp_message` / `krivo_chatflow_whatsapp_url` | Texto e URL do WhatsApp |
| `krivo_chatflow_webhook_payload` / `krivo_chatflow_webhook_request_args` | Payload e argumentos HTTP |
| `krivo_chatflow_email_args` / `krivo_chatflow_email_body` | Argumentos do `wp_mail` e corpo |
| `krivo_chatflow_flow_fields` | Campos do flow (no manifesto) |
| `krivo_chatflow_public_manifest` | Manifesto público |
| `krivo_chatflow_should_render` | `bool` de exibição do widget (+ flow) |
| `krivo_chatflow_variables` | Mapa de variáveis disponíveis |
| `krivo_chatflow_retry_backoff` / `krivo_chatflow_max_attempts` | Política da fila |
| `krivo_chatflow_rate_limits` / `krivo_chatflow_client_ip_header` | Antispam |

Regras: prefixo `krivo_chatflow_`; pares `before_`/`after_`; argumentos estáveis e documentados;
**teste de inventário** falha o CI se um `do_action`/`apply_filters` do código não estiver em `docs/HOOKS.md` (e vice-versa).

## 10. Contrato com o futuro Pro (sem bloqueios hoje)

- O Pro é **outro plugin** (`krivo-chatflow-pro`, `Requires Plugins: krivo-chatflow`), distribuído fora do .org. O free **não contém** checagem de licença, cota, flag “Pro”, menu de upsell nem código desativado (diretriz 5; `External_Admin_Menu_Links` do Plugin Check).
- **Contrato público** (estável, versionado por `KRIVO_CHATFLOW_API_VERSION`): registries e hooks da seção 9, *schema* JSON do flow (campo `schema`), *schema* do banco, rotas REST documentadas, formato do manifesto. Mudança incompatível ⇒ incrementa a versão da API.
- **Pontos de extensão já presentes**: novos triggers/actions/tipos de campo (PHP, com `SchemaForm` no admin sem JS); *resolvers* de destino (`single` no free; rodízio/atendentes no Pro via filtro); grupos aninhados no schema de condições; `lead_meta`/`lead_activity` por migração.
- **Fora do contrato** (`@internal`): repositórios, controllers, classes do admin. O JS admin de extensão (slots de tela) só será publicado quando a primeira feature Pro exigir — não construímos API sem consumidor.
- **Regra de ouro**: o que o free faz hoje continua no free. Pro *adiciona*; nunca remove nem limita.
