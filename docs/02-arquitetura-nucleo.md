# 02 · Arquitetura do núcleo

Cobre itens 41.1–41.5 e 41.7–41.8 do prompt: camadas (Admin / Core / Frontend), estrutura de arquivos,
banco de dados, modelo de dados, motores e pipeline. Automations/webhooks/e-mail estão no `03`;
endpoints, widget e app admin no `04`.

---

## 1. Princípios (valem como critério de revisão de PR)

1. **WordPress-nativo.** APIs do core antes de código próprio (`wp_remote_*`, `wp_mail`, `dbDelta`, `WP_Cron`, REST, Settings API, privacy tools, media library).
2. **Uma responsabilidade, um motor.** Condições, variáveis, roteamento, automações e webhooks têm *uma* implementação cada; telas só configuram.
3. **O servidor é a fonte de verdade.** O navegador envia respostas; validação, visibilidade de campos, roteamento, antispam e montagem de mensagens são recalculados no PHP. Nada do que o cliente declara sobre o *schema* do fluxo é aceito.
4. **Público ≠ privado.** O que vai ao navegador é um **manifesto público** derivado do flow (sem e-mails internos, regras de roteamento, automações, segredos).
5. **Nenhuma requisição externa sem configuração + ciência do admin.** Visitar o site não gera tráfego para ninguém além do próprio site.
6. **Falha externa nunca vira falha do visitante.** Lead primeiro; o resto vai para a fila.
7. **Sem opção morta.** Toda opção na UI tem efeito testado; o que não funciona não aparece.
8. **Extensão por contrato.** Registries e hooks documentados e testados; classes internas marcadas `@internal`.
9. **Conformidade é teste.** Cada requisito do WordPress.org vira verificação automática (doc 05).

## 2. Visão geral

```
                         ┌───────────────────────── wp-admin (só telas do ChatFlow) ─────────────────────────┐
                         │  React (pacotes do core)  ──REST + nonce + capability──►  Rest\Admin\*            │
                         └───────────────────────────────────────────────────────────────────────────────────┘
 Visitante                                                                           │
   │  HTML (balão fechado, SSR)                                                      ▼
   ▼                                                                       ┌──────────────────┐
 loader.js ──intenção──► panel.js ──GET manifesto──► Rest\Public\Manifest  │   Core (domínio)  │
   │                        │   ──POST sessão (token)─► Rest\Public\Session │ Flow · Fields     │
   │                        │   ──POST eventos────────► Rest\Public\Events  │ Conditions        │
   │                        └──POST envio─────────────► Rest\Public\Submissions ─► Submission    │
   │                                                                        │ Pipeline         │
   └── abre wa.me (navegador do visitante) ◄── directive ◄──────────────────│ Leads · Stats    │
                                                                            │ Routing · Vars   │
                              ┌─────────────────────────────────────────────┴────────┬─────────┘
                              ▼                                                      ▼
                    Automation\Engine  ──sync──► diretivas (WhatsApp)       Infrastructure (Db, Http,
                              │                                             Crypto, Cron, Caps, Logging)
                              └──async──► fila `runs` ──WP-Cron──► Mail · Webhook (wp_safe_remote_request)
```

## 3. Camadas e regra de dependência

```
Admin ─┐
Rest  ─┼──► Core ──► Infrastructure ──► WordPress (wpdb, http, mail, cron, options)
Frontend┘     ▲
Automation ───┘   (Automation usa Core; Core dispara Automation apenas via interface `Dispatcher`)
```

- **Core** (domínio): flows, campos, condições, variáveis, roteamento, leads, pipeline, stats, WhatsApp URL, settings. **Não conhece** Admin, REST nem HTML.
- **Infrastructure**: adaptadores finos sobre o WordPress (repositórios `$wpdb`, cliente HTTP seguro, criptografia, cron, capabilities, redator de logs). É o que se *mocka* nos testes unitários.
- **Rest / Admin / Frontend**: bordas. Convertem requisição ↔ objetos do Core. Sem regra de negócio.
- **Automation**: triggers, actions, fila, webhooks, e-mail. Depende do Core; o Core só enxerga a interface `Dispatcher::dispatch( $trigger, $context )`.
- Regra verificada por teste de arquitetura (script que falha se `Core/` fizer `use` de `Admin|Rest|Frontend`).

## 4. Módulos

