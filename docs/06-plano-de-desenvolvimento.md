# 06 · Plano de desenvolvimento

Cobre itens 39, 40 e 41.9–41.12 do prompt, mais “fluxo do visitante” e “fluxo do administrador” do bloco *Agora execute*.
Regra do plano: **segurança e conformidade entram no marco em que o código nasce**, não no fim.

---

## 1. Fases e escopo

| Fase | Objetivo | Conteúdo |
|------|----------|----------|
| **MVP — v0.9 (beta instalável)** | Lista do item 39 + o que não pode esperar por segurança/conformidade | Criação de ChatFlow · formulário **e** multi-etapas · 15 tipos de campo · widget flutuante · personalização (balão, tooltip, badge, painel) · WhatsApp (destinos, mensagem com variáveis, *preview*, vários destinos) · e-mail + SMTP + teste · leads (lista, busca, filtros, status, CSV, exclusão) · variáveis · regras básicas (mostrar/esconder, ir para, finalizar) · **roteamento básico** · **Automations** (6 triggers, 5 actions, fila, retentativas, logs) · **webhooks** (Connections, teste, presets) · tracking (UTM etc.) · regras de exibição · responsividade · **capabilities, antispam, privacidade (retenção, exportador/apagador, política de *uninstall*)** · hooks e eventos JS · acessibilidade · **coleta de métricas** (sem tela ainda) · assinatura opt-in (em Settings) · 5 templates · CI e `release-check` |
| **v1.0 — primeira submissão ao WordPress.org** | Produto completo e auditado | **Modo conversacional** · **horário de atendimento** · **Analytics** (funil e taxas) · **Dashboard** e painel de saúde · **assistente de onboarding** (inclui a autorização da assinatura) · **shortcode** (modo *inline*) · identidade visual completa · tradução PT-BR · matriz de conflitos · auditoria completa e documentos de conformidade |
| **Depois** | Evolução | Seção 6 |

Por que coletar métricas no MVP e só mostrar na v1.0: dados não podem ser reconstituídos depois. Por que **v1.0** e não o MVP no .org: a diretriz 16 pede plugin completo, e a autorização da assinatura precisa estar no onboarding.

---

## 2. Jornada do visitante

| # | Etapa | O que acontece | Se falhar |
|---|-------|----------------|-----------|
| 1 | **Chega à página** | O servidor decide (regras da página). Sem correspondência: **nada** é carregado. Com correspondência: CSS do balão, `loader.js` (*defer*) e balão fechado oculto | JS desligado → nada aparece (sem quebra) |
| 2 | **Balão aparece** | Em ociosidade, respeitando delay/scroll/dispositivo/sessão; tooltip e badge conforme regras; sem CLS | — |
| 3 | **Intenção** (hover/foco/toque) | Pré-carrega `panel.js`/`panel.css` e o manifesto (cacheável) | Falha de download → o clique tenta de novo e mostra erro amigável |
| 4 | **Abre o painel** | Foco no título; “Olá 👋 / Como podemos ajudar você?”; **online/offline** conforme horário; `POST /session` (token + métrica `open`); evento `krivo_chatflow_open` | REST bloqueada → *fallback* `admin-ajax` |
| 5 | **Responde** | Formulário, etapas ou conversa (uma pergunta por vez, com `{variáveis}`); validação inline acessível; lógica mostra/esconde e salta; eventos `start`/`step` | Perde conexão → respostas mantidas, botão “Tentar de novo” |
| 6 | **Finaliza** | Botão “Continuar pelo WhatsApp” **ou** “Enviar mensagem”. Se WhatsApp: abre `about:blank` **no clique** (anti-bloqueio do iOS); `POST /submissions` | 429 → “tente em alguns minutos”; token vencido → renova 1× em silêncio |
| 7 | **Servidor** | 12 etapas do pipeline (doc 02, §10): guarda → validação → roteamento → spam → **lead salvo** → triggers → *directives* → resposta (< 100 ms típico) | Falha interna → mensagem genérica, sem vazar detalhes |
| 8 | **Sucesso** | Mensagem com variáveis; WhatsApp abre com texto montado; `submit`, `whatsapp`, `complete` | Pop-up bloqueado → botão **“Abrir WhatsApp”** na tela de sucesso |
| 9 | **Depois** | E-mail, webhook e demais ações rodam **em segundo plano** (fila); o visitante nunca espera nem vê falha externa | n8n fora do ar → lead já salvo; 3 tentativas; se esgotar, `failed` visível ao admin |

