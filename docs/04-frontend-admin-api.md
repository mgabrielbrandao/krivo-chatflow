# 04 · Frontend, Admin e API

Cobre itens 3, 17–24, 25–28, 30, 35–38, 41.6/41.10, 58–61, 90, 95, 102–103 do prompt.

---

## 1. REST público (visitante) — `krivo-chatflow/v1/public/*`

Todas as rotas públicas definem `methods`, `callback`, **`permission_callback` real** (métodos de `Guards\PublicGuard`,
**não** `__return_true`), `args` com `sanitize_callback` e `validate_callback`. O **nonce não é a defesa** aqui (decisão C9);
a defesa é o conjunto de camadas do doc 02, seção 11.

| Método e rota | Guarda | Corpo | Resposta | Cache |
|---------------|--------|-------|----------|-------|
| `GET /public/flows/{id}?v={rev}` | flow **publicado** (senão 404; rascunho só em *preview* com capability + nonce); limite leve por IP | — | **Manifesto público** (doc 02, 8.5) | `Cache-Control: public, max-age=300, stale-while-revalidate=60` + `ETag` por `rev`. O core só envia `no-cache` a usuários logados (confirmado em `class-wp-rest-server.php`), então o cabeçalho é aplicado via `rest_post_dispatch` apenas nesta rota |
| `POST /public/session` | rate limit + `Origin` | `{flow}` | `{token, min_wait_ms}`; conta a métrica `open` | nenhum |
| `POST /public/events` | rate limit (60/min/IP) + *whitelist* de métricas | `{flow, events:["view"\|"start"]}` | `204` | nenhum |
| `POST /public/submissions` | **todas** as camadas antispam (token, honeypot, tempo, limites, `Origin`) | `{flow, token, answers{}, context{}, intent, hp}` | `{ok, lead, message, directives[]}` · `400` (erros por campo, traduzidos) · `403` (token) · `429` (`Retry-After`) · `503` (falha interna, mensagem genérica) | nenhum |

- **Métricas do funil**: `open` (sessão), `view` e `start` (eventos do cliente — são *consultivas*, podem ser infladas por robôs) · `lead` e `whatsapp_click` (**servidor**, autoritativas). O painel mostra a diferença de natureza.
- **Fallback `admin-ajax.php`** (D-14): ações `krivo_chatflow_session|events|submit` (`wp_ajax_nopriv_` e `wp_ajax_`) chamam **os mesmos serviços** e as mesmas guardas. O widget cai para ele só se a REST responder “bloqueada” (401/403 de segurança de terceiros, 404 de rota desativada).
- Nenhuma rota pública devolve segredo, e-mail interno, regra de roteamento ou dado de outro lead.

## 2. REST administrativo — `krivo-chatflow/v1/*`

Autenticação: cookie + `X-WP-Nonce` (`wp_rest`). Sem nonce válido o core trata a requisição como não autenticada e o
`permission_callback` falha — **nonce protege contra CSRF, a capability autoriza** (item 62). Toda rota verifica capability **e**
valida/sanitiza `args` pelo *schema*.

### 2.1 Capabilities (item 63)

13 capabilities, concedidas ao papel `administrator` na ativação (e reafirmadas pelo `Migrator`); removidas no *uninstall* com remoção de dados.
Permite a agências dar acesso só a “Leads” a um papel de cliente (editável por plugins de papéis).

