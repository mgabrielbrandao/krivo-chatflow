# 01 · Revisão técnica do prompt

Escopo: analisar os 107 itens do prompt (incluindo o *Reforço obrigatório*), apontar conflitos,
ambiguidades e riscos, registrar o que foi decidido e por quê. Nada aqui é implementação.

---

## 1. O que foi verificado — e o que não foi

O prompt exige consultar a documentação oficial *atual* em vez de confiar na memória. Resultado honesto:

### 1.1 Verificado em fonte primária (lido nesta sessão)

| Fato | Fonte | Uso |
|------|-------|-----|
| WordPress atual no espelho oficial: **7.1.2**. Mínimos do core: **PHP 7.4**, **MySQL 5.5.5** | `wp-includes/version.php` (tag `7.1.2` de `WordPress/WordPress`) | Piso de compatibilidade |
| `wp_register_script()` aceita `strategy` (`defer`/`async`), `in_footer`, `fetchpriority` | `wp-includes/functions.wp-scripts.php` | Carregamento do widget |
| Cabeçalho `Requires Plugins` existe desde 6.5 | `wp-admin/includes/plugin.php` | Pro como add-on |
| Tradução “just-in-time” só procura `WP_LANG_DIR/plugins`, `WP_LANG_DIR/themes` e o caminho registrado por `load_plugin_textdomain()`; **não** lê `languages/` dentro do plugin sozinha | `wp-includes/l10n.php`, `class-wp-textdomain-registry.php` | Estratégia de i18n (decisão D-04) |
| **Plugin Check 2.1.0** (commit de 2026-10-02): 36 verificações registradas e as regras listadas em `05`, seção 3 | `WordPress/plugin-check` (`includes/Checker/Checks/**`) | Checklist de release, estrutura do ZIP |

### 1.2 Não verificado (bloqueado pelo proxy de saída do ambiente)

| Alvo | Estado | Consequência |
|------|--------|--------------|
| `developer.wordpress.org` (Detailed Plugin Guidelines, Handbook, FAQ, Common Issues) | **bloqueado** | Texto integral das 18 diretrizes **não relido**. Só os *títulos* foram confirmados via busca web. |
| `wordpress.org`, `make.wordpress.org` (checklist do revisor, perfil de contributor) | **bloqueado** | `Contributors: gabrielbrandaoai` **não pôde ser validado**. |
| `krivo.works`, domínios de termos/privacidade do WhatsApp | **inacessíveis** | Nenhuma URL legal/assinatura foi verificada. **Nenhuma URL será escrita no `readme.txt` sem verificação** (item 72 do prompt). |
| Download de plugins/temas de terceiros (Elementor, WooCommerce, SMTP, cache) | **inacessível** (WPackagist e wordpress.org) | Matriz de conflitos (item 100) precisa rodar no CI do GitHub ou na sua máquina. |

**Ações:** (a) se quiser que eu releia as diretrizes, libere `developer.wordpress.org`, `wordpress.org` e
`make.wordpress.org` em *Network access* do ambiente; (b) independentemente disso, o marco **M7** inclui
uma releitura completa das diretrizes e a geração de `WORDPRESS-ORG-COMPLIANCE.md` — a matriz do doc 05 é
**provisória** até lá.

> Uma das buscas devolveu data errada para o Plugin Check (“agosto de 2024”); descartei e usei o commit
> do repositório. Resumos de busca/IA não são fonte de verdade neste projeto.

### 1.3 O que dá para testar neste ambiente

PHP 8.3 (com `mysqli`, `sodium`, `intl`, `mbstring`), Node 22, Composer 2.8, Chromium (Playwright),
MariaDB instalável via `apt`, WordPress via espelho Git, `wp-phpunit` e `@wordpress/scripts` via
Packagist/npm. **Não dá:** Safari/iOS real, Samsung Internet, provedores SMTP reais, plugins de terceiros.

---

## 2. Conflitos e tensões entre requisitos