| Módulo | Responsabilidade | APIs do WordPress usadas |
|--------|------------------|--------------------------|
| `Core\Flow` | Modelo, validação e publicação (rascunho × publicado) do flow; templates iniciais | `$wpdb`, `wp_json_encode` |
| `Core\Fields` | Registry de tipos de campo (sanitização, validação, exportação) | `sanitize_*`, `is_email` |
| `Core\Conditions` | Avaliador único de condições (campo, contexto, operadores) | — |
| `Core\Variables` | Resolver e renderizador de `{variáveis}` por contexto de saída | `esc_html`, `wp_strip_all_tags` |
| `Core\Routing` | Destinos, regras e resolução de destino | options |
| `Core\Submission` | Pipeline de envio, validação, tracking, antispam | transients/object cache |
| `Core\Leads` | Persistência, status, CSV, exportador/apagador de privacidade | `$wpdb`, privacy hooks |
| `Core\Stats` | Contadores diários e funil | `$wpdb` |
| `Core\Whatsapp` | Normalização de telefone e construção de URL `wa.me` | — |
| `Core\Settings` | Schema único de configurações, branding (assinatura) | `register_setting` |
| `Automation\*` | Triggers, actions, fila, webhooks (doc 03) | WP-Cron, HTTP API |
| `Mail\*` | Envio com SMTP escopado, diagnóstico (doc 03) | `wp_mail`, `phpmailer_init` |
| `Infrastructure\*` | `Db`, `Http`, `Crypto`, `Cron`, `Caps`, `Logging`, `Support` | `dbDelta`, sodium |
| `Rest\*` | Controllers públicos e administrativos (doc 04) | `register_rest_route` |
| `Frontend\*` | Gate, regras de exibição, assets, SSR do balão, shortcode, preview | `wp_enqueue_*` |
| `Admin\*` | Menu, assets por tela, notices, onboarding, export | `add_menu_page`, `admin_enqueue_scripts` |

## 5. Estrutura de diretórios

```
krivo-chatflow/                         # raiz do repositório (em dev, também a pasta do plugin)
├── krivo-chatflow.php                  # cabeçalho, constantes, bootstrap (sem lógica)
├── uninstall.php                       # exige WP_UNINSTALL_PLUGIN; respeita a política de remoção
├── readme.txt                          # inglês; External Services; Source code / Build
├── LICENSE · README.md · CHANGELOG.md · SECURITY.md · CONTRIBUTING.md   # únicos .md na raiz
│
├── includes/                           # PHP, PSR-4: Krivo\ChatFlow\  →  includes/
│   ├── Autoloader.php                  # ~30 linhas; sem Composer em produção
│   ├── Plugin.php                      # raiz de composição (liga módulos por contexto)
│   ├── Core/   Flow/ Fields/ Conditions/ Variables/ Routing/ Leads/ Submission/ Stats/ Whatsapp/ Settings/
│   ├── Automation/  Triggers/ Actions/ Queue/ Webhook/
│   ├── Mail/
│   ├── Infrastructure/  Db/ Http/ Crypto/ Cron/ Caps/ Logging/ Support/
│   ├── Rest/  PublicApi/ AdminApi/ Guards/
│   ├── Frontend/
│   └── Admin/
├── templates/                          # views PHP (SSR do balão, e-mail HTML) — sem lógica de negócio
├── src/                                # FONTE legível dos bundles
│   ├── shared/        conditions.js, variables.js, wildcard.js      # paridade com o PHP (fixtures)
│   ├── frontend/      loader/  panel/  styles/
│   └── admin/         app/ screens/ components/ api/ styles/
├── build/                              # saída do webpack (gitignored; entra no ZIP)
├── languages/                          # krivo-chatflow.pot
├── tests/                              # php/{unit,integration,security} · js · e2e · fixtures
├── bin/                                # build-zip.php · release-check.php · size-budget.mjs · link-check.php
├── brand/                              # identidade visual (provisória): SVG, previews, gerador (fora do ZIP)
├── assets-wporg/                       # banner, ícones, screenshots (SVN /assets; fora do ZIP)
├── docs/                               # estes documentos (+ BUILD.md, EXTERNAL-SERVICES.md, WORDPRESS-ORG-COMPLIANCE.md no M7)
└── composer.json · package.json · package-lock.json · webpack.config.js · phpcs.xml.dist
    phpstan.neon.dist · phpunit.xml.dist · .gitignore    # dev: o ZIP é montado por allowlist (doc 05, §12); não há `.distignore`
```

