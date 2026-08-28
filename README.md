# RS Connect — v36.20.15.4

## Hotfix White Label — painel, login e layout proporcional

- aplica a marca salva no painel autenticado do cliente;
- aplica logo, cores, textos e favicon no login identificado por tenant;
- corrige a prévia desproporcional;
- limita e centraliza a tela administrativa;
- preserva as proteções XSS, SVG, CSP, uploads persistentes e autoload;
- não adiciona migration.

## Histórico consolidado

## Hotfix de inicialização do autoload e Router

- corrige a falha `Class App\Core\Router not found` após deploy;
- registra o autoloader antes do bootstrap;
- aceita Composer com fallback interno;
- valida o Router durante o build Docker;
- preserva o White Label e as proteções da ENT-030.

# RS Connect — v36.20.15.2

## Hotfix White Label: upload persistente e layout revisado

- corrige a criação de pasta ao enviar logo, ícone ou favicon;
- armazena novos arquivos em `storage/app/white-label`;
- entrega imagens por rota segura, sem expor o diretório físico;
- mantém PNG, JPG e WEBP e continua bloqueando SVG/ICO;
- reorganiza uploads, paleta, prévia e ações da tela;
- não adiciona migration.

## Hotfix — rotas White Label

- restaura a rota canônica `/white-label`;
- mantém compatibilidade com o endereço antigo `/white_label`;
- restaura as rotas de salvar e pré-visualizar;
- adiciona o acesso **Marca dos clientes** ao menu do Super Admin;
- mantém autenticação, CSRF e restrição de Super Admin;
- preserva integralmente as proteções XSS, SVG e CSP da v36.20.15.

Nenhuma migration nova é necessária. A migration vigente permanece `088_payment_reconciliation_schema_compat.sql`.

# RS Connect — v36.20.15

## ENT-030 — proteção contra XSS, SVG e CSP

- uploads white label limitados a PNG, JPEG e WEBP;
- SVG, ICO, HTML, JavaScript e executáveis bloqueados em `public/uploads`;
- CSP com nonce e sem `unsafe-inline` em `script-src`;
- handlers JavaScript inline removidos das views;
- proteção adicional em inserções dinâmicas de HTML;
- auditoria dos uploads públicos disponível por CLI;
- endpoint legado de saúde reduzido a `{"status":"ok"}`.

Não há migration nova. A última migration obrigatória permanece `088_payment_reconciliation_schema_compat.sql`.

## ENT-029 / PA-003 — Health checks seguros

- adiciona `/health/live` com resposta pública mínima `{"status":"ok"}`;
- adiciona `/health/ready` com HTTP 200 ou 503 sem expor detalhes internos;
- adiciona `/health/ready/details` protegido por autenticação de Super Admin;
- evita criação de sessão nos health checks públicos;
- adiciona healthcheck do contêiner apontando para o liveness;
- não cria migration nova e preserva todos os recursos da ENT-028.

## Hotfix v36.20.13.4 — rótulos em português e operação em produção

- traduz rótulos que estavam aparecendo em inglês na tela de cobrança;
- converte nomes de planos como `Starter` e `Business` para `Inicial` e `Empresarial`;
- traduz status externos como `ACTIVE`, `CREATED` e similares para PT-BR na listagem de cobranças;
- mantém o cadastro de meios de pagamento preparado para **Produção**;
- preserva os hotfixes anteriores do PagBank/PagSeguro;
- não cria migrations novas e não altera dados reais automaticamente.

Esta versão consolida a **ENT-028 / PA-002 — Blindagem dos webhooks** com os hotfixes operacionais do PagBank/PagSeguro e melhora a consistência visual do módulo financeiro.

## O que fazer após publicar

1. Abrir **Configurar meios de pagamento**.
2. Editar o cadastro do **PagBank / PagSeguro**.
3. Selecionar **Produção** no campo **Ambiente**.
4. Confirmar que o token informado é o token real de produção.
5. Salvar e gerar novamente o link.

## Migration obrigatória vigente

A última migration obrigatória continua sendo:

```text
database/migrations/088_payment_reconciliation_schema_compat.sql
```

Nenhuma migration nova foi adicionada neste hotfix.