Ordenados por impacto. “Resolução” é o que a arquitetura adota.

| # | Tensão | Por que importa | Resolução |
|---|--------|-----------------|-----------|
| C1 | **Pro (item 40) × diretriz 5 (trialware)**: “Webhooks ilimitados”, “lógica avançada”, “múltiplos atendentes” como Pro sugerem *limites no free* | O build do .org não pode ter funcionalidade bloqueada, cota ou código “para destravar” | Pro = **plugin separado** (`krivo-chatflow-pro`) que *adiciona* capacidades por registries/filtros. O free não tem cotas, flags de licença, menu de upsell nem código morto. “Ilimitado” deixa de ser argumento de venda — não limitamos o free. |
| C2 | **Assinatura Krivo Works (58, 59, 102) × diretriz 10** (crédito/link só com permissão explícita) | Risco de rejeição e de parecer *dark pattern* | Opt-in **desmarcado por padrão**, texto exato exibido, **dois controles** (mostrar texto / tornar link), reversível em Settings, **um único build**. Renderizada só dentro do painel, como `<footer>` com `<a rel="noopener noreferrer nofollow">`. Detalhes em `04`, seção 3.9. |
| C3 | **Arquivos exigidos (`BUILD.md`, `EXTERNAL-SERVICES.md`, `WORDPRESS-ORG-COMPLIANCE.md`) × Plugin Check** (só `README.md`, `readme.txt`, `LICENSE(.md)`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md` na raiz) | `.md` extra na raiz do ZIP gera aviso | Os três ficam em **`docs/` do repositório** e fora do ZIP. O que o revisor precisa vai para o `readme.txt` (*External Services*, *Source code / Build*). O repositório precisa ser **público**. |
| C4 | **`Tested up to`** no cabeçalho PHP (hábito comum) | O Plugin Check exige declará-lo **só no `readme.txt`**, igual à versão atual do WP (apenas major) | Cabeçalho PHP sem `Tested up to`. Verificação no release. |
| C5 | **`readme.txt` em PT-BR × “inglês padrão”** (o checker acusa “unofficial language”) | Português como idioma principal da UI é compatível, mas o readme não | Código-fonte com **strings em inglês** (convenção WP) + tradução PT-BR; `readme.txt` em inglês; textos PT-BR do readme via translate.wordpress.org. |
| C6 | **i18n (94) × `load_plugin_textdomain()` desencorajado para .org** — e o core **não** carrega `languages/` do plugin sozinho | Beta distribuído por ZIP ficaria em inglês; chamar a função gera aviso no .org | Decisão **D-04** (padrão: sem a chamada no código; PT-BR via translate.wordpress.org; beta usa instalador de tradução). |
| C7 | **Analytics “visualizações” (15) × performance/privacidade (30, 85, 96)**: 1 requisição PHP por pageview não cacheável | 10.000 views = 10.000 boots do WP | Beacon **1× por sessão por flow** (`sessionStorage`), contadores **agregados por dia** (`stats`), sem tabela de eventos brutos; opção para desligar “views”. |
| C8 | **Tabela `events` sugerida (88) × LGPD/volume** | Evento por visitante = dado pessoal em potencial + crescimento ilimitado | Troca por `stats` (agregado, sem identificador). Linha do tempo do lead fica para quando houver CRM. |
| C9 | **Nonce em formulário público (91, 92) × cache de página** | Nonce de visitante deslogado é igual para todos, expira em 12–24 h e fica “velho” no HTML cacheado → 403 em leads legítimos | **Token HMAC sem estado** (emitido ao abrir o painel) + honeypot + tempo mínimo + rate limit + checagem de `Origin`. Nonce só no admin. |
| C10 | **Regras de exibição no servidor (22) × cache de página** | Dispositivo/horário decididos no PHP ficam “congelados” no HTML cacheado | Servidor decide só o que é **da página** (URL, tipo, taxonomia, ID, logado). **Cliente** decide dispositivo, scroll, delay, horário de atendimento. |
| C11 | **Abrir WhatsApp depois de enviar (assíncrono) × bloqueio de pop-up do iOS/Safari** | `window.open` fora do gesto do usuário é bloqueado → o lead é salvo mas o WhatsApp não abre | *Pre-open*: abre `about:blank` **dentro do clique**, navega quando a resposta chegar; se bloqueado, tela de sucesso com botão “Abrir WhatsApp”. Nunca beco sem saída. |
| C12 | **Webhook como recurso central (46) + “falha externa não quebra UX” (53) + zero requisição ao carregar (96)** | Enviar e-mail/webhook dentro da requisição do visitante é lento e frágil | Todas as actions externas são **assíncronas** (fila `runs` + WP-Cron + `spawn_cron()`), 3 tentativas com *backoff*, status final `failed`. |
| C13 | **SMTP próprio (10) × “não sobrescrever globalmente” (87)** e convivência com WP Mail SMTP/FluentSMTP/Post SMTP | Hook global em `phpmailer_init` altera *todos* os e-mails do site | `phpmailer_init` e filtros de remetente **registrados só durante o envio do ChatFlow** e removidos em `finally`. O e-mail de teste informa o caminho efetivamente usado. Limitação documentada: plugins SMTP que trocam o transporte por API podem prevalecer. |
| C14 | **Lógica “enviar para destino diferente” (6) × Routing (7)** = duas fontes de verdade | Dois lugares decidindo o destino geram resultados imprevisíveis | **Um** motor de condições. *Routing* é dono de destinos e regras (escopo: todos os flows ou flows específicos). A aba *Logic* mostra as regras de roteamento do flow (mesma tela, filtrada). |
| C15 | **Abas do editor (26) não têm “Automations”**, mas o item 46 exige ações por ChatFlow | Webhook/triggers ficariam sem lugar na UI | Adicionada a aba **Automations**. Ordem: Flow · Design · Bubble · WhatsApp · Email · Automations · Logic · Publish. |
| C16 | **“Save Lead” listado como Action (48)** × ele ser o núcleo do envio | Desligar “Save Lead” quebraria webhooks (sem `lead_id`) e a fila (payload vem do lead) | “Salvar lead” é **etapa fixa do pipeline**, exibida como “sempre ativo”. Alternativa de minimização: retenção curta configurável. |
| C17 | **Tipos de campo sobrepostos (4)**: Consentimento × LGPD; UTM / página / referrer como “campos” | Duplicam funcionalidade e confundem o admin | `consent` (com preset “LGPD”) e `hidden` com `source` (valor fixo, parâmetro de URL, página, título, referrer, UTM). |
| C18 | **Ícone “WhatsApp” (18) × marca registrada/diretriz 17** (o Plugin Check mantém lista de slugs de marcas, incluindo `whatsapp`) | Logo oficial embutido = risco de marca e de licença | Ícones próprios: chat, mensagem, headset, “chat com telefone”. Logo do WhatsApp **não é embarcado**; o admin pode enviar o próprio SVG/imagem. Nome/slug nunca contêm “WhatsApp”. |
| C19 | **SVG personalizado (18) × XSS e item 67 (upload só por APIs nativas)** | SVG executa script; upload próprio é proibido | O admin **cola** o SVG; o servidor sanitiza com *allowlist* (`wp_kses`); imagem raster vem da **biblioteca de mídia** do WP. O plugin não trata upload. |
| C20 | **Admin React (25) × “não SaaS React artificial” (107)** | Dependência pesada ou bundle próprio de React viola diretriz 13 e o Plugin Check (React empacotado = erro) | React **do core** (`wp-element`, `wp-components`, `wp-api-fetch`, `wp-i18n`) via *dependency extraction*, só nas telas do ChatFlow. Dados por REST com capabilities. |
| C21 | **Ferramentas de desenvolvimento no repositório** (`.claude/`, `CLAUDE.md`, `.github/`, `.nvmrc`, scripts `.sh`) × Plugin Check (diretórios de IA, `.github`, arquivos ocultos e `.sh` são sinalizados) | O ZIP não pode conter nada disso | Empacotamento por **allowlist** (`bin/build-zip.php`), não por denylist. Versão do Node vai em `package.json#engines`, não em `.nvmrc`. Scripts de release em PHP/Node. |
| C22 | **Repositório privado × código-fonte público dos bundles (68)** | Sem fonte público, o .org recusa JS compilado | Tornar o repositório público antes da submissão **e** incluir `src/`, `package.json`, lock e config no ZIP (cinto e suspensório). |
| C23 | **Mesmo prefixo `Krivo\` do Krivo Forms** | Dois plugins empacotando uma lib “compartilhada” em versões diferentes colidem | Cada plugin é **autocontido**. Sem biblioteca comum. Interoperabilidade só por hooks/REST documentados. |
| C24 | **Paleta Krivo × acessibilidade (103)**: `#A14DFF` com texto branco = **4,18:1**; com texto escuro `#171519` = 4,34:1; sobre `#F7F4FC` = 3,84:1 | Reprova WCAG AA para texto normal (exige 4,5:1) | `#A14DFF` apenas para elementos gráficos/texto grande (≥ 3:1) e decoração. **Texto, links e botões usam `#7B2FF7`** (5,85:1 com branco, 5,37:1 sobre `#F7F4FC`). O seletor de cor do balão calcula contraste e avisa. |

---

## 3. Ambiguidades resolvidas (padrão adotado × alternativa)

| ID | Questão | Padrão adotado | Alternativa |
|----|---------|----------------|-------------|
| D-01 | Piso de PHP | **7.4** (mesmo piso do core 7.1.2). Código limpo em 8.3, testado em 7.4 / 8.1 / 8.3 / 8.4 | 8.0+ (usa `match`, promoção de construtor; perde instalações em 7.4) |
| D-02 | Piso de WordPress | **6.5** (o core atual é 7.1.x). Matriz de teste: 6.5 e a mais recente | 6.7+ (menos deriva do `wp-components`, menos alcance) |
| D-03 | Visualizações no analytics | Beacon 1×/sessão/flow, agregado; desligável | Não contar views (funil só a partir de “abertura”) |
| D-04 | Tradução PT-BR antes da aprovação no .org | Sem `load_plugin_textdomain()` no código; PT-BR por translate.wordpress.org; beta instala o `.mo` em `wp-content/languages/plugins/` | Uma chamada protegida só no ZIP beta |
| D-05 | `dataLayer` (GTM) | Eventos DOM sempre disparados; **espelho em `dataLayer` desligado por padrão** (opt-in em Settings) | Ligado por padrão |
| D-06 | Retenção | Leads: **indefinida** (o admin define o prazo); logs de automação: **30 dias**; contadores: indefinido (agregados, sem dado pessoal) | Leads com prazo padrão (surpreende o usuário e apaga dado de negócio) |
| D-07 | Posição do menu | Menu de topo, **posição `null`** (final da lista, após Configurações), ícone SVG `data:` monocromático | Submenu de *Ferramentas* (não comporta 7 módulos) |
| D-08 | Onboarding | **Um** redirecionamento na primeira ativação (somente quem tem capability; nunca em ativação em lote, multisite de rede, AJAX ou WP-CLI) + aviso dispensável como *fallback* | Apenas aviso (mais conservador para revisão) |
| D-09 | Modo conversacional e shortcode | Mesmo modelo de dados (passos). **v1.0**, não MVP | Incluir no MVP |
| D-10 | Nomes de variáveis | **Canônicos em inglês** (`{name}`, `{phone}`, `{page_url}`…) **+ aliases PT-BR fixos** (`{nome}`, `{telefone}`, `{pagina}`…). Chaves de campo são livres | Só PT-BR (quebra o item 94) |
| D-11 | Métodos de webhook | POST, PUT, PATCH. GET fora (sem necessidade real) | Incluir GET |
| D-12 | Política de URL do webhook | **HTTPS obrigatório**; HTTP, hosts privados e portas fora de 80/443/8080 só com opção avançada explícita | HTTP livre |
| D-13 | Widgets simultâneos | **Um balão por página**; empate resolvido por prioridade do flow | Vários balões |
| D-14 | Transporte público | REST + *fallback* `admin-ajax.php` (mesma classe de serviço) para sites que bloqueiam a REST a visitantes | Só REST |
| D-15 | Versão do `Stable tag`/readme | `0.x` até a v1.0; primeira submissão ao .org = **v1.0**, não MVP | Submeter o MVP |

---

## 4. Melhorias propostas (só as que valem o custo)

1. **Rascunho × publicado** com **pré-visualização no frontend** só para administradores (URL com nonce): testar sem afetar o site.
2. **Painel de saúde** no Dashboard: automações falhando, fila atrasada, WP-Cron desativado, SMTP não testado.
3. **`Reply-To` = e-mail do lead** nas notificações internas (responder direto ao lead).
4. **Webhooks com idempotência e assinatura**: cabeçalhos `X-KrivoChatFlow-Event`, `-Delivery` e assinatura HMAC com timestamp (anti-replay).
5. **“Dry run”** (endpoint de simulação): mostra mensagem do WhatsApp, e-mail e payload com dados de exemplo, sem gravar nada — é também o *preview* do item 8.
6. **Supressão de duplicidade** (duplo clique / reenvio) sem disparar automações duas vezes.
7. **`public_id` (UUID v4)** no lead para webhooks e links, em vez de expor ids sequenciais.
8. **CSV seguro**: proteção contra *formula injection*, BOM UTF-8 e delimitador `;` opcional (Excel pt-BR).
9. **Fixtures compartilhadas PHP ↔ JS** (condições e variáveis): uma implementação por linguagem, uma só definição de verdade.
10. **Teste de inventário de hooks**: hook sem documentação em `docs/HOOKS.md` falha o CI.
11. **Orçamento de tamanho** no CI (gzip por arquivo) e medição Lighthouse com/sem plugin.
12. **“Connections”**: credenciais/endpoints separados das actions — o mesmo n8n serve a vários flows, e segredos ficam num só lugar (e a base certa para integrações Pro).
13. **Quarentena de spam** (`status = spam`, sem disparar automações) em vez de descartar silenciosamente — evita perder lead legítimo por falso positivo.
14. **`window.KrivoChatFlow.open()/close()/on()`** — abrir o painel a partir de qualquer botão/menu e escutar eventos sem depender do DOM.
15. **Checagem de contraste** no seletor de cor do balão/painel (WCAG), pelo problema da paleta (C24).

## 5. O que fica explicitamente fora (e por quê)

Lista completa e fases no doc 06. Os cortes mais relevantes:

- **Upload de arquivos**: exige `wp_handle_upload()`, validação MIME/tamanho e superfície pública de abuso → pós-1.0.
- **Cálculos, máscaras, validações avançadas, lógica aninhada/matemática**: o *schema* já admite grupos aninhados; a UI começa plana.
- **Integrações nativas** (RD Station, HubSpot, Sheets…): a engine genérica de webhook + *Connections* cobre; presets só se melhorarem a UX.
- **WhatsApp Cloud API**: fora. WhatsApp é **link `wa.me`** aberto pelo navegador do visitante. Se um dia houver API, será outro serviço externo, documentado à parte.
- **CAPTCHA**: integração opcional futura; não é dependência.
- **Bloco Gutenberg** e **intenção de saída**: arquitetura preparada (modo `inline` do widget; gatilhos de comportamento extensíveis), sem implementação.
- **Telemetria/analytics da Krivo**: nenhuma, nunca (itens 73, 96).