**Autoloader próprio** (não Composer) em produção: sem `vendor/`, sem colisão de versões do `ClassLoader` entre plugins,
sem licenças de terceiros para auditar. Composer fica só para ferramentas de desenvolvimento.
Todo arquivo PHP começa com `defined( 'ABSPATH' ) || exit;` (verificação *direct file access* do Plugin Check).

## 6. Convenções de nomes (itens 93, 94)

| Item | Convenção |
|------|-----------|
| Namespace PHP | `Krivo\ChatFlow\…` (sem biblioteca “comum” entre plugins Krivo) |
| Constantes | `KRIVO_CHATFLOW_VERSION`, `KRIVO_CHATFLOW_FILE`, `KRIVO_CHATFLOW_API_VERSION` |
| Options / transients / user meta / cron / nonce actions / capabilities | prefixo `krivo_chatflow_` |
| Tabelas | `{$wpdb->prefix}krivo_chatflow_flows \| leads \| runs \| stats` |
| REST | `krivo-chatflow/v1` |
| Handles | `krivo-chatflow-frontend`, `krivo-chatflow-panel` (carregado sob demanda), `krivo-chatflow-admin` |
| Admin pages | `krivo-chatflow` (dashboard), `krivo-chatflow-flows`, `-leads`, `-routing`, `-integrations`, `-analytics`, `-settings` |
| DOM / CSS | raiz `#krivo-chatflow-root`; classes `kcf-*`; variáveis `--kcf-*` |
| JS global / eventos | `window.KrivoChatFlow`; eventos `krivo_chatflow_*` |
| `sessionStorage` | chaves `krivo_chatflow:*` |
| Text domain | `krivo-chatflow` (strings-fonte em inglês) |
| Hooks PHP | `krivo_chatflow_<verbo|substantivo>`; pares `before_`/`after_`; todos documentados (doc 03, seção 9) |

## 7. Boot e o que carrega em cada contexto

`krivo-chatflow.php` registra o autoloader e chama `Plugin::boot()`, que liga **apenas** os hooks do contexto atual.

| Contexto | Hooks ligados | Classes carregadas | Custo |
|----------|---------------|--------------------|-------|
| **Página do site** | `wp_enqueue_scripts` (Gate), `wp_footer` (SSR do balão), `init` (shortcode) | `Gate`, `DisplayRules`, `Assets`, `Renderer` (só se houver correspondência) | 0 consultas extras: lê a option autoloaded `krivo_chatflow_index` (id + regras de exibição + `rev`); sem correspondência → **nada é enfileirado nem impresso** |
| **wp-admin genérico** | `admin_menu`, `admin_notices` (só quem tem capability e não dispensou) | `Menu`, `Notices` | sem assets |
| **Tela do ChatFlow** | `admin_enqueue_scripts` com checagem do *hook suffix* | `Screens`, `Assets` | bundle admin só aqui |
| **REST** | `rest_api_init` registra rotas com *factories* preguiçosas | controller instanciado só quando a rota é chamada | — |
| **Cron** | `krivo_chatflow_process_queue`, `krivo_chatflow_maintenance` | `Worker`, `Maintenance` | — |
| **Ativação/desativação** | `register_activation_hook` (capabilities, flag de boas-vindas, agenda de manutenção) / `deactivate` (**só** remove agendas; nada de dados) | `Installer` | — |
| **Atualização** | `plugins_loaded`/`admin_init`: compara `krivo_chatflow_db_version` com a constante | `Migrator` com trava | 1 `get_option` |

Instalação do schema é **preguiçosa e por site** (`Migrator` em vez de depender só da ativação), o que cobre
multisite com ativação de rede e blogs criados depois.

---

## 8. Dados

### 8.1 Onde cada coisa mora (e por quê)