| Capability | Permite |
|-----------|---------|
| `krivo_chatflow_create_flows` | Criar flow (inclui duplicar e usar template) |
| `krivo_chatflow_edit_flows` | Editar rascunho, **publicar/pausar**, preview |
| `krivo_chatflow_delete_flows` | Excluir flow |
| `krivo_chatflow_view_leads` | Listar e abrir leads |
| `krivo_chatflow_edit_leads` | Alterar status |
| `krivo_chatflow_delete_leads` | Excluir leads (inclusive em massa) |
| `krivo_chatflow_export_leads` | Exportar CSV |
| `krivo_chatflow_manage_routing` | Destinos e regras |
| `krivo_chatflow_manage_email` | SMTP e remetente (“Alterar SMTP”) |
| `krivo_chatflow_view_integrations` | Ver Connections (mascaradas) e logs de automação |
| `krivo_chatflow_manage_integrations` | Criar/editar Connections, **editar `automations` de flows**, testar, tentar de novo (“Alterar Webhooks”) |
| `krivo_chatflow_view_analytics` | Funil |
| `krivo_chatflow_manage_settings` | Privacidade, retenção, branding, antispam, eventos |

**Permissão por seção ao salvar um flow**: `PUT /flows/{id}` verifica a capability de **cada bloco alterado** —
`automations[]` exige `manage_integrations` (quem pode mexer em automações pode enviar leads para fora: é exfiltração em potencial),
os demais blocos exigem `edit_flows`. Isto é teste de segurança nomeado (doc 05).
Sempre `current_user_can()` no servidor; esconder botão na UI **não** é controle.

### 2.2 Rotas

| Rota | Métodos | Capability | Observações |
|------|---------|-----------|-------------|
| `/flows` | GET, POST | `edit_flows` (GET) · `create_flows` (POST) | POST aceita `template` |
| `/flows/{id}` | GET, PUT, DELETE | `edit_flows` · `edit_flows`* · `delete_flows` | PUT salva **rascunho** com `base_revision` (409 em conflito) e devolve relatório de validação |
| `/flows/{id}/publish` · `/pause` · `/duplicate` | POST | `edit_flows` · `edit_flows` · `create_flows` | publicar gera manifesto e atualiza o índice autoloaded; dispara `krivo_chatflow_flow_published` |
| `/flows/{id}/preview-link` | POST | `edit_flows` | URL com nonce para ver o **rascunho** no site (só administradores) |
| `/flows/{id}/dry-run` | POST | `edit_flows` | Simula o pipeline: mensagem do WhatsApp, e-mails e payloads com dados de exemplo; não grava |
| `/templates` | GET | `create_flows` | Templates iniciais |
| `/leads` | GET | `view_leads` | `search`, `status`, `flow`, `from`, `to`, `orderby` (*whitelist*), `page`, `per_page` ≤ 100 |
| `/leads/{id}` | GET, PATCH, DELETE | `view_leads` · `edit_leads` · `delete_leads` | PATCH só `status` |
| `/leads/bulk` | POST | `edit_leads` / `delete_leads` (por ação) | máx. 200 ids |
| `admin-post.php?action=krivo_chatflow_export_leads` | GET | `export_leads` + **nonce** (`check_admin_referer`) | CSV em *streaming*, lotes de 500, BOM UTF-8, delimitador `,` ou `;`, proteção contra *formula injection* |
| `/routing` | GET, PUT | `manage_routing` | destinos + regras; `revision` otimista |
| `/settings` | GET, PUT | `manage_settings` | schema único (doc 02, 8.3) |
| `/email` · `/email/test` | GET, PUT · POST | `manage_email` | senha nunca devolvida (`is_set`) |
| `/integrations/connections` (+ `/{id}`) | GET · POST, PUT, DELETE | `view_integrations` · `manage_integrations` | URL mascarada; habilitar exige `acknowledged` |
| `/integrations/test` | POST | `manage_integrations` | resumo seguro (doc 03, 6.4) |
| `/automations/preview` | POST | `manage_integrations` | payload com lead de exemplo |
| `/automations/runs` · `/runs/{id}/retry` | GET · POST | `view_integrations` · `manage_integrations` | logs sem segredos |
| `/analytics/summary` | GET | `view_analytics` | `from`, `to`, `flow` |
| `/health` | GET | qualquer capability do ChatFlow | falhas recentes, fila, cron, SMTP |
| `/onboarding` | GET, PUT | `edit_flows` | estado do assistente |

\* PUT de `automations` exige também `manage_integrations`.