## 3. Jornada do administrador

| # | Momento | Fluxo |
|---|---------|-------|
| 1 | **Primeira ativação** | Um redirecionamento → assistente em 5 passos (objetivo → número/e-mail → campos → aparência → publicar). Cartão da **assinatura** desmarcado, com o texto exato. “Ver no meu site” mostra o rascunho antes de publicar. Em minutos o widget está no ar |
| 2 | **Dia a dia** | Dashboard (leads novos, funil resumido, **saúde**) → Leads (abrir lead, mudar status, buscar/filtrar, exportar CSV) |
| 3 | **Ajustar o fluxo** | Editor com rascunho salvo automaticamente → prévia ao vivo → **pré-visualização no site** → Publicar → lembrete “limpe o cache do site” |
| 4 | **Automatizar** | Integrations: criar Connection (preset n8n/Make/Zapier/Custom) → **confirmar quais dados serão enviados** → Testar (status, tempo, resumo) → ligar em *Automations* do flow → acompanhar o log → “Tentar de novo” |
| 5 | **E-mail** | Settings › E-mail: usar o WordPress ou SMTP próprio → **E-mail de teste** (conectado/erro/resposta do servidor) |
| 6 | **Roteamento** | Routing: destinos (WhatsApp + e-mails) → regras por condição → ordem por arrastar; avisos de destino sem WhatsApp |
| 7 | **Privacidade** | Retenção, exportar/apagar, política no *uninstall*; texto de privacidade sugerido já registrado no WordPress |
| 8 | **Quando algo falha** | O painel de saúde aponta; o run mostra categoria do erro (timeout, DNS, SSL, 5xx…) sem segredos; corrige e usa “Tentar de novo” |

---

## 4. Plano por marcos

Tamanhos relativos (P / M / G). **Nenhum marco termina sem seus testes e sem o `release-check` aplicável** (doc 05, §6 e §8).

### M0 — Fundação e ferramentas (P–M)
- **Entregas**: cabeçalho do plugin (sem `Tested up to`), `Autoloader`, `Plugin::boot()`, `Installer`/`Migrator` (`dbDelta` v1 das 4 tabelas), capabilities, `uninstall.php` com política; Composer de desenvolvimento (PHPCS + WPCS + PHPCompatibilityWP, PHPStan, PHPUnit + `wp-phpunit`); npm (`@wordpress/scripts`, Jest, Playwright, ESLint); `bin/build-zip.php`, `bin/release-check.php` (regras iniciais), `bin/size-budget.mjs`, `bin/link-check.php`; `ci.yml` (matriz PHP × WP) e modelo de PR com os 14 itens; teste de arquitetura (`Core` não importa `Admin|Rest|Frontend`); `readme.txt` inicial (inglês), `SECURITY.md`, rascunhos de `EXTERNAL-SERVICES.md`, `HOOKS.md`, `EVENTS.md`.
- **Critério de saída**: ativar → desativar → reativar → desinstalar (com e sem remoção) em WP limpo, `WP_DEBUG` ligado, **sem notice/warning**; Plugin Check sem erros no esqueleto; CI verde; ZIP vazio passa na estrutura do `release-check`.

### M1 — Domínio (G)
- **Entregas**: Settings (schema único + `register_setting`), Secrets (libsodium) + `Redactor`; registry de campos e os 15 tipos (sanitização/validação); `Flow` (schema, validador, migrador, repositório rascunho/publicado, **gerador de manifesto**); **Conditions** e **Variables** em PHP e JS com **fixtures compartilhadas**; `Wildcard`; Routing (repositórios e resolvedor); `Whatsapp` (telefone, `UrlBuilder`); `LeadRepository`, status e exportador CSV seguro.
- **Critério de saída**: testes unitários com cobertura de linhas do `Core` ≥ 90% e **100% das linhas das tabelas de validação** (doc 02, §10); paridade PHP = JS nas fixtures; corpora XSS/SQLi sem falhas; nenhum arquivo de UI.