| Entidade | Armazenamento | Justificativa |
|----------|---------------|---------------|
| **Flow** (rascunho + publicado) | tabela `krivo_chatflow_flows` (2 colunas JSON em `longtext`) | CPT passaria `post_content` por `kses` para quem não tem `unfiltered_html` (corrompe JSON) e traria consultas/meta desnecessárias; `wp_options` não suporta bem documentos grandes nem concorrência. Tabela dá controle, rascunho/publicado e versionamento. |
| **Leads** | tabela `krivo_chatflow_leads` | Volume potencialmente alto, filtros e exportação (você preferiu tabelas; concordo). |
| **Automation runs** (fila + log) | tabela `krivo_chatflow_runs` | Fila e log são o mesmo registro: um `run` por (lead, trigger, action). |
| **Contadores** | tabela `krivo_chatflow_stats` (agregado por dia) | Substitui a tabela `events` sugerida: sem identificador de visitante, crescimento limitado. |
| **Destinos e regras de roteamento** | option `krivo_chatflow_routing` (sem autoload) | Poucos itens, edição rara; concorrência tratada com `revision` otimista. |
| **Connections (webhooks)** | option `krivo_chatflow_integrations` (sem autoload) | Idem. |
| **Segredos** | option `krivo_chatflow_secrets` (sem autoload), valores criptografados | Separados dos JSONs que são exportados/duplicados/listados. |
| **Configurações gerais** | option `krivo_chatflow_settings` (sem autoload) via `register_setting` com `sanitize_callback` | Exigência do Plugin Check. |
| **Índice de exibição** | option `krivo_chatflow_index` (**autoload**, minúscula: id, prioridade, regras de exibição, `rev`) | Evita consulta ao banco em cada página. |
| **Estado do onboarding / avisos dispensados** | option `krivo_chatflow_onboarding`; *user meta* `krivo_chatflow_dismissed` | — |
| **Antispam (contadores)** | transients / object cache (TTL curto) | Sem dados pessoais persistidos (IP só como HMAC truncado, só durante o TTL). |

Tabelas: **4** (`flows`, `leads`, `runs`, `stats`). Você sugeriu 3 (`leads`, `events`, `logs`): `events` virou `stats`, `logs` virou `runs` (fila + log), e `flows` foi adicionada.
Não há tabela “sem uso”: `lead_meta`/`lead_activity` entram quando o CRM/Pro precisar (o `Migrator` torna isso trivial).

### 8.2 Schema v1 (formato `dbDelta`)

```sql
CREATE TABLE {prefix}krivo_chatflow_flows (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',          -- draft | published | paused
  schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
  revision int(10) unsigned NOT NULL DEFAULT 0,         -- incrementa a cada publicação
  config_draft longtext NOT NULL,
  config_published longtext NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  published_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {charset_collate};

CREATE TABLE {prefix}krivo_chatflow_leads (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(36) NOT NULL,                          -- wp_generate_uuid4()
  flow_id bigint(20) unsigned NOT NULL,
  flow_revision int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'new',            -- new | contacted | converted | lost | spam
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  destination_key varchar(64) NOT NULL DEFAULT '',
  destination_label varchar(190) NOT NULL DEFAULT '',   -- snapshot (o destino pode ser renomeado/removido)
  channel varchar(20) NOT NULL DEFAULT 'message',       -- message | whatsapp
  source varchar(20) NOT NULL DEFAULT 'widget',         -- widget | shortcode
  in_hours tinyint(1) NULL,                             -- NULL quando não há horário configurado
  device varchar(10) NOT NULL DEFAULT '',
  answers longtext NOT NULL,                            -- JSON {key:{label,type,value}}
  consent longtext NULL,                                -- JSON [{key,accepted,text_hash,at}]
  page_url text NULL,
  page_title varchar(255) NOT NULL DEFAULT '',
  referrer text NULL,
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  utm_content varchar(191) NOT NULL DEFAULT '',
  utm_term varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY flow_created (flow_id,created_at),
  KEY status_created (status,created_at),
  KEY email (email(100)),                               -- exportador/apagador de privacidade do WP buscam por e-mail
  KEY created_at (created_at)                           -- retenção
) {charset_collate};

CREATE TABLE {prefix}krivo_chatflow_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  flow_id bigint(20) unsigned NOT NULL,
  lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
  trigger_key varchar(40) NOT NULL,                     -- lead.created, form.submitted, …
  action_id varchar(40) NOT NULL,                       -- id da instância dentro do flow
  action_type varchar(40) NOT NULL,                     -- send_email, send_webhook, …
  status varchar(20) NOT NULL DEFAULT 'pending',        -- pending | running | retrying | success | failed | skipped | cancelled
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
  next_attempt_at datetime NULL,
  locked_at datetime NULL,
  http_status smallint(5) unsigned NULL,
  error_code varchar(40) NOT NULL DEFAULT '',           -- categoria: timeout, dns, ssl, http_5xx, …
  error_message varchar(255) NOT NULL DEFAULT '',       -- sanitizada e truncada (sem corpo de resposta, sem segredos)
  duration_ms int(10) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  KEY queue (status,next_attempt_at),
  KEY lead_id (lead_id),
  KEY flow_created (flow_id,created_at),
  KEY created_at (created_at)
) {charset_collate};

CREATE TABLE {prefix}krivo_chatflow_stats (
  flow_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,                                    -- dia no fuso do site
  metric varchar(24) NOT NULL,                          -- view | open | start | lead | whatsapp_click
  total int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (flow_id,day,metric)
) {charset_collate};
```

