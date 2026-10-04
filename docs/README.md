# Krivo ChatFlow — Documentação de arquitetura

> **Fase 0 — arquitetura e plano.** Nenhuma linha de código do plugin foi escrita ainda.
> Estes documentos respondem ao pedido: *"antes de escrever o código, faça uma revisão técnica
> do prompt e apresente uma arquitetura que considere TODOS os requisitos"*.
>
> Data: 2026-10-04 · Status: **aguardando aprovação das decisões em aberto** (`06`, seção 7).

## Como ler

| # | Documento | Responde a |
|---|-----------|-----------|
| 01 | [Revisão técnica do prompt](01-revisao-do-prompt.md) | O que foi verificado, conflitos entre requisitos, decisões tomadas, melhorias |
| 02 | [Arquitetura do núcleo](02-arquitetura-nucleo.md) | Camadas, diretórios, convenções, banco de dados, motores (condições, variáveis, roteamento), pipeline de envio, antispam |
| 03 | [Automations e integrações](03-automations-e-integracoes.md) | Triggers, actions, fila, retentativas, webhooks, e-mail/SMTP, segredos, hooks, contrato com o futuro Pro |
| 04 | [Frontend, Admin e API](04-frontend-admin-api.md) | REST público e admin, capabilities, widget (carregamento, performance, a11y, eventos), app administrativo |
| 05 | [Segurança, WordPress.org e qualidade](05-seguranca-wporg-qualidade.md) | Modelo de ameaças, conformidade por design, regressões do Krivo Forms, testes, checklist de release, build |
| 06 | [Plano de desenvolvimento](06-plano-de-desenvolvimento.md) | Escopo por fase (MVP / 1.0 / depois), marcos com critérios de saída, riscos, decisões em aberto |

## Resumo executivo (uma página)

**O que é.** Uma camada entre visitante e atendimento: um balão flutuante que abre um fluxo de
qualificação (formulário, multi-etapas ou conversacional), grava o lead no WordPress e dispara
**Automations** (e-mail, webhook, WhatsApp, status), com **roteamento** por regras.
Diferença para o Krivo Forms: *widget de atendimento + roteamento + WhatsApp*, não formulário embutido em página.

**Decisões estruturais**

1. **Plugin WordPress nativo**, não um SaaS React dentro do WP. PHP orientado a módulos
   (`Krivo\ChatFlow`), REST própria (`krivo-chatflow/v1`), 4 tabelas próprias, WP-Cron para a fila.
2. **Frontend em JS puro, sem jQuery, com lazy-load**: servidor imprime o balão fechado; um
   *loader* de ~4 KB gz carrega o painel só na primeira intenção (hover/foco/toque/clique).
   Orçamento de performance é verificado por script no release.
3. **Admin em React com os pacotes do próprio WordPress** (`@wordpress/scripts`, dependências
   externalizadas) apenas dentro das telas do ChatFlow; nada global no wp-admin.
4. **Um motor por responsabilidade**: um motor de *condições* (visibilidade, saltos, roteamento),
   um de *variáveis/templates*, um de *Automations* (triggers → actions), um de *webhooks* (com presets
   n8n/Make/Zapier sobre a mesma engine). Fixtures compartilhadas garantem paridade PHP ↔ JS.
5. **Falha externa nunca quebra o visitante**: lead salvo primeiro; e-mail e webhook vão para uma fila
   (`runs`) com 3 tentativas e *backoff*; payload reconstruído do lead (sem duplicar dados pessoais).
6. **Segredos** (senha SMTP, tokens, secrets de webhook): criptografados com libsodium, fora do JSON
   dos fluxos, nunca devolvidos por REST/HTML/JS/log; opção de definir via constante no `wp-config.php`.
7. **Pro depois, sem trava hoje**: o Pro será um *plugin separado* que se registra em registries e
   filtros documentados. O build do WordPress.org não contém código bloqueado nem cotas (diretriz 5).
8. **Conformidade como teste**: cada problema do Krivo Forms vira uma verificação automática que
   falha o build (seção 4 do doc 05), incluindo regras extraídas do código-fonte do Plugin Check 2.1.0.

**O que mudou em relação ao prompt (detalhes no doc 01)** — os pontos que você precisa conhecer:

- `BUILD.md`, `EXTERNAL-SERVICES.md` e `WORDPRESS-ORG-COMPLIANCE.md` ficam em `docs/` (repositório) e **não na raiz do ZIP**:
  o Plugin Check sinaliza `.md` inesperado na raiz. O conteúdo que o revisor precisa ler vai dentro do `readme.txt`.
- `Tested up to` **só** no `readme.txt` (não no cabeçalho PHP) e o `readme.txt` deve estar em **inglês padrão**.
- O ícone “WhatsApp” do balão **não será o logo do WhatsApp** (marca registrada); haverá um glifo próprio.
- `#A14DFF` com texto branco dá **4,18:1** (reprova AA para texto normal). Textos e botões usam `#7B2FF7` (5,85:1).
- O repositório GitHub hoje é **privado**; o WordPress.org exige código-fonte público dos bundles.
- Analytics de “visualizações” **não** grava um evento por visita: contadores agregados por dia, deduplicados por sessão.

**Limite da pesquisa.** `developer.wordpress.org`, `wordpress.org` e `make.wordpress.org` estão bloqueados
neste ambiente. As regras citadas vêm do **código-fonte do Plugin Check 2.1.0** e do **core WordPress 7.1.2**
(lidos direto do GitHub) e de buscas web (só títulos das diretrizes). O texto completo das
Detailed Plugin Guidelines **não foi relido** — a matriz de conformidade é provisória até o marco M7.