### M2 — Envio, antispam e automações (G)
- **Entregas**: REST pública (manifesto, sessão, eventos, envio) + *fallback* AJAX + `PublicGuard` (token, honeypot, tempo, rate limit, disjuntor, `Origin`); `Pipeline` (12 etapas); `Stats`; engine de Automations (registry, 6 triggers, 5 actions); fila (`runs`, worker, agenda, *backoff*, manutenção); `Mail` (SMTP escopado, diagnóstico, templates); Webhooks (Connections, payload, assinatura, `SafeClient`, testador); privacidade (exportador/apagador, retenção); hooks documentados; guarda de “rede zero” nos testes.
- **Critério de saída**: suíte de segurança (T1–T4, T7–T11, T15, T18) verde; cenários de webhook (200/204/500/429/timeout/302/JSON inválido/SSRF); SMTP contra sumidouro; **falha externa não altera a resposta ao visitante**; jornada por script (envio → lead → e-mail → webhook) sem notice/warning; **zero requisições a terceiros** com webhook/SMTP desligados.

### M3 — Widget do visitante (G)
- **Entregas**: `Gate`, `DisplayRules`, `Assets`, SSR do balão; `loader.js`; `panel.js` (formulário e etapas, 15 renderizadores, validação, lógica, envio, sucesso, *pre-open* do WhatsApp); tooltip, badge, animações, delay, scroll, abertura automática, regras de dispositivo; eventos + `dataLayer` opcional + `window.KrivoChatFlow`; acessibilidade; mobile; assinatura (se autorizada); CSS hostil de teste.
- **Critério de saída**: Jest + Playwright (jornada completa **só com teclado**), `axe-core` sem violações sérias, orçamentos de tamanho cumpridos, CLS = 0 atribuível, ΔTBT/ΔLCP dentro da meta (doc 04, §3.2), emulação de dispositivos.

### M4 — Admin base e editor (G)
- **Entregas**: casca do admin (menu, assets por tela, avisos), app React com *tokens* Krivo; ChatFlows (lista); REST de flows (rascunho, publicar, pausar, duplicar, preview-link, *dry-run*); editor com as 8 abas (campos com arrastar **e** teclado, contraste, *preview* de WhatsApp pelo PHP, Email, Automations por *schema*, Logic, Publish); 5 templates; salvamento automático com conflito 409.
- **Critério de saída**: E2E “criar → publicar → ver no site” em poucos minutos; matriz de capabilities do módulo de flows; bundle verificado (**sem React embutido**, só nas nossas telas); Plugin Check do admin sem erros.

### M5 — Leads, Routing, Integrations, Settings → **MVP v0.9** (M–G)
- **Entregas**: Leads (tabela, filtros, gaveta, status, exclusão em lote, **CSV seguro**); Routing; Integrations (Connections, confirmação, teste, logs, “Tentar de novo”); Settings (privacidade, branding, e-mail + teste, antispam, eventos).
- **Critério de saída**: E2E de cada tela; **teste de canário de segredos** em toda a UI; ferramentas de privacidade do WP (exportar/apagar por e-mail) E2E; exportação testada contra *formula injection*; ZIP v0.9 montado por `build-zip` e aprovado no `release-check` (itens específicos do .org marcados como pendentes).

### M6 — Recursos da v1.0 (M–G)
- **Entregas**: modo conversacional; horário de atendimento; Analytics (funil e taxas); Dashboard + saúde; **assistente de onboarding** (assinatura opt-in); shortcode (*inline*); política de avisos; identidade visual (símbolo, ícones, banner; verificada em 16/24/32/64/128/512 px e monocromática); tradução PT-BR; `HOOKS.md`/`EVENTS.md` finais.
- **Critério de saída**: E2E dos novos fluxos; roteiro manual com leitor de tela; verificação visual do símbolo nos seis tamanhos; fixtures de horário (inclui mudança de horário de verão).

### M7 — Endurecimento e submissão (M)
- **Entregas**: **releitura integral das diretrizes** (com rede liberada) → `WORDPRESS-ORG-COMPLIANCE.md`; Plugin Check completo com triagem (`PLUGIN-CHECK-TRIAGE.md`); **matriz de conflitos no CI** (WP 6.5 e atual; PHP 7.4/8.1/8.3/8.4; Elementor e Pro; WooCommerce; tema padrão atual; plugin SMTP popular; cache/minificação); roteiro manual em aparelhos reais; `link-check` com relatório recente; `readme.txt` final (inglês) com *External Services* (URLs verificadas), *Source code*, `Tested up to`; repositório público; `Contributors` validado; build reprodutível; `release-report.md`; ZIP final.
- **Critério de saída**: `release-check` totalmente verde; Plugin Check **zero erros**; todas as matrizes passam; documentos de auditoria entregues.