Regras para o `dbDelta` (pegadinhas conhecidas): dois espaços após `PRIMARY KEY`; uma coluna por linha; sem vírgula final;
`KEY` com prefixo de índice onde há `utf8mb4`; sem `JSON` nativo (compatibilidade MySQL 5.5.5/MariaDB) — JSON em `longtext`;
datas em **UTC** (`current_time( 'mysql', true )`) e exibição com `wp_date()`.
Contadores: `INSERT … ON DUPLICATE KEY UPDATE total = total + 1` via `$wpdb->prepare()`.
`runs` não guarda payload: é **reconstruído do lead** na hora do envio (sem duplicar dado pessoal; apagar o lead apaga os `runs`).
O `Migrator` roda `dbDelta` completo + passos de dados numerados, sob trava (`add_option` com TTL), registra `krivo_chatflow_db_version`.

### 8.3 Settings (schema único; alimenta `register_setting`, validação REST e UI)

| Chave | Tipo | Padrão | Observação |
|-------|------|--------|-----------|
| `branding.signature_enabled` | bool | `false` | Opt-in explícito; guarda `authorized_by`, `authorized_at` |
| `branding.signature_link` | bool | `false` | Link é **segundo** consentimento |
| `privacy.retention_leads_days` | int ≥ 0 | `0` (indefinido) | Purga em lotes de 500 |
| `privacy.retention_logs_days` | int ≥ 1 | `30` | `runs` |
| `privacy.delete_on_uninstall` | bool | `false` | “Excluir todos os dados do Krivo ChatFlow ao desinstalar” |
| `tracking.capture_utm` / `capture_referrer` | bool | `true` | Minimização: pode desligar |
| `events.datalayer` | bool | `false` | Espelho em `dataLayer` (D-05) |
| `analytics.count_views` | bool | `true` | D-03 |
| `antispam.*` | int/enum | ver seção 11 | limites, tempo mínimo, cabeçalho de proxy confiável |
| `general.default_dial_code` | string | `55` se locale `pt_BR`, senão vazio | DDI padrão para telefones nacionais |

### 8.4 Modelo do flow (JSON `schema: 1`) — o que cada aba edita

```jsonc
{
  "schema": 1,
  "name": "Contato principal",
  "mode": "form",                       // form | steps | conversational   (mesmo modelo de passos)
  "steps": [ { "id": "s1", "title": "", "fields": [
    { "id": "f_a1", "type": "select", "key": "servico",           // `key` vira a variável {servico}
      "label": "Qual serviço você procura?", "prompt": "",         // `prompt` = fala no modo conversacional
      "description": "", "placeholder": "", "default": "", "required": true,
      "options": [ { "value": "dev", "label": "Desenvolvimento" } ] }
  ] } ],
  "logic":  { "rules": [ { "id": "r1",
      "when": { "match": "all", "conditions": [ { "left": "field:servico", "op": "eq", "right": "dev" } ] },
      "then": [ { "do": "show_field", "target": "f_b2" } ] } ] },  // show_field | hide_field | goto | finish
  "finish": { "button_label": "Continuar pelo WhatsApp",          // só apresentação; o comportamento vem de `automations`
              "success_message": "Recebemos sua mensagem, {nome}." },
  "whatsapp": { "message": "Olá! Meu nome é {nome}…", "default_destination": "dst_ab12" },
  "automations": [ { "id": "act_1", "trigger": "form.submitted", "type": "send_email", "enabled": true, "config": {} } ],
  "bubble":  { "icon": {"type":"chat"}, "shape": "circle", "position": "bottom-right", "offset_x": 24, "offset_y": 24, "size": 56,
               "colors": {"bg":"#7B2FF7","icon":"#FFFFFF","border":"","shadow":true}, "radius": null, "animation": "none",
               "tooltip": {"enabled":true,"text":"Precisa de ajuda?","delay":3,"duration":8,"dismissible":true,"once":true},
               "badge": {"enabled":false,"text":"1","delay":5} },
  "design":  { "title": "Olá 👋", "subtitle": "Como podemos ajudar você?", "avatar_attachment_id": 0,
               "theme": "auto", "colors": {"accent":"#7B2FF7"}, "strings": {"online":"Estamos online","offline":"No momento estamos offline, mas deixe sua mensagem."} },
  "hours":   { "enabled": false, "days": { "mon": [["09:00","18:00"]] } },      // fuso = fuso do site
  "behavior":{ "delay": 3, "scroll_percent": null, "auto_open": false, "auto_open_once_per_session": true },
  "display": { "priority": 10, "mode": "all", "include": [], "exclude": [ { "type": "url", "op": "contains", "value": "/checkout/" } ],
               "devices": ["desktop","tablet","mobile"], "logged": "any" }
}
```