---

## 3. Widget do visitante

### 3.1 Estratégia de carregamento

```
1. wp_enqueue_scripts (PHP)   Gate::should_render() — contexto + índice autoloaded + regras DA PÁGINA
                              sem correspondência → NADA é enfileirado nem impresso (0 bytes, 0 consultas extras)
2. correspondência            wp_enqueue_style  ('krivo-chatflow-frontend'  — bubble.css ≈ 2,5 KB gz)
                              wp_enqueue_script ('krivo-chatflow-frontend'  — loader.js ≈ 4 KB gz,
                                                 args: ['strategy' => 'defer', 'in_footer' => true])
                              wp_print_inline_script_tag( JSON, type="application/json" id="krivo-chatflow-config" )
                              wp_footer: balão FECHADO renderizado no servidor, com atributo `hidden`
3. loader.js (defer)          lê a config, aplica gates do CLIENTE (dispositivo, delay, scroll, sessão), revela o balão
                              (requestIdleCallback; delay 0 ainda espera ociosidade → não disputa LCP)
4. 1ª intenção                hover / focus / touchstart / pointerdown no balão  → baixa panel.js + panel.css (arquivos locais,
                              URLs versionadas vindas da config) e faz GET do manifesto (cacheável)
5. abertura                   POST /public/session (token + métrica `open`)
6. envio                      POST /public/submissions
```

- Config impressa com `wp_json_encode( …, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )` (impede escapar do `<script>`) e *data block* `application/json` — **não executável**, compatível com CSP; o `loader.js` faz `JSON.parse( el.textContent )`.
- **Cores do tema via CSSOM** (`element.style.setProperty('--kcf-…')`), não via atributo `style` nem tag `<style>`: funciona sob CSP restritiva. Sem fontes externas (pilha do sistema); sem CDN; sem jQuery; sem requisição a terceiros.
- **Abertura automática** carrega o painel em ociosidade após o delay (não na intenção).
- **Um balão por página**: se vários flows combinam com a URL, vence a maior prioridade (D-13).
- `Gate` ignora admin, feeds, *embeds*, REST/AJAX, *customizer preview*, pré-visualização do Elementor (via `class_exists( '\Elementor\Plugin' )`, sem acoplamento) e passa por `krivo_chatflow_should_render`. O widget **não depende** do Elementor.
- *Eventos de página em cache*: o HTML cacheado referencia `?v={rev}`; ao publicar, o admin recebe o lembrete **“limpe o cache do site”**; `krivo_chatflow_flow_published` permite add-ons de purga.

### 3.2 Orçamento de performance (verificado no release)

| Recurso | Orçamento (gzip) | Quando carrega |
|---------|------------------|----------------|
| `loader.js` | **≤ 4 KB** | Toda página com widget |
| `bubble.css` | **≤ 2,5 KB** | Idem |
| `panel.js` | **≤ 20 KB** (todos os tipos de campo, lógica, modo conversacional) | Só na primeira intenção/abertura automática |
| `panel.css` | **≤ 7 KB** | Idem |
| Requisições extras numa página | **+2 arquivos** (loader, bubble.css); **0 XHR**; 1 *beacon* `view` por sessão | — |

Critérios medidos (3 execuções, mediana, Chromium headless, com/sem plugin num tema de teste): **CLS = 0 atribuível**;
**nenhuma tarefa longa (> 50 ms)** originada do plugin; **ΔTBT ≤ 10 ms**; **ΔLCP dentro da margem de ruído**. O script `bin/size-budget.mjs`
falha o build se qualquer orçamento estourar. CLS é zero por construção: elemento `position: fixed`, dimensões declaradas no CSS, sem reflow do conteúdo.

### 3.3 Markup e estilo (Light DOM, sem Shadow DOM — decisão)