---

## 5. Riscos

| # | Risco | Prob. | Impacto | Mitigação |
|---|-------|-------|---------|-----------|
| K1 | Texto integral das diretrizes não relido (bloqueio de rede) | Média | Alto | Regras do Plugin Check já impostas desde o M0; releitura obrigatória no M7; matriz marcada como provisória |
| K2 | WP-Cron desativado/atrasado atrasa e-mail/webhook | Média | Médio | Lead sempre salvo antes; alerta de saúde; “Tentar de novo”; orientação de cron real |
| K3 | Plugins de segurança bloqueiam a REST a visitantes | Média | Alto | *Fallback* `admin-ajax`; E2E dos dois transportes |
| K4 | Cache de página/minificação quebram o widget | Média | Alto | Sem nonce no HTML; URLs versionadas; arquivos locais; `defer`; matriz de conflitos |
| K5 | iOS bloqueia a abertura do WhatsApp | Alta | Alto | *Pre-open* + botão de *fallback*; **teste manual em aparelho real** (o sandbox não tem WebKit) |
| K6 | Deriva do `@wordpress/components` entre versões do WP | Média | Médio | Uso mínimo, camada de componentes própria, E2E em WP 6.5 e atual |
| K7 | Plugin SMTP por API prevalece sobre o SMTP do ChatFlow | Média | Médio | Escopo estrito dos ganchos, diagnóstico do transporte efetivo, limitação documentada |
| K8 | Atrás de CDN, todos compartilham o IP → rate limit pune legítimos | Média | Médio | Opção de cabeçalho de proxy confiável, limites generosos, alerta de saúde |
| K9 | CSS do tema interfere no widget | Média | Médio | Escopo por ID, *reset*, suíte de CSS hostil; Shadow DOM só se os dados pedirem |
| K10 | Segredos ilegíveis após rotação de salts/migração | Baixa | Médio | UX “reinsira credencial”; sem fatal |
| K11 | Contas/URLs/ativos que só você tem (Contributors, URL da assinatura, símbolo Krivo, repositório público) | Alta | Alto | Lista de insumos na seção 7; `release-check` bloqueia sem eles |
| K12 | Expansão de escopo (Pro, integrações) | Alta | Alto | Faseamento; regra “sem consumidor, sem API”; Pro como plugin separado |
| K13 | Métricas de cliente (`view`, `start`) são inflacionáveis | Média | Baixo | Rotuladas como consultivas; `lead` e `whatsapp_click` são do servidor |
| K14 | Peculiaridades do `dbDelta` (índices, `utf8mb4`) | Média | Médio | Regras documentadas; testes em MariaDB aqui e MySQL no CI; piso MySQL 5.5.5 respeitado |
| K15 | PT-BR não carrega automaticamente antes da aprovação (core não lê `languages/` do plugin) | Alta | Médio | Decisão D-04; instalador de tradução no beta; translate.wordpress.org depois |
| K16 | Cálculo de horário (fuso, horário de verão) | Média | Médio | `Intl` com o fuso do site; fixtures de datas críticas; `in_hours` no servidor é autoritativo |
| K17 | Revisor estranha rota pública e *fallback* AJAX | Baixa | Médio | Justificativa documentada, *allowlist* explícita, guardas reais (não `__return_true`) |
| K18 | Desenho do logo sem o símbolo oficial fica genérico | Alta | Baixo | **Parcialmente mitigado**: conjunto provisório em `brand/` (reproduzível, com tamanhos ópticos); a troca pelo símbolo oficial (seção 7) é mecânica |
| K19 | Plugin Check avisa “script carregado em todos os contextos” quando o balão está publicado no site inteiro | Alta | Baixo | Aviso inerente a widget flutuante (lido no código do `Enqueued_Scripts_Scope_Check`); triagem justificada no M7, sem artifícios; nada carrega sem flow publicado |

## 6. Fora do MVP / backlog (e “preparado, não construído”)