Mapeamento com as abas: **Flow** = `mode`, `steps`, `finish` (o seletor “Ao finalizar” adiciona/remove a action gerenciada `open_whatsapp` em `automations` — **única fonte de verdade**) · **Design** = `design` · **Bubble** = `bubble` · **WhatsApp** = `whatsapp` ·
**Email** = visão amigável das actions `send_email` (inclui o autoresponder) · **Automations** = `automations` · **Logic** = `logic` + regras de
roteamento do flow · **Publish** = `display`, `behavior`, `hours`, status e publicação.

**Validação no servidor ao salvar/publicar** (`FlowValidator`): chaves de campo únicas, `sanitize_key`, não-reservadas (`id`, `flow_id`, `lead_id`, `event`, …);
tipos permitidos; operadores válidos por tipo; alvos de lógica existentes; ciclos de `goto` rejeitados; variáveis desconhecidas **avisadas** (não bloqueiam);
SVG sanitizado; HTML por `wp_kses` com *allowlist* mínima; limites (campos por flow, opções por campo, tamanho total do JSON).

### 8.5 Manifesto público (o que o navegador recebe)

Gerado na publicação a partir de `config_published` e entregue por `GET /public/flows/{id}`:
passos/campos (rótulos, opções, validações, defaults), `logic`, textos do `finish`, `design`, `strings` localizadas (impressas pelo PHP com `__()` — o frontend **não** tem pacote de i18n), `hours`,
`events`, `signature` (se autorizada), `rev`.
**Nunca** inclui: e-mails, regras de roteamento, `automations`, número do WhatsApp (o URL chega só na *directive* de resposta), segredos, destinos.

### 8.6 Retenção, desinstalação, atualização

- **Desativar** remove só agendas do cron. Nada de dados.
- **Desinstalar** (`uninstall.php`, protegido por `WP_UNINSTALL_PLUGIN`): se `privacy.delete_on_uninstall = true`, remove tabelas, options, transients, *user meta* e capabilities; senão, **não apaga nada**. (Multisite: itera os sites.)
- **Retenção**: `Maintenance` (cron horário) apaga `runs` antigos e, se configurado, leads antigos — em lotes e com índices (`created_at`).
- **Upgrades**: `Migrator` idempotente e versionado (`schema_version` do flow também migra: `FlowMigrator`).

---

## 9. Motores

### 9.1 Condições (`Core\Conditions` + `src/shared/conditions.js`)

Um único formato para **visibilidade de campo**, **saltos/finalização**, **roteamento** e (no servidor) **regras de exibição de página**:

```jsonc
{ "match": "all" | "any", "conditions": [ { "left": "field:servico", "op": "eq", "right": "dev" } ] }
```

- **Operandos (`left`)**: `field:{key}` · `ctx:page_url|page_path|page_title|referrer|utm_source|…|device|in_hours`.
- **Operadores**: `eq`, `neq`, `contains`, `not_contains`, `starts_with`, `ends_with`, `gt`, `lt`, `gte`, `lte`, `empty`, `not_empty`, `in`, `matches` (curinga `*`, **sem regex do usuário** → sem ReDoS).
- **Semântica**: números comparam numericamente quando ambos são numéricos (aceita `10.000,50`); strings comparam sem diferença de caixa (`mb_strtolower`); respostas multivaloradas (checkbox) só admitem `contains/not_contains/empty/not_empty`. A UI restringe operadores por tipo **e o servidor revalida ao salvar**.
- **Schema já admite grupos aninhados**; a UI do MVP é plana (lógica avançada = Pro depois, sem reescrever).
- **Paridade PHP ↔ JS**: `tests/fixtures/conditions.json` (casos de entrada/saída) é executado pelos dois testes. Mesmo fixture para o *wildcard* e para o renderizador de variáveis.
- **No envio, o servidor recalcula** a visibilidade com as respostas recebidas: campo escondido por lógica **não é obrigatório** e seu valor é **descartado**.