- **Light DOM** com raiz `#krivo-chatflow-root`, classes `kcf-*` e *reset* explícito dentro da raiz (especificidade por ID para vencer CSS de tema/Elementor). O Shadow DOM foi **rejeitado**: obrigaria a injetar CSS fora do `wp_enqueue_style`, atrapalha plugins de cache/minificação e o SSR. Reavaliar se a suíte de conflitos mostrar problemas reais.
- **Teste de CSS hostil** (fixtures com `button{…!important}`, `input{…}`, reset agressivo) faz parte da suíte do widget.
- Ícones (chat, mensagem, headset, “chat com telefone”): SVGs **desenhados para o projeto** (sem biblioteca de ícones → sem licença de terceiros; **nenhum** logotipo do WhatsApp). SVG do admin: colado e sanitizado por *allowlist* (`wp_kses`); sai no SSR com `wp_kses( $svg, $allowed )` (escape tardio).
- **Sem `innerHTML` com dados**: o JS monta DOM com `createElement`/`textContent`. Campo `html` do flow usa **DOM sanitizado por allowlist** (a, strong, em, br, p, ul, ol, li). Regra aplicada por ESLint (`no-unsanitized`).

### 3.4 Regras de exibição (item 22) — divisão servidor × cliente

| Regra | Onde decide | Por quê |
|-------|-------------|---------|
| Site inteiro / **exceto**; páginas, posts, tipos, categorias/taxonomias, ID, URL (`contains`, `starts_with`, `ends_with`, `equals`, curinga `*`) | **Servidor** (PHP, uma vez por requisição) | É propriedade da página; WooCommerce (`is_checkout()` etc.) só por `function_exists` |
| Logado / deslogado | **Servidor** | Usuários logados normalmente não recebem cache de página |
| **Dispositivo**, delay, % de scroll, uma-vez-por-sessão, horário de atendimento | **Cliente** | Servidor decidiria uma vez e o cache congelaria para todos |
| Exemplo do prompt: “todo o site **exceto** `/checkout/`” | `mode: all` + `exclude: [{url contains /checkout/}]` | — |

### 3.5 Comportamento, tooltip e badge (itens 19, 20, 23)

- **Delay**: 0/3/5/10 s (ou valor livre ≤ 60) **ou** % de scroll (IntersectionObserver/`scroll` passivo com *throttle*); intenção de saída fica para depois.
- **Abertura automática** opcional, **uma vez por sessão** (`sessionStorage`); “mostrar só a bolha” = sem auto-abrir.
- **Tooltip**: texto, delay, duração, botão fechar e “**mostrar uma única vez**” (`localStorage`, chave por flow+`rev`). **Badge**: texto curto (ex.: `1`) ou indicador, após X segundos; some ao abrir.
- **Armazenamento local** (somente do visitante, sem cookies, sem identificadores): `krivo_chatflow:*` em `sessionStorage` (sessão do funil, UTMs de primeiro toque, abertura automática) e `localStorage` (tooltip dispensado). Listados no texto de privacidade sugerido (seção 5 do doc 05).
- **Animações**: nenhuma · fade · pulse · bounce discreto; respeitam `prefers-reduced-motion`.

### 3.6 Painel, modos e fluxo de envio

- **Um modelo de passos, três apresentações**: `form` (1 passo), `steps` (n passos com progresso), `conversational` (um campo por vez, em balões; `prompt` com variáveis; sem “digitando…” falso). Mesmos validadores, mesma lógica; só muda o renderizador.
- Saltos (`goto`/`finish`) só aparecem na UI nos modos que os suportam.
- **Abrir o WhatsApp (item C11)**:
  1. No **clique** do botão final, `win = window.open('about:blank', '_blank')` (dentro do gesto do usuário).
  2. `POST /public/submissions`; a resposta traz `directives: [{type:'open_whatsapp', url}]`.
  3. Se o URL começa com `https://wa.me/`, `win.location.replace(url)`; **se `win` foi bloqueado** (ou em navegadores embutidos), a tela de sucesso mostra o botão **“Abrir WhatsApp”** e tenta `location.assign` ao toque.
  4. Texto auxiliar sob o botão: *“Você será redirecionado para o WhatsApp com sua mensagem preenchida.”* (transparência e LGPD).
  5. Se não houver *directive* (destino sem WhatsApp), mensagem de sucesso normal.
