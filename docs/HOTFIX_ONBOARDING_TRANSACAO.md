# RS Connect — Hotfix: Configuração Expressa, bloco 2 "Como atender"

## Sintoma

Ao salvar a operação pelo formulário `/onboarding/operation`, a interface mostrava:

> Não foi possível salvar a configuração do atendimento: There is no active transaction

## Causa reproduzida por inspeção

`OnboardingGuideService::saveBusinessOperation()` abre uma transação e chama vários métodos de salvamento. `saveAttendance()` / `saveAgenda()` / `saveAgentLimits()` chamam `saveStep()`, que, por sua vez, chamava `ensureTables()`. Antes da correção, `ensureTables()` executava **dois comandos `CREATE TABLE IF NOT EXISTS` em toda chamada, mesmo com as tabelas presentes**.

No MySQL, `CREATE TABLE` / `ALTER TABLE` ocasionam um **COMMIT implícito**, inclusive quando a tabela já existe. Isso encerrava a transação de `saveBusinessOperation()` antes do `commit()` final, gerando a mensagem observada e permitindo gravações parciais.

O caminho auxiliar `ImplementationChecklistService::updateItem()` também poderia executar `CREATE TABLE` caso o checklist faltasse.

## Correção implementada

- **Preflight de schema antes de `beginTransaction()`**: valida/prepara `tenant_onboarding_progress`, `tenant_onboarding_events`, `tenant_onboarding_settings` e `tenant_pre_schedule_settings`, incluindo colunas legadas opcionais e checklist auxiliar.
- `ensureTables()` **não executa DDL se as tabelas já existem**.
- `ensureOnboardingSettingsTable()`, `ensurePreScheduleTable()` e `addColumnIfMissing()` **rejeitam DDL dentro de transação**, com erro explicativo sobre migrations.
- `ImplementationChecklistService` ganhou o método `prepareStorageForWrite()`, chamado antes da operação; se a tabela de checklist for requisitada dentro de uma transação sem prévia preparação, a guarda impede DDL e o código existente trata o checklist como complementar.
- Antes do `commit()`, `saveBusinessOperation()` confirma que a transação continua aberta.
- A auditoria **complementar** do controller passa a ocorrer fora do bloco que reporta sucesso/erro do salvamento: uma falha nela não diz falsamente que a configuração principal falhou. O histórico de `tenant_onboarding_events` já é registrado na transação principal.
- Nenhuma regra de triagem, aprovação, capacidade ou horário foi modificada.

**Esta entrega não cria migrations novas**. No ambiente atual, o pré-voo preserva o comportamento legado de criar estruturas ausentes, mas o ideal para produção é executar todas as migrations pela rotina de deploy antes de aceitar tráfego.

## Arquivos modificados

- `app/Services/OnboardingGuideService.php`
- `app/Services/ImplementationChecklistService.php`
- `app/Controllers/OnboardingController.php`
- `tests/Feature/onboarding-operation-transaction-guard-regression.php` (novo)

## Instalação

1. Salve backup dos arquivos atuais **e do banco MySQL**.
2. Aplique os 3 arquivos PHP do patch nos caminhos equivalentes da VPS (sem apagar `.env`, uploads ou outros diretórios).
3. Limpe cache/opcache do PHP-FPM conforme sua infra, se necessário.
4. Rode na raiz do projeto:

   ```bash
   php -l app/Services/OnboardingGuideService.php
   php -l app/Services/ImplementationChecklistService.php
   php -l app/Controllers/OnboardingController.php
   php tests/Feature/onboarding-operation-transaction-guard-regression.php
   php tests/Feature/assisted-onboarding-simplified-v36442-smoke.php
   php tests/Feature/express-onboarding-phase1-smoke.php
   ```
5. Em **homologação com MySQL/InnoDB**, teste um tenant com agendamento por equipe e outro com confirmação automática. Verifique também 1 e 4 pessoas por horário, coleta antes da agenda, horário fora do expediente, SLA e reversão quando uma gravação falha no meio. Somente depois publique.
6. Como a falha anterior permitia **gravação parcial**, confira os valores persistidos do tenant afetado antes de salvar novamente. O print sozinho não revela quais valores já foram aplicados.

### Consultas de conferência (somente leitura)

Substitua `:tenant_id` pelo ID correto da empresa em seu cliente SQL.

```sql
SELECT step_key, status, updated_at
  FROM tenant_onboarding_progress WHERE tenant_id = :tenant_id;
SELECT business_timezone, business_hours_json, calendar_mode
  FROM tenant_onboarding_settings WHERE tenant_id = :tenant_id;
SELECT enabled, require_human_approval, ai_can_confirm
  FROM tenant_pre_schedule_settings WHERE tenant_id = :tenant_id;
SELECT field_key, active, required_before_schedule
  FROM tenant_triage_fields WHERE tenant_id = :tenant_id ORDER BY position;
SELECT enabled, internal_availability_strategy, default_slot_capacity
  FROM tenant_calendar_availability_settings WHERE tenant_id = :tenant_id;
```

As queries são exemplos para ambientes em que as migrations relevantes já estão aplicadas. Não execute atualizações diretas para "consertar" o estado sem comparar as informações exibidas na plataforma.

## Testes executados no ambiente de desenvolvimento

- Lint PHP para os arquivos modificados: passou.
- `tests/Feature/onboarding-operation-transaction-guard-regression.php`: passou. O teste usa um **PDO simulado** e impede que `CREATE TABLE`/`ALTER TABLE` sejam executados depois de `BEGIN`; não substitui teste com banco real.
- `tests/Feature/assisted-onboarding-simplified-v36442-smoke.php`: passou.
- `tests/Feature/express-onboarding-phase1-smoke.php`: passou.
- `tests/Feature/onboarding-handoff-action-v36341-smoke.php`: passou.
- `tests/Feature/calendar-internal-workflow-v36373-smoke.php`: passou.

**Limitação:** neste ambiente não há `pdo_mysql`, credenciais MySQL nem integração WhatsApp. Não afirmar que a correção está homologada em produção sem testes de ponta a ponta na VPS/homologação.