| Item | Estado |
|------|--------|
| Upload de arquivos (`wp_handle_upload`) | Pós-1.0. Exige validação MIME/tamanho/permissão e superfície de abuso |
| Cálculos, máscaras, validações avançadas | Pós-1.0 |
| Lógica avançada (grupos aninhados, matemática) | **Schema pronto**; UI plana na 1.0 |
| Múltiplos atendentes, rodízio | Pós-1.0, via *resolver* de destino (filtro); Pro |
| Integrações nativas (RD, HubSpot, ActiveCampaign, Brevo, Sheets, Slack, Discord) | Pós-1.0: *Connection types + Actions* ou o webhook genérico |
| Presets adicionais | Só se melhorarem a UX |
| Bloco Gutenberg | Pós-1.0 (modo *inline* e `apiVersion ≥ 3` já previstos) |
| Intenção de saída | Pós-1.0 (gatilhos de comportamento extensíveis) |
| CAPTCHA | Opcional futuro, via filtro `krivo_chatflow_is_spam`; qualquer serviço externo seria opt-in documentado |
| Anotações/atividade do lead, CRM, atribuição | Pós-1.0, por migração (`lead_meta`/`lead_activity`) |
| WhatsApp Cloud API / Business | **Fora.** Seria serviço externo distinto, documentado à parte |
| Analytics avançado (UTM, tendências) | Pro |
| Shadow DOM | Reavaliar com dados da matriz de conflitos |
| **Pro** (`krivo-chatflow-pro`) | Plugin separado; contrato no doc 03, §10; **sem bloqueio artificial hoje** |

---

## 7. Decisões em aberto e insumos necessários

### 7.1 Decisões que preciso de você (a arquitetura só avança com “ok” ou ajuste)

1. **Aprovar o desenho**, em especial os desvios do prompt que considero justificados (doc 01, seção 2): tabela `flows` (doc 02, §8.1), `events` → `stats` (C7/C8), aba **Automations** (C15), **Salvar lead** como etapa fixa (C16), glifo próprio em vez do logotipo do WhatsApp (C18), documentos de conformidade em `docs/` (C3), *fallback* `admin-ajax` (D-14).
2. **Confirmar os padrões D-01 … D-15** (doc 01, seção 3). Os que mais mudam o produto: **PHP 7.4** como piso (D-01), **WP 6.5** (D-02), views por sessão (D-03), `dataLayer` desligado (D-05), redirecionamento único na ativação (D-08), variáveis em inglês + aliases PT-BR (D-10), modo conversacional e shortcode na **v1.0** (D-09).
3. **D-16 — idioma do repositório**: proponho **código, comentários e mensagens de commit em inglês** (convenção do ecossistema WordPress e exigência de leitura pelo revisor); **documentação interna em português**. Confirme.

### 7.2 Insumos que só você tem (nada disto foi inventado; o `release-check` bloqueia sem eles)

| Insumo | Para quê | Estado |
|--------|----------|--------|
| **SVG do símbolo/K oficial da Krivo** | Substituir o logo provisório | **logo provisório entregue em `brand/`**; símbolo oficial ainda pendente |
| **URL de destino do link “Krivo Works”** | Assinatura clicável | **informada: `https://krivo.works/`**; `krivo.works` é inacessível a partir do ambiente, então **não verificada** — o `link-check` do release confirma; sem isso a opção de link fica desabilitada |
| **Repositório público** (ou espelho) e seu URL | Fonte dos bundles, seção *Source code* do `readme.txt` | hoje **privado** |
| **Username WordPress.org** | `Contributors` | **confirmado por você: `gabrielbrandaoai`** (gravado em `bin/release.json` no M0). Não é verificável a partir do ambiente (`wordpress.org` bloqueado): o perfil precisa ser aberto no navegador antes de cada submissão; o `Contributors` distingue maiúsculas e minúsculas |
| **Liberar `developer.wordpress.org`, `wordpress.org`, `make.wordpress.org`** em *Network access* do ambiente | Releitura das diretrizes; baixar Elementor/WooCommerce/plugins de SMTP e cache para a matriz | sem isso, a matriz roda só no CI do GitHub |
| **Aparelhos reais** (iPhone/Safari, Android/Chrome, Samsung Internet) | Roteiro manual do M7 | seu lado |

### 7.3 Próximo passo

Com os itens 7.1 respondidos, começo o **M0** (fundação e ferramentas) e só avanço de marco quando os critérios de saída estiverem cumpridos e verificados — não por “a interface abrir”.