- **Horário de atendimento**: `Estamos online` / `No momento estamos offline, mas deixe sua mensagem.` (avaliado no cliente com o fuso do site; o servidor grava `in_hours` no lead). O fluxo **continua coletando** fora do horário.
- Erros: mensagens por campo, acessíveis; falha de rede → botão “Tentar de novo” sem perder as respostas.

### 3.7 Mobile (item 24)

- Painel como **folha inferior** (quase largura total), altura máx. `85dvh` (fallback `vh`), `env(safe-area-inset-*)`, rolagem interna; **sem travar a rolagem da página** (não bloqueia navegação); botão de fechar sempre visível.
- Campos com `font-size ≥ 16px` (evita *zoom* do iOS); alvos de toque ≥ 44 px; reposicionamento com `visualViewport` ao abrir o teclado.
- **Cobertura de teste**: emulação de dispositivo no Chromium (layout/toque) **e** lista de verificação manual em aparelho real (iOS Safari, Chrome Android, Samsung Internet) — o ambiente de desenvolvimento não tem WebKit/Samsung; isso é dito, não escondido.

### 3.8 Acessibilidade (item 103) — critérios de aceite

- Painel: `role="dialog"`, `aria-labelledby`, **não modal** (`aria-modal="false"`, sem *focus trap*, para não bloquear navegação); ao abrir, foco no título/primeiro campo; **Esc fecha**; ao fechar, **foco volta ao balão**.
- Balão: `<button>` com `aria-label`, `aria-expanded`, `aria-controls`; tooltip `role="status"` (não rouba foco); badge com texto alternativo.
- Modo conversacional: histórico em `role="log"` + `aria-live="polite"`; erros em região `aria-live`, `aria-invalid` + `aria-describedby`; rótulos associados a todos os campos.
- Foco visível, ordem de tabulação natural, contraste (≥ 4,5:1 texto, ≥ 3:1 gráficos), `prefers-reduced-motion`, tema `light | dark | auto`.
- **Aceite**: percurso completo só com teclado (Playwright), varredura `axe-core` sem violações sérias, e roteiro manual com leitor de tela (NVDA/VoiceOver) antes da v1.0.

### 3.9 Assinatura “Desenvolvido por Krivo Works” (itens 58, 59, 102) — opt-in sem *dark pattern*

- **Estado inicial: desligada.** Nada é renderizado sem autorização.
- **Dois controles independentes** (Settings → Branding e etapa de publicação do onboarding): (1) *Exibir “Desenvolvido por Krivo Works” no widget*; (2) *Tornar o texto um link para `<URL>`* (a URL aparece escrita ao lado). Ambos **desmarcados**, com o texto exato do que será mostrado e a nota *“Opcional. Você pode alterar isto a qualquer momento.”* Pular o passo mantém tudo desligado; sem insistência.
- Autorizado ⇒ aparece em **todos** os flows publicados do site, no **rodapé do painel aberto** (não no site, não no balão): `<footer class="kcf-credit"><p>Desenvolvido por <a href="…" rel="noopener noreferrer nofollow" target="_blank">Krivo Works</a></p></footer>` — **elemento real**, texto real, acessível; nunca por `::before/::after`; nunca link oculto/invisível/texto escondido.
- `nofollow` incluído porque é **branding, não construção de backlinks** (item 59).
- Estilo: pequeno, contraste ≥ 4,5:1, respeita tema claro/escuro, sem competir com o CTA.
- **Um único build** para o .org e comercial. Divergência futura de política = *flag* de empacotamento documentada, nunca checagem silenciosa em runtime.
- `authorized_by` / `authorized_at` ficam registrados (prova de consentimento). **A URL final é fornecida e verificada por você** antes do release; até lá o link fica desabilitado no código.

