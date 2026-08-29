# RS Connect — v36.23.0

## Notificações automáticas de agenda e orçamento

- adiciona regras configuráveis por empresa em **Notificações > Notificações de agenda e orçamento**;
- cria avisos internos imediatos para novos agendamentos, confirmações, cancelamentos, remarcações e pedidos de orçamento;
- envia WhatsApp para o responsável ou equipe pelo número configurado;
- agenda lembretes antes do compromisso e escalonamento de orçamento atrasado;
- usa fila persistente com deduplicação, quatro tentativas e retomada após falhas transitórias;
- cancela automaticamente lembretes e alertas que perderam validade;
- impede envio para o próprio número conectado da Evolution;
- exibe contadores de pendentes, novas tentativas, enviados e falhas;
- permite processar a fila manualmente para homologação.

### Atualização do banco

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration adicionada:

```text
database/migrations/092_notification_orchestration.sql
```

### Processamento da fila

Configure uma tarefa no EasyPanel para executar a cada minuto:

```bash
php /var/www/html/bin/process-notifications.php
```

Alternativa HTTP:

```text
GET /webhooks/notifications/process?token=SEU_TOKEN
```

Variável necessária para a alternativa HTTP:

```env
NOTIFICATION_CRON_TOKEN=troque-por-um-token-longo-e-aleatorio
```

# RS Connect — v36.22.1

## Hotfix de recebimento Evolution/PDO

- corrige o HTTP 500 `SQLSTATE[HY093]: Invalid parameter number` no webhook da Evolution;
- permite que eventos `contacts.upsert`, `contacts.update` e `messages.upsert` sejam processados e salvos;
- remove reutilização de placeholders nomeados incompatível com PDO nativo;
- inclui proteção de regressão para outras consultas estáticas;
- não adiciona migration.

# RS Connect — v36.22.0

## Monitor pós-horário e orçamentos pendentes

- adiciona uma rotina dedicada, visível e configurável para retomar conversas preservadas depois da reabertura do expediente;
- oferece execução manual, status da última rodada, quantidade pendente, trava contra concorrência e comando CLI para agendamento no EasyPanel;
- identifica pedidos diretos de orçamento e confirmações contextuais como “sim, por favor” após a oferta da IA;
- cria uma única pendência comercial por conversa, tarefa no CRM, alerta na conversa, notificação interna, tag no contato e contador no dashboard;
- permite definir responsável, prazo útil, etapa do funil e modo de movimentação do card;
- mantém o recurso comercial desativado por padrão para cada empresa.

### Atualização do banco

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration adicionada:

```text
database/migrations/091_after_hours_monitor_and_quote_requests.sql
```

### Agendamento no EasyPanel

Crie uma tarefa agendada no serviço da aplicação, preferencialmente a cada 5 minutos:

```bash
php /var/www/html/bin/ai-after-hours-recovery.php
```

O intervalo efetivo é controlado em **Saúde do sistema > Fila/IA > Retomada pós-horário**. O padrão é 15 minutos e o sistema impede execuções simultâneas.

# RS Connect — v36.21.2

## Retomada automática da IA

- corrige conversas que respondiam somente uma vez e depois dependiam de reprocessamento manual;
- mantém o tempo de espera para agrupar mensagens do cliente;
- responde ao webhook da Evolution antes da tarefa lenta;
- inicia worker interno para retomar a última mensagem automaticamente;
- impede respostas duplicadas quando chegam várias mensagens durante a espera;
- não adiciona migration.

# RS Connect — v36.21.1

## Hotfix do executor de migrations

- corrige o erro MySQL `2014 Cannot execute queries while other unbuffered queries are active`;
- consome e fecha todos os resultados produzidos por `PREPARE/EXECUTE`;
- substitui os no-ops `SELECT 1` da migration 090 por `DO 0`;
- permite reaplicar com segurança a migration 090 caso a tentativa anterior tenha criado parcialmente as tabelas ou colunas;
- não exige novo baseline e não altera migrations históricas já registradas.

Para atualizar um banco que mostra a migration 090 como pendente:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

# RS Connect — v36.21.0

## Novidades

- Demonstração interativa da IA diretamente na tela de login.
- Automação comercial opcional baseada na conversa do lead.
- Modos de sugestão e movimentação automática, com confiança mínima e histórico.
- Bloqueio por card e pausa de segurança após movimentações manuais.

# RS Connect — v36.20.16

## ENT-027 / PA-005 — Normalização das migrations

- cria `schema_migrations` com sequência, checksum, lote, origem e tempo de execução;
- preserva todos os nomes históricos e resolve prefixos duplicados pelo `manifest.php`;
- adiciona executor CLI com `verify`, `status`, `install`, `baseline`, `up`, `seed` e `bootstrap`;
- centraliza a instalação Docker no serviço `migrate`;
- impede readiness quando o histórico do banco estiver incompleto;
- mantém a migration 089 aditiva, sem apagar dados comerciais.

Para atualizar o banco atual já homologado até a 088:

```bash
php bin/migrate.php verify
php bin/migrate.php baseline --through=088 --yes
php bin/migrate.php status
```

A migration obrigatória desta versão é:

```text
database/migrations/089_schema_migrations_registry.sql
```

## Histórico consolidado

# RS Connect — v36.20.15.4

## Hotfix White Label — painel, login e layout proporcional

- aplica a marca salva no painel autenticado do cliente;
- aplica logo, cores, textos e favicon no login identificado por tenant;
- corrige a prévia desproporcional;
- limita e centraliza a tela administrativa;
- preserva as proteções XSS, SVG, CSP, uploads persistentes e autoload;
- não adiciona migration.

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