### 9.2 Variáveis e templates (`Core\Variables`)

`{token}` casa `/\{([a-z0-9_]+)\}/` — chaves com outro formato (ex.: JSON) nunca são tocadas. Token desconhecido vira **vazio** (e o editor avisa).

| Origem | Variáveis |
|--------|-----------|
| Campos | `{key}` de qualquer campo do flow |
| Papéis | `{name}`/`{nome}`, `{email}`, `{phone}`/`{telefone}` (primeiro campo de cada tipo) |
| Resumo | `{all_fields}`/`{respostas}` — lista “rótulo: valor” formatada para o contexto (tabela HTML no e-mail, linhas no WhatsApp) |
| Página | `{page_url}`/`{pagina}`, `{page_title}`/`{pagina_titulo}`, `{referrer}`, `{utm_source}`, `{utm_medium}`, `{utm_campaign}`, `{utm_content}`, `{utm_term}` |
| Sistema | `{site_name}`/`{site_nome}`, `{flow_name}`/`{fluxo}`, `{date}`/`{data}`, `{time}`/`{hora}`, `{lead_id}`, `{destination}`/`{destino}` |

**Escape por contexto de saída** (sempre no momento da saída): HTML de e-mail → `esc_html` + `nl2br`; texto/WhatsApp → texto puro, depois `rawurlencode` na URL;
assunto → remove CR/LF; **JSON de webhook** → o template é *decodificado como JSON primeiro* e a substituição acontece nas **folhas string**
(valor do visitante jamais quebra a estrutura).

### 9.3 Roteamento (`Core\Routing`)

- **Destino**: `{ id, name, whatsapp: {dial_code, number}, emails: [...], note }` (WhatsApp armazenado em dígitos E.164).
- **Regra**: `{ id, name, enabled, flows: [ids]|[], when: <condição>, destination }`, ordem = prioridade (arrastar para reordenar), **primeira correspondência vence**.
- **Ordem de resolução**: regras com escopo do flow → regras globais → destino padrão do flow → destino padrão do site → nenhum.
- Resultado: `{ destination, rule_id|null, reason }`, passa pelo filtro `krivo_chatflow_routing_result`. O trigger `routing.matched` só dispara quando uma **regra** casou.
- **Destino sem WhatsApp** (ex.: Financeiro só com e-mail) em flow com finalização “WhatsApp”: **não há directive**; o visitante vê a mensagem de sucesso. O editor e a tela de Routing avisam.
- Um destino é sempre **atômico** (número + e-mail). “Número diferente” e “e-mail diferente” = outro destino. (Rodízio entre atendentes = Pro, via filtro de resolução.)

### 9.4 URL do WhatsApp (`Core\Whatsapp\UrlBuilder`)

- Formato: `https://wa.me/{dígitos E.164}?text={rawurlencode(mensagem)}`. Número só com dígitos, sem `+` nem zeros à esquerda.
- **Limite**: a mensagem é truncada (com “…”) até o URL codificado caber em **4.000 caracteres**; a mensagem completa fica no lead.
- Passa por `krivo_chatflow_whatsapp_message` e `krivo_chatflow_whatsapp_url`; *action* `krivo_chatflow_before_whatsapp_redirect`.
- O cliente só abre URLs que começam com `https://wa.me/` (defesa em profundidade contra *directive* adulterada).
- O plugin **não** se conecta ao WhatsApp: é navegação do navegador do visitante, após clique explícito. Documentado em *External Services*.

---

## 10. Pipeline de envio (`Core\Submission\Pipeline`)

Um submit público percorre, **nesta ordem** (cada etapa pode interromper):