### 3.10 Eventos JavaScript e API (item 17)

Eventos `CustomEvent` em `document` (borbulham; `detail` **sem dados pessoais**):

| Evento | Quando | `detail` |
|--------|--------|----------|
| `krivo_chatflow_open` | Painel abriu | `{flowId, rev, trigger: 'click'\|'auto'}` |
| `krivo_chatflow_close` | Painel fechou | `{flowId, rev}` |
| `krivo_chatflow_start` | 1ª interação com um campo | `{flowId, rev}` |
| `krivo_chatflow_step` | Mudou de passo | `{flowId, rev, step, stepCount, fieldKey}` |
| `krivo_chatflow_submit` | Visitante enviou (antes da resposta) | `{flowId, rev, channel}` |
| `krivo_chatflow_whatsapp` | *Directive* de WhatsApp executada | `{flowId, rev}` |
| `krivo_chatflow_complete` | Servidor confirmou | `{flowId, rev, leadId, channel}` |

- **`dataLayer`** (GTM): espelho opcional, **desligado por padrão** (D-05): `dataLayer.push({event:'krivo_chatflow_open', krivo_chatflow:{…}})`. O plugin **não** carrega Meta Pixel/GA/GTM: quem captura é o site.
- `window.KrivoChatFlow = { version, open(), close(), toggle(), on( name, fn ) }`; vale também para abrir o painel de um botão/menu do tema.
- Todos documentados em `docs/EVENTS.md`; teste E2E confere nome, ordem e ausência de PII.

### 3.11 Shortcode e modo *inline* (item 35; v1.0)

`[krivo_chatflow id="123"]` renderiza o **mesmo painel** em `mode: 'inline'` (sem balão, sempre aberto), enfileirando assets **dentro do callback** (só nas páginas que o usam). Um flow pode ser “somente manual” (`display.mode = none`).
Bloco Gutenberg: **fora** do MVP; o `block.json` futuro precisa de `apiVersion ≥ 3` (verificação do Plugin Check) e reaproveita o modo *inline*.

---

## 4. App administrativo

### 4.1 Menu e navegação (itens 26, 82–84)

- **Menu de topo** *Krivo ChatFlow* (sete módulos justificam), `position = null` (fim da lista; **nada de posição agressiva**), ícone **SVG `data:` monocromático** (o Plugin Check avisa para ícones raster).
- Submenus (cada um com sua capability): **Dashboard · ChatFlows · Leads · Routing · Integrations · Analytics · Settings**. Quem só tem `view_leads` vê só *Leads*.
- **Sem links externos no menu, sem “Upgrade”, sem selos.** Sem CSS global no wp-admin; **não escondemos notices, Screen Options nem *update nags***: o contêiner do app vem *depois* de `<hr class="wp-header-end">`, então os avisos do WordPress aparecem acima, como sempre.
- Assets do admin **só nas nossas telas** (checagem do *hook suffix* em `admin_enqueue_scripts`).

### 4.2 Telas

| Tela | Conteúdo |
|------|----------|
| **Dashboard** | Últimos 7 dias (funil resumido), últimos leads, **saúde** (falhas de automação, fila atrasada, WP-Cron, SMTP não testado), checklist de primeiros passos |
| **ChatFlows** | Lista (status, última edição, leads), criar por template, duplicar, pausar/publicar |
| **Editor** | Abas **Flow · Design · Bubble · WhatsApp · Email · Automations · Logic · Publish** (seção 4.3) |
| **Leads** | Tabela (nome, e-mail, telefone, formulário, origem, destino, data, status, UTM, página), busca, filtros (status/flow/data), gaveta com todas as respostas e tracking, troca de status, exclusão (individual/lote), **exportar CSV** |
| **Routing** | Destinos (WhatsApp + e-mails) e regras (condição → destino, arrastar para reordenar), escopo por flow, avisos de destino sem WhatsApp |
| **Integrations** | Connections (presets n8n/Make/Zapier/Custom), confirmação “quais dados serão enviados”, **Testar**, **log de execuções** (status, HTTP, tentativas, “Tentar de novo”) |
| **Analytics** | Funil (visualizações → aberturas → inícios → leads → cliques no WhatsApp) com **taxas** (abertura = aberturas/visualizações; conclusão = leads/inícios; conversão = leads/visualizações), período 7/30/90 dias e filtro por flow — barras SVG simples, sem biblioteca de gráficos |
| **Settings** | Geral · Privacidade (retenção, exclusão no uninstall) · **Branding** (assinatura) · **E-mail** (WP × SMTP próprio + teste) · Antispam · Eventos (`dataLayer`) |

