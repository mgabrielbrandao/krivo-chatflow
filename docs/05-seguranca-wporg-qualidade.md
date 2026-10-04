# 05 · Segurança, WordPress.org e qualidade

Cobre itens 30–34, 43, 60–106 do prompt. Prioridade final (item 107): **1 Segurança · 2 Compatibilidade WP.org · 3 Funcionamento real · 4 Performance · 5 UX · 6 Extensibilidade · 7 Design**.

> **Aviso de verificação.** As seções 2 e 4 dependem das *Detailed Plugin Guidelines*, cujo texto integral **não pôde ser relido**
> (bloqueio de rede, ver doc 01, seção 1). Títulos das 18 diretrizes foram confirmados por busca; as regras objetivas da seção 3 vêm
> do **código-fonte do Plugin Check 2.1.0**. A matriz é **provisória até o marco M7**, quando será relida e convertida em `WORDPRESS-ORG-COMPLIANCE.md`.

---

## 1. Modelo de ameaças

| # | Ativo / superfície | Ameaça | Mitigação (onde) | Prova (teste) |
|---|--------------------|--------|------------------|---------------|
| T1 | Endpoint público de envio | Spam, inundação, *e-mail bombing*, uso como relé de webhook | Token HMAC, honeypot, tempo mínimo, rate limit por IP/flow, disjuntor, `Origin`, quarentena (doc 02, §11) | testes de guarda; teste de carga leve; autoresponder limitado |
| T2 | Autoresponder | Atacante digita o e-mail da vítima e usa o site para enviar mensagem | 1 por e-mail/hora, não ecoa texto livre, nunca em `spam`, só `is_email` | teste de limite por destinatário |
| T3 | REST/admin-post | **CSRF** | Nonce `wp_rest` (core) + `check_admin_referer` no export | requisição sem nonce → negada |
| T4 | REST/admin-post | **Elevação de privilégio** / IDOR | 13 capabilities; verificação **por seção** do flow; `permission_callback` real em toda rota; nunca `__return_true` | **matriz de capabilities** (anônimo, assinante, autor, editor, admin × todas as rotas); inventário de rotas |
| T5 | Dados do visitante exibidos (admin, e-mail, manifesto) | **XSS armazenado** | Sanitiza na entrada por tipo; escapa na saída por contexto (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`); JS sem `innerHTML` com dados; JSON na página com `JSON_HEX_*` | corpus XSS em **todos** os campos → verificação de saída em REST, e-mail HTML, manifesto, tela de leads |
| T6 | SVG personalizado | XSS por SVG | Colar (não *upload*) + *allowlist* `wp_kses`; sem `script`, `foreignObject`, `on*`, `href` externo | corpus de SVGs maliciosos |
| T7 | Banco | **SQL injection** | `$wpdb->prepare`/`insert`/`update` com formatos; `ORDER BY` e colunas por *whitelist*; `LIKE` com `esc_like` | corpus SQLi nos filtros/busca/ordenação; PHPCS `PreparedSQL` |
| T8 | Webhook | **SSRF** (URL interna, metadados de nuvem, portas) | `wp_safe_remote_request`, HTTPS por padrão, sem redirecionamentos, bloqueio de IP privado/loopback e portas, opt-in avançado explícito | testes com `127.0.0.1`, `169.254.169.254`, DNS que resolve a IP privado, redirect 302 |
| T9 | Webhook/e-mail | **Injeção de cabeçalho** (CR/LF) | Nome de cabeçalho validado, valor sem CR/LF, assunto sem CR/LF, `Reply-To` sanitizado | testes com `\r\n` em todos os campos |
| T10 | CSV | *Formula injection* | Prefixa `'` em células que começam com `= + - @ TAB CR` | teste de exportação |
| T11 | Segredos | Vazamento por REST, HTML, JS, log, erro, e-mail | Criptografia (`secretbox`), repositório separado, `Redactor`, `is_set` apenas, constante opcional | **teste de canário**: valor-canário em cada campo secreto; varre todas as respostas REST, HTML, logs e payload previews |
| T12 | Manifesto público | Exposição de rascunho/dados internos | Só publicado; subconjunto público (sem e-mails, regras, automações, número de WhatsApp); preview exige capability + nonce | teste de contrato do manifesto (lista de chaves permitidas) |
| T13 | *Directive* de WhatsApp | Redirecionamento aberto | URL montada no servidor a partir do destino; cliente só abre `https://wa.me/…`; sem URL de redirecionamento vinda do visitante | teste de unidade do cliente |
| T14 | Regras do admin | **ReDoS** | Sem regex do usuário; curinga `*` compilado com `preg_quote` | teste de desempenho com padrões patológicos |
| T15 | Payloads | DoS por tamanho | Corpo ≤ 16 KB; limites por campo; limites do JSON do flow | testes de limite |
| T16 | Privacidade | Coleta indevida / retenção infinita | Minimização configurável, **sem IP armazenado**, sem cookies, retenção, exportação/exclusão, consentimento versionado | testes de exportador/apagador/retenção |
| T17 | Cadeia de suprimentos | Dependência maliciosa/licença incompatível | Zero dependência de runtime; `package-lock.json`; `composer audit`/`npm audit`; auditoria de licenças do que é **embarcado** | `bin/license-audit` + auditorias no CI |
| T18 | Fila | Execução dupla / laço infinito | Reivindicação atômica, 3 tentativas, guarda de profundidade em `lead.updated` | testes de concorrência e de laço |
| T19 | Multisite | Vazamento entre sites | Tabelas por site (`$wpdb->prefix`), instalação preguiçosa por site | teste em multisite |

---

## 2. Conformidade com o WordPress.org por design (as 18 diretrizes)

Títulos conferidos por busca (texto completo pendente — ver aviso). “Controle” = verificação automática que impede regressão.

| # | Diretriz | Como o ChatFlow atende | Controle |
|---|----------|------------------------|----------|
| 1 | Compatível com a GPL | `GPL-2.0-or-later` no cabeçalho e no `readme.txt` (mesmo SPDX); ícones e CSS próprios; sem biblioteca de terceiros em runtime; fragmentos MIT do *runtime* do webpack/Babel nos bundles são compatíveis e listados | `release-check` (licença igual nos dois lugares, auditoria do que vai no ZIP) |
| 2 | Responsabilidade do desenvolvedor | Revisão de segurança por marco; `SECURITY.md`; nada embarcado sem licença conhecida | checklist do PR (§11) |
| 3 | Versão estável disponível | `Stable tag` = `Version` do cabeçalho = constante = `package.json` | `release-check` (coerência de versões) |
| 4 | Código (quase) legível | `src/` + `package.json` + lock + config **dentro do ZIP**, repositório **público**, seção *Source code* no `readme.txt`, `docs/BUILD.md`; sem ofuscação, sem `eval` | `release-check` (todo `build/**/*.js` tem entrada em `src/`); Plugin Check `code_obfuscation` |
| 5 | Sem *trialware* | Pro = plugin separado; free sem cotas, flags de licença, menu de upsell ou código bloqueado | `release-check` (busca por padrões de licença/upsell; menu sem links externos) |
| 6 | SaaS permitido | O plugin é **plenamente funcional sozinho**; serviços opcionais documentados | revisão de `External Services` |
| 7 | Sem rastreamento sem consentimento | Zero telemetria; nenhuma requisição externa por padrão; só contadores agregados locais; armazenamento local do visitante documentado | **teste de saída de rede**: durante toda a jornada E2E, qualquer `wp_remote_*` ou requisição do navegador a terceiros **falha o teste** (com webhook/SMTP desligados) |
| 8 | Sem código executável por sistemas de terceiros | Sem CDN, sem script remoto, sem *updater*, sem `eval`/`new Function` | Plugin Check `plugin_updater`, `offloading_files`; `release-check` |
| 9 | Nada ilegal/enganoso | *Readme* honesto (não afiliado ao WhatsApp/Meta); sem *review gating* | Plugin Check `plugin_content` (links de 5 estrelas) |
| 10 | Sem links/créditos externos sem permissão | Assinatura **opt-in**, desmarcada, dois consentimentos, reversível (doc 04, §3.9) | teste E2E: assinatura **ausente** por padrão; presente só com opt-in; `rel` correto |
| 11 | Sem sequestrar o admin | Menu na posição final, avisos só para quem pode e dispensáveis, sem CSS global, sem esconder notices/Screen Options, 1 redirecionamento guardado | `release-check` (seletores globais proibidos); teste do menu |
| 12 | Sem spam | `readme.txt` sem nomes de concorrentes, ≤ 5 tags, sem afiliados | `release-check` (tags) + Plugin Check `plugin_readme` |
| 13 | Usar as bibliotecas do WordPress | React/`@wordpress/*` do core (externalizados); PHPMailer do core; libsodium do core; sem jQuery/lodash/moment empacotados | Plugin Check `library_core_files`, `react_usage` |
| 14 | Evitar commits frequentes | Releases deliberados; procedimento documentado em `BUILD.md` | processo |
| 15 | Incrementar versão a cada release | `release-check` compara com a tag anterior | `release-check` |
| 16 | Plugin completo na submissão | Submeter **v1.0**, não o MVP | decisão D-15 |
| 17 | Respeitar marcas | Nome **Krivo ChatFlow**, *slug* `krivo-chatflow`; “WhatsApp” só como descrição; sem logotipos de terceiros | Plugin Check `trademarks`; `release-check` |
| 18 | Direito de manutenção do diretório | Aceito; contato em `SECURITY.md` | — |

---

## 3. Regras objetivas extraídas do código do Plugin Check 2.1.0

Fonte: `WordPress/plugin-check`, `includes/Checker/Checks/**` (commit de 2026-10-02; 36 verificações registradas). Cada linha vira item do `release-check`.

| Regra | Verificação de origem | Como atendemos |
|-------|----------------------|----------------|
| `Tested up to` **só no `readme.txt`**, igual à versão atual do WP (só *major*); **não** no cabeçalho PHP | cabeçalhos/readme | Cabeçalho sem a linha; valor definido no M7 após testar na versão atual |
| `Stable tag` = `Version`; nome do readme = nome do cabeçalho | cabeçalhos/readme | `release-check` |
| Readme e descrição em **inglês padrão** (“unofficial language”) | readme | `readme.txt` em inglês; PT-BR via translate.wordpress.org |
| `Contributors` = usuários válidos do WordPress.org (distingue maiúsculas; reservados/restritos rejeitados) | readme | `Contributors: gabrielbrandaoai` (**a validar**; não foi possível checar) |
| Licença válida e **igual** no cabeçalho e no readme | cabeçalhos/readme | `GPL-2.0-or-later` |
| Raiz do plugin: só `README.md`, `readme.txt`, `LICENSE`, `LICENSE.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md` como `.md` | `File_Type_Check` (conteúdo inesperado) | `BUILD.md` etc. em `docs/`, fora do ZIP |
| Diretórios `.cursor .claude .aider .continue .windsurf .ai` e `.github` sinalizados | `File_Type_Check` | ZIP por **allowlist** |
| **Arquivos ocultos = erro** (exceto `.distignore`/`.gitignore` em ambiente não produtivo) | `File_Type_Check` | nenhum arquivo oculto no ZIP (Node em `package.json#engines`, não `.nvmrc`) |
| Compactados, `phar`, **executáveis e scripts** (`.sh` incluso), `.so`… = erro | `File_Type_Check` | scripts de release em PHP/Node; fora do ZIP |
| Nomes com espaço/caracteres especiais; pastas que só diferem por caixa | `File_Type_Check` | convenção de nomes |
| Bibliotecas do core por nome de arquivo (`jquery`, `lodash`, `moment`, `backbone`, `PHPMailer.php`…) = erro | `File_Type_Check` | não embarcamos |
| **React embutido (pré-19) no bundle = erro**; exige externalização | `React_Usage_Check` | `@wordpress/scripts` com extração de dependências |
| Scripts do plugin **sem `strategy`** → aviso (cabeça: “potencialmente bloqueante”) | `Non_Blocking_Scripts_Check` | `strategy: 'defer'` + `in_footer` |
| Tamanho de scripts e de estilos carregados por página (limite padrão **300.000 bytes**) | `Enqueued_Scripts_Size_Check`, `Enqueued_Styles_Size_Check` | nosso orçamento é ~2 ordens de grandeza menor (≈ 4 KB + 2,5 KB gz) |
| **Aviso** “script/estilo carregado em **todos** os contextos do frontend” (testa home, busca, autor e o permalink/termos de cada tipo de post) | `Enqueued_Scripts_Scope_Check`, `Enqueued_Styles_Scope_Check` | Num WP limpo, **sem flow publicado, não carregamos nada** → sem aviso. Com o balão publicado no site inteiro (uso normal de um widget flutuante) o aviso é **inerente** ao produto: será **triado com justificativa** em `PLUGIN-CHECK-TRIAGE.md` (carregamento só com flow publicado e regra correspondente; loader ≤ 4 KB; painel sob demanda). **Não** vamos mascarar o aviso com regras artificiais |
| `register_setting()` **precisa** de `sanitize_callback` | `Setting_Sanitization_Check` | `register_setting` com schema único |
| Ícone de menu: *dashicon* ou **SVG `data:`** (raster = aviso) | `Menu_Image_Icon_Check` | SVG mono `data:` |
| Links externos no menu do admin | `External_Admin_Menu_Links_Check` | nenhum |
| `load_plugin_textdomain()` **desencorajado** para plugins no .org | `Plugin_Review_PHPCS_Check` | decisão D-04 (sem chamada) |
| `uninstall.php` deve checar `WP_UNINSTALL_PLUGIN` | `Plugin_Uninstall_Check` | ✔ |
| Acesso direto a arquivos PHP | `Direct_File_Access_Check` | `defined( 'ABSPATH' ) \|\| exit;` em todo PHP |
| Prefixo único em tudo que é global | `Prefixing_Check` | namespace `Krivo\ChatFlow` + `krivo_chatflow_` |
| Slugs de marcas (inclui `whatsapp`, `instagram`, `facebook`, `google-`, `elementor-`, `woocommerce`…) | `Trademarks_Check` | *slug* `krivo-chatflow` |
| Não gravar na pasta do plugin (usar `uploads`/banco) | `Write_File_Check` | nenhuma gravação em arquivos |
| *Updaters* próprios proibidos | `Plugin_Updater_Check` | nenhum no free (o Pro, fora do .org, terá o seu) |
| Carregamento remoto de arquivos/serviços sem necessidade | `Offloading_Files_Check` | tudo local |
| Link direto para avaliações de 5 estrelas | `Plugin_Content_Check` | nenhum |
| `block.json` com `apiVersion ≥ 3` | `Plugin_Content_Check` | para o bloco futuro |
| *Safe redirect*, verificação de nonce, escape tardio, consultas diretas ao banco | `Security/*` | `wp_safe_redirect`; padrões das seções 1 e 5 |
| `localhost` em código de produção | `Localhost_Check` | sem URLs locais |
| Compatibilidade de funções com o `Requires at least` | `WP_Functions_Compatibility_Check` | piso 6.5 testado |

---

## 4. Regressões do Krivo Forms → controles automáticos (item 106)

Cada problema vira **teste/checagem que falha o build**. Não é “corrigir quando o revisor reclamar”.

| # | Problema anterior | Prevenção | Onde roda |
|---|-------------------|-----------|-----------|
| R1 | CSS/JS sem *enqueue* | Só `wp_register_*/wp_enqueue_*`, `wp_add_inline_*`, `wp_print_inline_script_tag`. `release-check` faz varredura de *tokens* PHP por `<script`/`<style` fora de uma *allowlist* justificada; PHPCS `WordPress.WP.EnqueuedResources`; Plugin Check `enqueued_resources` | CI + release |
| R2 | Requisições admin sem proteção | Todas as rotas admin: capability **+** nonce do core; `admin-post` com `check_admin_referer` + capability. **Teste de inventário de rotas**: toda rota fora da *allowlist pública* tem `permission_callback` ≠ `__return_true` | CI |
| R3 | Nonce **sem** permissão | Matriz de capabilities (papéis × rotas) + verificação por seção do flow | CI |
| R4 | URLs externas inválidas | `bin/link-check.php` valida **toda** URL do `readme.txt`, de `EXTERNAL-SERVICES.md` e das constantes do plugin (2xx/3xx; sem `example.com`/`exemplo`/`localhost`). **Relatório com ≤ 24 h é pré-requisito do ZIP**. Exige rede — roda no CI do GitHub ou na sua máquina (não neste sandbox) | release |
| R5 | `Contributors` incorreto | `bin/release.json` guarda o usuário **confirmado**; `release-check` exige igualdade exata com o `readme.txt`; **checklist manual** de abrir o perfil antes de cada submissão | release |
| R6 | JS compilado sem fonte | `src/` + `package.json` + lock + config no ZIP; seção *Source code* no `readme.txt` com repositório **público** (verificado via API do GitHub); cada `build/**/*.js` precisa ter entrada mapeada | release |
| R7 | Serviços externos sem documentação | `bin/external-endpoints.json` (registro). `release-check` varre PHP/JS por capacidade de saída (`wp_remote_*`, `wp_safe_remote_*`, `fetch`, `XMLHttpRequest`, `sendBeacon`, `window.open`, literais `https?://`) e **exige** cada host em `readme.txt › External Services` **e** `docs/EXTERNAL-SERVICES.md`. Teste de rede zero por padrão | CI + release |
| R8 | Upload fora das APIs nativas | Sem upload no 1.0. PHPCS `WordPress.WP.AlternativeFunctions`; busca por `move_uploaded_file`, `$_FILES`. Futuro: `wp_handle_upload()` | CI + release |
| R9 | Posição do menu inadequada | `position = null`; teste lê `$GLOBALS['menu']` após `admin_menu` e exige que o item venha **depois** de *Configurações*; Plugin Check | CI |

---

## 5. Privacidade / LGPD (itens 32, 85) e serviços externos (itens 71–75)

### 5.1 Controles

| Requisito | Implementação |
|-----------|---------------|
| Consentimento | Tipo `consent` (preset “LGPD”), texto **versionado** (guarda o *hash* do texto exibido e a data); obrigatório se o admin marcar; link automático para a política de privacidade do site (`get_privacy_policy_url()`) |
| Minimização | Capturas de UTM/referrer **desligáveis**; **IP não é armazenado**; sem *user agent* (só classe de dispositivo); sem cookies |
| Apagar | Por lead, em lote; **apagar o lead apaga seus `runs`** |
| Exportar | CSV (capability própria) + exportador de dados pessoais do WordPress |
| Retenção | Leads (padrão: indefinida, configurável), logs (30 dias) — purga em lotes |
| Ferramentas de privacidade do WP | `wp_privacy_personal_data_exporters` / `_erasers` (por e-mail, paginados em 50) e `wp_add_privacy_policy_content()` com texto sugerido |
| **Lacuna declarada** | As ferramentas do WP identificam pessoa por **e-mail**; leads **sem e-mail** (fluxo só com WhatsApp) não aparecem lá — o admin usa a busca/exclusão da tela de Leads. O texto sugerido avisa |
| Desinstalar | “Excluir todos os dados ao desinstalar” (padrão desligado); nunca apaga em silêncio |

### 5.2 Texto de privacidade sugerido (fonte em inglês; traduzível)

> *Krivo ChatFlow stores the answers a visitor submits through the chat widget (for example name, contact details and message), together with the page URL, page title, referrer and UTM parameters, in your site’s database. No IP address is stored. In the visitor’s browser it stores, without cookies, a few interface flags in `sessionStorage`/`localStorage` (keys starting with `krivo_chatflow:`). Data is sent to third parties only if the site owner enables it: to an SMTP server or webhook URL configured by the owner, or to WhatsApp when the visitor chooses to continue in WhatsApp (their browser opens a `wa.me` link containing the message).*

### 5.3 Serviços externos (rascunho do `External Services`; item 71)

Nenhum serviço da Krivo Works. **Nenhuma URL abaixo foi verificada** (bloqueio de rede) — a regra é: **o `readme.txt` não é gerado enquanto houver URL não verificada**.

| Serviço | Para quê | Quando conecta | O que é enviado | Termos / Privacidade |
|---------|----------|----------------|-----------------|----------------------|
| **WhatsApp** (link *click-to-chat* `wa.me`) | Continuar a conversa | **Navegador do visitante**, só depois do clique em “Continuar pelo WhatsApp”; o servidor do site não contata o WhatsApp | Número de destino e a mensagem montada com as respostas do visitante (na URL) | Candidatas, **a verificar**: `https://www.whatsapp.com/legal/terms-of-service` · `https://www.whatsapp.com/legal/privacy-policy` |
| **Webhook configurado pelo administrador** (n8n, Make, Zapier ou outro) | Entregar leads à automação do dono do site | Só com Connection **habilitada e confirmada**, quando um trigger dispara (ou no botão “Testar”) | Payload do lead (doc 03, §6.2) | Cada serviço tem seus próprios termos — listados por preset **após verificação** |
| **Servidor SMTP configurado pelo administrador** | Entregar e-mails de lead | Só com “SMTP próprio” configurado, ao enviar e-mail do ChatFlow | O e-mail (conteúdo do lead) | Do provedor escolhido pelo dono do site |

Não é alegada parceria ou integração oficial com o WhatsApp/Meta; o `readme.txt` traz aviso de **não afiliação**.

---

## 6. Estratégia de testes (item 43) — “a interface abrir” não conta como pronto

| Área | O que a suíte prova | Tipo / ferramenta | Roda |
|------|--------------------|-------------------|------|
| Criação/validação/publicação de flow | Schema, chaves únicas, ciclos de `goto`, SVG/HTML sanitizados, rascunho × publicado, conflito 409, *dry-run* | PHPUnit (unidade + integração), E2E do editor | sandbox ✔ |
| Envio (pipeline) | Tabela de validação por tipo, visibilidade por lógica, duplicidade, quarentena, formatos do `insert`, resposta e *directives* | PHPUnit integração + E2E | sandbox ✔ |
| Lógica | Operadores/tipos; **mesmas fixtures** em PHP e JS | PHPUnit + Jest | sandbox ✔ |
| Sanitização / XSS / SQLi | Corpus de ataque em todo campo e filtro; saídas escapadas por contexto | PHPUnit (`security/`) | sandbox ✔ |
| Permissões | Matriz papéis × rotas; nonce ausente; permissão por seção; inventário de rotas | PHPUnit (`security/`) | sandbox ✔ |
| REST/AJAX | Contrato, `args`, códigos de erro; token adulterado/expirado/cedo; honeypot; rate limit; *fallback* AJAX | PHPUnit integração | sandbox ✔ |
| SMTP | Mapeamento de config; **ganchos escopados** (adicionados e removidos em `finally`, inclusive com exceção); `Redactor`; conversa SMTP contra **sumidouro local**; diagnóstico do transporte | PHPUnit + `smtp-server` (npm, dev) | sandbox ✔ (provedores reais: manual) |
| Webhook | Servidor-stub: 200/204/500/429+`Retry-After`/timeout/302/JSON inválido; SSRF (IP privado/metadados/porta); assinatura HMAC verificável; *backoff* e 3 tentativas com relógio simulado; canário de segredos | PHPUnit + servidor PHP embutido | sandbox ✔ |
| Fila | Reivindicação atômica (concorrência), `running` travado, laço em `lead.updated`, retenção | PHPUnit integração | sandbox ✔ |
| Mensagens WhatsApp | E.164, acentos/emoji/quebras, variável vazia, truncamento em 4.000 caracteres codificados, destino sem WhatsApp | PHPUnit | sandbox ✔ |
| Armazenamento de lead | Índices usados (`EXPLAIN` de fumaça), retenção, exportador/apagador, *uninstall* com/sem remoção, multisite | PHPUnit integração | sandbox ✔ |
| Migrações | Upgrade a partir de fixtures de schema anterior; instalação preguiçosa | PHPUnit | sandbox ✔ |
| Widget (lógica) | Máquina de passos, eventos e `detail` sem PII, *pre-open* do WhatsApp, UTMs/sessão, delay/scroll/uma-vez | Jest + jsdom | sandbox ✔ |
| Widget (jornada) | Abrir → preencher → enviar → lead salvo → e-mail no sumidouro → webhook no stub → URL do WhatsApp; **somente teclado**; `axe-core`; CSS hostil | Playwright (Chromium) | sandbox ✔ |
| Responsividade | 320 / 375 / 414 / 768 / 1280 px, toque, teclado virtual simulado | Playwright com emulação | sandbox ✔ (aparelhos reais: manual) |
| Performance | `size-budget`; A/B com/sem plugin (CLS, TBT, LCP) | script + Chromium | sandbox ✔ |
| i18n | `.pot` sem strings fora de `__()`, *text domain* correto, fumaça PT-BR | PHPCS (I18n) + `wp i18n` | sandbox ✔ |
| **Plugin Check** (completo, WP limpo) | Zero erros; cada aviso triado um a um (`docs/PLUGIN-CHECK-TRIAGE.md`) | WP-CLI + Plugin Check clonado do GitHub | sandbox ✔ |
| **Matriz de conflitos** (Elementor, Elementor Pro, WooCommerce, tema padrão atual, plugin SMTP popular, cache/minificação) | Sem erro fatal/aviso; widget funcional; sem *hacks* específicos | wp-env/Playground no CI | **✘ sandbox** (wordpress.org bloqueado) → **CI do GitHub / sua máquina** |
| **Instalação limpa** (item 99) | Ativar → 1º flow → publicar → abrir site → enviar → lead → e-mail → webhook → WhatsApp → desativar → reativar → *uninstall* (com/sem remoção), com `WP_DEBUG` e **falha em qualquer notice/warning/deprecated/fatal** | Playwright em WP self-hosted (e Playground no CI) | sandbox ✔ / CI |
| Navegadores | iPhone/Safari, Chrome Android, Samsung Internet | roteiro manual em aparelhos reais | **owner** |

**Definição de pronto de uma feature**: testes PHP + JS (quando há lógica de cliente) + jornada E2E (quando é visível) + checklist do PR (§11) + `release-check` limpo + `docs/HOOKS.md`/`EVENTS.md` atualizados + uma resposta explícita a “o que acontece quando falha?”.

## 7. Ambiente de teste

| Peça | Viável neste ambiente? | Como |
|------|------------------------|------|
| WordPress 7.1.2 e 6.5.x | ✔ | `git clone --depth 1 --branch <tag> https://github.com/WordPress/WordPress` |
| MariaDB | ✔ | `apt-get install mariadb-server` |
| PHPUnit + suíte do core | ✔ | `wp-phpunit/wp-phpunit` (Packagist) |
| `@wordpress/scripts`, Jest, Playwright | ✔ | npm; Chromium já instalado |
| Sumidouro SMTP / servidor-stub HTTP | ✔ | `smtp-server` (npm, só dev) / servidor PHP embutido |
| Plugin Check | ✔ | clonar `WordPress/plugin-check` + `composer install` |
| PHP 7.4 / 8.1 / 8.4, WP mínimo × máximo, plugins de terceiros, Safari, Samsung Internet, SMTP real | ✘ aqui | **GitHub Actions** (matriz) e roteiros manuais — o `ci.yml` é escrito no M0, mas **só roda no GitHub** |

---

## 8. Checklist automático de release (item 97) — `bin/release-check.php`

Roda sobre o **conteúdo do ZIP** (não sobre o repositório) e produz `release-report.md`. Qualquer falha **bloqueia** o empacotamento.

| Grupo | Verificações |
|-------|--------------|
| Assets | `<script>`/`<style>` impressos por PHP; `echo`/`print` de HTML com assets; scripts sem `strategy`; asset externo (qualquer `https://` em `src=`/`href=` de assets) |
| Código perigoso | `eval`, `assert(`, `create_function`, `new Function`, `base64_decode` suspeito, `curl_*`, `file_get_contents` de URL, `fsockopen`, `move_uploaded_file`, `$_FILES`, `exec/system/shell_exec/passthru/proc_open` |
| Autorização | Rotas sem `permission_callback`/com `__return_true` fora da *allowlist* pública (introspecção em runtime); `wp_ajax_*`/`admin_post_*` sem `check_ajax_referer`/`check_admin_referer` + `current_user_can` (exceto o *fallback* público justificado); hooks admin sem capability |
| Dados | SQL concatenado (PHPCS `PreparedSQL`); `DirectDatabaseQuery` sem justificativa; segredos expostos (teste de canário + padrões de chave/token/`-----BEGIN`) |
| Serviços e links | URLs fora do registro `external-endpoints.json`; relatório do `link-check` com ≤ 24 h; proibição de `example.com`/`localhost`/placeholders |
| Fonte e licenças | `build/**/*.js` sem entrada em `src/`; ausência de `src/`+`package.json`+lock no ZIP; auditoria de licenças do que é embarcado |
| Higiene | `console.log`, `debugger`, `var_dump`, `print_r`, `error_log`; `TODO/FIXME/XXX` marcados como críticos; arquivos temporários (`*.bak *.orig *.log *.tmp .DS_Store Thumbs.db`); credenciais |
| Estrutura do pacote | Nada fora da *allowlist* (§12); sem ocultos, `.github`, diretórios/arquivos de IA (`CLAUDE.md`, `AGENTS.md`, `.claude/`…), `.sh`, `tests/`, `node_modules/`, `vendor/`, `composer.*`; `.md` na raiz só os permitidos |
| Metadados | Versão idêntica (cabeçalho = `readme.txt › Stable tag` = constante = `package.json`); versão **maior** que a tag anterior; `Tested up to` **só** no readme; `Contributors` igual a `bin/release.json`; licença igual; `Requires PHP/at least` coerentes com o código (PHPCompatibilityWP) |
| i18n | `.pot` atualizado; sem strings fora de funções de tradução |
| Performance | `size-budget` dentro do orçamento |
| Rede | Teste E2E de “zero requisição a terceiros” no estado padrão |
| **Plugin Check** | Execução completa, **zero erros**, cada aviso com decisão registrada em `docs/PLUGIN-CHECK-TRIAGE.md` (item 98: investigar um por um, sem ignorar em lote) |

## 9. Build reprodutível e código-fonte (itens 68–70)

- **Ferramenta**: `@wordpress/scripts` (webpack 5 + Babel), versões fixadas em `package-lock.json`. **Node 22 LTS** (testado), npm ≥ 10, declarado em `package.json#engines`.
- **Fonte**: `src/` (legível, ES modules, JSDoc com `checkJs`; **sem TypeScript** para manter a distância entre fonte e saída mínima). **Saída**: `build/`.
- **Comandos**: `npm ci` → `npm run build` (produção, minificada, sem *source maps* no ZIP) · `npm run build:dev` (legível).
- **Minificação normal**, sem ofuscação, nomes de variáveis do autor preservados na fonte. **Reprodutibilidade**: o CI compila **duas vezes** e compara *checksums*.
- **Onde o revisor encontra tudo**: dentro do ZIP (`src/`, `package.json`, lock, `webpack.config.js`) **e** no repositório público; `readme.txt` tem a seção *Source code* com o URL do repositório e os comandos; `docs/BUILD.md` detalha.
- **Pré-requisito do primeiro release**: o repositório `mgabrielbrandao/krivo-chatflow` hoje é **privado**; precisa ficar público (ou ter espelho público) antes da submissão.

## 10. Documentos de auditoria (marco M7; item 105)

`docs/WORDPRESS-ORG-COMPLIANCE.md` (diretriz × como atende × arquivos × riscos × verificações, após **releitura integral** das diretrizes) · `docs/EXTERNAL-SERVICES.md` ·
`docs/BUILD.md` · `docs/PLUGIN-CHECK-TRIAGE.md` · `release-report.md` · `docs/HOOKS.md` · `docs/EVENTS.md`. A auditoria é feita *como revisor*: procura o que **não** está na lista, não só problemas conhecidos.

## 11. Checklist do desenvolvedor (item 104) — modelo do PR

Antes de dar uma feature como pronta, responder (modelo em `.github/pull_request_template.md`, fora do ZIP):

1. Segue as APIs do WordPress? 2. Está sanitizado? 3. Está escapado? 4. Tem checagem de capability? 5. Precisa de nonce? 6. Faz requisição externa?
7. Essa requisição está documentada? 8. O usuário consentiu/configurou? 9. Usa asset externo? 10. Existe fonte legível do bundle? 11. Pode afetar a performance?
12. Pode conflitar com outros plugins? 13. Funciona numa instalação limpa? 14. Está compatível com as regras atuais do WordPress.org?
Qualquer “sim” de risco **é corrigido antes** de seguir.

## 12. Estrutura do ZIP (allowlist — `bin/build-zip.php`)

```
krivo-chatflow/
├── krivo-chatflow.php · uninstall.php · readme.txt · LICENSE
├── includes/**  ·  templates/**
├── build/**                         # JS/CSS compilados + *.asset.php
├── languages/*.pot
└── src/** · package.json · package-lock.json · webpack.config.js      # fonte dos bundles
```

Fora do ZIP: `tests/`, `bin/`, `docs/`, `assets-wporg/` (vai ao SVN `/assets`), configs de PHPCS/PHPStan/PHPUnit, `composer.*`, qualquer arquivo oculto, `node_modules/`, `vendor/`, `.github/`, `CLAUDE.md` e diretórios de IA.
O ZIP é **sempre** gerado por esse script — nunca “zipar a pasta do repositório”.