| # | Etapa | Detalhe |
|---|-------|---------|
| 1 | **Entrada** | Limite de tamanho do corpo (16 KB); JSON válido; só chaves conhecidas |
| 2 | **Flow** | Carrega o `config_published` (404 se não publicado; rascunho só em preview com capability + nonce) |
| 3 | **Guarda** | Token HMAC válido e dentro da janela, `Origin`, honeypot, tempo mínimo, rate limit, disjuntor do flow (seção 11) |
| 4 | **Validação** | Recalcula visibilidade; sanitiza/valida por **tipo de campo** (tabela abaixo); consentimentos obrigatórios |
| 5 | **Tracking** | Sanitiza URL/título/referrer/UTMs (URLs só `http(s)`, tamanhos máximos); respeita `tracking.capture_*` |
| 6 | **Duplicidade** | Mesma impressão digital (flow + e-mail/telefone + hash da mensagem) em 10 min → responde sucesso **sem** reexecutar automações |
| 7 | **Roteamento** | Resolve destino (9.3); `routing.matched` se houve regra |
| 8 | **Spam** | Heurísticas + filtro `krivo_chatflow_is_spam`; suspeito → `status = spam`, **sem automações** (quarentena visível) |
| 9 | **Persistência** | `LeadRepository::insert()` (`$wpdb->insert` com formatos); contador `lead` |
| 10 | **Triggers** | Dispara `lead.created`, `routing.matched` (se houver), `form.submitted`, `whatsapp.clicked` (se a intenção foi WhatsApp), `flow.completed` |
| 11 | **Actions síncronas** | Produzem *directives* (ex.: `open_whatsapp`); actions assíncronas viram linhas em `runs` |
| 12 | **Resposta** | `{ ok, lead: public_id, message, directives[] }`; depois `spawn_cron()` para a fila |

Meta de latência: etapas 1–12 < 100 ms típico (nenhuma I/O externa).

**Sanitização/validação por tipo (servidor)**

| Tipo | Sanitização | Validação |
|------|-------------|-----------|
| `name`, `text` | `sanitize_text_field` | tamanho (nome ≤ 120; texto ≤ 255, configurável até 500) |
| `textarea` | `sanitize_textarea_field` | ≤ 5.000; > 3 URLs marca suspeita |
| `email` | `sanitize_email` | `is_email`, ≤ 190, minúsculas |
| `phone`, `whatsapp` | só dígitos e `+` inicial | 8–15 dígitos; DDI padrão só se o campo definir `default_dial_code` |
| `number` | normaliza decimal (`.`/`,`) | `min`/`max` |
| `select`, `radio` | — | valor **deve estar** na lista de opções do servidor |
| `checkbox` (múltiplo) | — | subconjunto das opções |
| `date`, `time` | — | `Y-m-d` / `H:i` estritos; faixa opcional |
| `url` | `esc_url_raw` (só `http`/`https`) | ≤ 2.048 |
| `consent` | — | `true` obrigatório quando exigido; grava hash do texto exibido |
| `hidden` | valor **do servidor** (estático) ou do contexto sanitizado | origem declarada; valor do cliente ignorado quando estático |
| `html` | (exibição) | sem valor |

## 11. Antispam do endpoint público (item 91/92 — separado do nonce)

| Camada | Mecanismo | Observação |
|--------|-----------|-----------|
| 1 | **Token HMAC sem estado**: `base64url(payload).base64url(hmac)`; `payload = {flow, iat, rand, v}`; chave derivada de `wp_salt('auth')` via `hash_hkdf`; comparação com `hash_equals` | Emitido em `POST /public/session` ao abrir o painel; idade mínima (padrão 3 s; escala com nº de passos) e máxima (6 h). Não depende de nonce nem de HTML cacheado |
| 2 | **Honeypot** (campo fora da tela, `tabindex=-1`, `aria-hidden`) | Bot preenchendo → responde “sucesso” e descarta; não informa o robô |
| 3 | **Rate limit** por flow e por IP (HMAC truncado, sem armazenar o IP) | Padrão: 8 envios / 15 min / IP / flow; contadores em *object cache* ou *transients* |
| 4 | **Disjuntor do flow** | > 30 envios/min no flow → novos envios vão para `spam` e o admin vê aviso (protege e-mail e webhooks de inundação) |
| 5 | **`Origin`/`Referer`** | Se presente, o host precisa ser o do site |
| 6 | **Heurísticas de conteúdo** | Muitas URLs, texto repetido, campos idênticos; resultado = quarentena, não descarte |
| 7 | **Autoresponder protegido** | Máx. 1 por e-mail/hora, só para `is_email`, nunca em `spam`, e **não ecoa texto livre** no template padrão |
| 8 | **Ganchos** | `krivo_chatflow_is_spam` (Akismet/CAPTCHA como add-on futuro; qualquer serviço externo seria opt-in documentado) |

**IP**: usa `REMOTE_ADDR`; cabeçalho de proxy (`CF-Connecting-IP`, `X-Forwarded-For`) só se o admin escolher em Settings
(senão qualquer cliente forjaria o cabeçalho). Atrás de CDN sem essa opção, todos compartilham um IP: por isso o limite padrão é
generoso e o Dashboard avisa quando detecta muitos bloqueios do mesmo IP.