### 4.3 Editor de flow

- **Flow**: paleta de 15 tipos (nome, e-mail, telefone, WhatsApp, texto, textarea, número, select, radio, checkbox, data, hora, URL, consentimento, oculto, texto/HTML); adicionar, **duplicar**, excluir, **reordenar por arrastar** (implementação própria, **com botões subir/descer por teclado** — sem dependência de biblioteca de DnD), obrigatório, *placeholder*, descrição, valor padrão; modo (formulário / etapas / conversacional); “Ao finalizar”.
- **Design / Bubble**: ícone, forma (círculo, *squircle*, pílula, personalizada), posição, distâncias, tamanho, cores, borda, sombra, raio, animação, tooltip, badge — com **aviso de contraste** (WCAG).
- **WhatsApp**: destino padrão, mensagem com variáveis e **preview** (renderizado pelo PHP via `dry-run` — uma só implementação), modelos de mensagem, DDI.
- **Email**: assunto/corpo (com variáveis), destinatários, *autoresponder*, e-mail de teste.
- **Automations**: lista de triggers → actions (formulário gerado por *schema*), Connection escolhida, *payload preview*, teste.
- **Logic**: mostrar/esconder campo, ir para pergunta, finalizar; regras de roteamento deste flow (mesma engine/tela do Routing).
- **Publish**: onde (regras de exibição), quando (delay, scroll, abertura automática, horário), validação (lista de pendências), **pré-visualização no site** (rascunho), publicar/pausar.
- **Prévia ao vivo**: o próprio `panel.js` montado no admin em modo *preview* (o que se vê é o que roda). Rascunho **salvo automaticamente** (PUT com `base_revision`; 409 → “outra pessoa editou, recarregar”); alerta de alterações não salvas.

### 4.4 Design system Krivo (item 27)

| Token | Valor | Uso e contraste (calculado) |
|-------|-------|-----------------------------|
| `--kcf-primary-400` | `#A14DFF` | Destaques gráficos, ícones, ilustração, **texto grande** (≥ 24 px ou ≥ 18,66 px negrito). Branco sobre ele: **4,18:1** (≥ 3:1 gráfico ✔; **texto normal ✘**) |
| `--kcf-primary-600` | `#7B2FF7` | **Botões, links, foco, texto sobre fundo claro.** Branco sobre ele: **5,85:1** ✔ · sobre `#F7F4FC`: 5,37:1 ✔ |
| `--kcf-primary-800` | `#5521B5` | Estados *hover/pressed*, texto de ênfase (9,25:1) |
| `--kcf-text` | `#171519` | Texto (16,67:1 sobre `#F7F4FC`) |
| `--kcf-bg` | `#F7F4FC` | Fundo do app |
| `--kcf-surface` | `#FFFFFF` | Cartões e painéis |
| Neutros | derivados do texto: bordas `rgba(23,21,25,.08)` (decorativas); **texto secundário `rgba(23,21,25,.64)`** = 5,27:1 sobre `#F7F4FC` e 5,44:1 sobre branco (`.56` reprova: 4,05:1 — não usar) | — |

- Tipografia: **pilha do sistema** (nenhuma fonte externa nem empacotada — evita rever licença e atende itens 30/81); escala modular; bastante espaço em branco; raio 6/10/14 px; sombras muito sutis; **sem gradientes** além de, no máximo, um realce discreto; microinterações de 120–180 ms com `prefers-reduced-motion`.
- Escopo: tudo sob `.kcf-admin`; **nenhum seletor global**; componentes do `@wordpress/components` usados onde ajudam a acessibilidade (botões, modais, popovers), com tema por variáveis.
- A paleta não é “genérica de IA”: editorial, claro, denso em informação e calmo — referência conceitual a produtos SaaS profissionais, sem copiar nenhum.

### 4.5 Onboarding (item 38) e avisos (item 84)

- **Primeira ativação**: um redirecionamento (D-08) apenas para quem tem capability, nunca em ativação em lote, rede, AJAX ou WP-CLI. *Fallback*: aviso dispensável.
- Assistente em 5 passos: **1 Objetivo** (WhatsApp · Capturar contatos · Suporte · Orçamento → escolhe o template) → **2 Número/e-mail** (validados) → **3 Campos** (liga/desliga/reordena) → **4 Aparência** (cor, ícone, posição, tooltip, com prévia) → **5 Publicar** (inclui o cartão de **assinatura opt-in**, desmarcado, e o botão *Ver no meu site*). Meta: primeiro widget no ar em minutos.
- **Avisos**: só para quem tem capability; dispensáveis (persistidos em *user meta*); sem promoção agressiva; nunca em tela alheia (apenas Plugins e telas do ChatFlow); sem repetição infinita.

### 4.6 Templates iniciais (item 37)

Definidos em PHP (`Core\Flow\Templates`), **traduzíveis**, com chaves de campo geradas a partir do rótulo traduzido na criação (site em português → `{nome}`, em inglês → `{name}`).

| Template | Campos |
|----------|--------|
| Contato simples | Nome · WhatsApp · Mensagem |
| Solicitar orçamento | Nome · Empresa · Serviço · Orçamento · Mensagem |
| Suporte | Nome · E-mail · Produto · Problema |
| Clínica | Nome · Telefone · Tipo de atendimento · Mensagem |
| Imobiliária | Nome · Telefone · Tipo de imóvel · Faixa de valor |

Cada template já traz uma automation `send_email` (notificação interna com `{all_fields}`), o texto de WhatsApp e consentimento opcional.

### 4.7 Build do admin

`@wordpress/scripts` (webpack) com **extração de dependências**: `react`/`react-dom`/`@wordpress/*` vêm do core (sem React empacotado — o Plugin Check trata React embutido como **erro**). Entrada única com *code splitting* para o editor.
`build/admin/index.asset.php` fornece `dependencies` e `version`; traduções via `wp_set_script_translations()` (JSON gerado por `wp i18n make-json`). Estado com React (`useReducer`/contexto), sem Redux.
Integração com `@wordpress/components` sujeita à deriva entre versões do WP: a suíte E2E roda no **WP mínimo (6.5)** e no **mais recente**.

### 4.8 Identidade visual do ChatFlow (item 28)

- **Conceito**: balão de conversa minimalista cuja estrutura é formada pelo **espaço negativo entre duas formas da linguagem Krivo** (ou pelo próprio **K**), com a “cauda” do balão integrada à perna do K. Geometria em grade de 24 px, traço mínimo de 2 px para sobreviver em **16 px**, sem gradiente e sem dependência de cor.
- **Entregáveis**: símbolo SVG (cor + monocromático + negativo), ícone de menu do admin (SVG `data:` mono), ícone do plugin (128/256 para o .org), banner. Verificação **renderizada** em 16/24/32/64/128/512 px (Chromium headless) e em modo monocromático, com critério de aceite visual.
- **Dependência sua**: preciso do **símbolo/K oficial da Krivo (SVG)** para derivar o desenho com fidelidade; sem isso, o conceito fica genérico. Não será usado nenhum elemento do logotipo do WhatsApp.
