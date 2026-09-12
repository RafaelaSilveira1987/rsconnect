# TESTE DA VERSÃO — RS Connect 36.32.0

## Objetivo
Validar a Fase A do roadmap de Production Readiness: separar configuração/homologação da operação oficial e garantir que SLA, métricas produtivas e cobrança manual de produção só sejam liberados após o Go-Live.

## 1. Pré-requisitos
- backup recente do banco e dos arquivos;
- aplicação 36.32.0 publicada;
- acesso Superadmin;
- pelo menos uma empresa de teste com WhatsApp/IA disponíveis;
- PHP 8.2+ e MySQL 8/MariaDB 10.6+.

## 2. Aplicar e validar a migration
Na raiz do projeto:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
```

Resultado esperado:
- migration `112_tenant_lifecycle_go_live.sql` aplicada;
- manifesto com 119 migrations;
- colunas `lifecycle_status`, `lifecycle_changed_at`, `ready_at`, `went_live_at`, `suspended_at` em `tenants`;
- tabela `tenant_lifecycle_events` existente.

Consulta opcional:

```sql
SELECT id, name, status, lifecycle_status, ready_at, went_live_at, suspended_at
FROM tenants
ORDER BY id DESC;
```

Após a migration, empresas já existentes devem aparecer inicialmente como `onboarding` até que o Go-Live seja confirmado.

## 3. Onde configurar
Acesse **RS Admin → Empresas**.

Cada empresa passa a exibir o ciclo operacional. Na **Visão geral** existe a seção **Go-Live / Ciclo operacional**.

Fluxo esperado:

`ONBOARDING → READY → LIVE → SUSPENDED`

Também é possível retornar de `LIVE` para `READY` para nova homologação e retomar de `SUSPENDED` conforme as transições apresentadas pela interface.

## 4. Cenário A — Onboarding
### Configuração
Deixe a empresa em **Onboarding**.

### Execução
1. envie uma mensagem real pelo WhatsApp;
2. deixe a IA responder;
3. responda como atendente humano;
4. se aplicável, faça um pré-agendamento;
5. abra Relatórios e consulte SLA/primeira resposta.

### Esperado
- WhatsApp funciona: **SIM**;
- IA funciona: **SIM**;
- atendimento humano funciona: **SIM**;
- agenda funciona: **SIM**;
- banner de onboarding/homologação aparece para o cliente: **SIM**;
- nova resposta humana não entra no SLA oficial: **SIM**;
- cobrança manual em **Cobranças** é bloqueada com aviso de Go-Live pendente: **SIM**.

**Aprovado quando:** é possível homologar normalmente sem contaminar as métricas oficiais.

## 5. Cenário B — Ready
### Configuração
Em **Empresas → Visão geral → Ciclo operacional**, altere de `Onboarding` para **Pronta para produção** e registre uma observação, por exemplo: `Configuração revisada; aguardando autorização do cliente.`

### Esperado
- status exibido: **Pronta para produção**;
- `ready_at` preenchido;
- banner informa que o ambiente está pronto, mas ainda não está em produção;
- SLA oficial continua pausado;
- botão/ação de **Colocar em produção** fica disponível no Superadmin;
- histórico registra `Onboarding → Pronta para produção`.

Consulta opcional:

```sql
SELECT lifecycle_status, ready_at, went_live_at
FROM tenants
WHERE id = ID_DA_EMPRESA;
```

## 6. Cenário C — Go-Live
### Configuração
Com a empresa em `READY`, clique em **Colocar em produção** e confirme a mensagem de segurança.

### Esperado imediatamente
- `lifecycle_status = live`;
- `went_live_at` preenchido;
- evento de auditoria `company.lifecycle_live` registrado;
- histórico operacional registra `ready → live`;
- banner de homologação deixa de aparecer no painel do cliente.

Consulta:

```sql
SELECT lifecycle_status, ready_at, went_live_at, lifecycle_changed_at
FROM tenants
WHERE id = ID_DA_EMPRESA;

SELECT from_status, to_status, note, changed_at
FROM tenant_lifecycle_events
WHERE tenant_id = ID_DA_EMPRESA
ORDER BY id DESC;
```

## 7. Cenário D — Métricas depois do Go-Live
### Execução
Depois do Go-Live, inicie **uma nova conversa/ciclo**:
1. cliente envia mensagem;
2. aguarde alguns segundos;
3. atendente humano responde;
4. encerre a conversa, se possível;
5. abra Relatórios com o intervalo do teste.

### Esperado
- primeira resposta humana passa a ser medida;
- ciclo encerrado passa a compor duração oficial;
- SLA passa a medir somente ciclos iniciados enquanto o tenant estava `LIVE`;
- conversas feitas no onboarding antes do Go-Live não entram retroativamente como produção.

**Importante:** teste com uma nova conversa/ciclo depois do Go-Live. Um ciclo iniciado durante onboarding permanece não produtivo para fins de SLA oficial.

## 8. Cenário E — Cobrança manual
Com a empresa em `LIVE`:
1. abra **Cobranças**;
2. crie uma cobrança manual válida.

Esperado: a cobrança pode ser criada respeitando as demais regras existentes (trial, assinatura, valor etc.).

Depois retorne a empresa para `READY` e tente novamente.

Esperado: a criação manual fica bloqueada com mensagem informando que a empresa não está em produção.

> O ciclo operacional não cancela pagamentos de checkout/trial já existentes. Ele controla a liberação da cobrança manual de produção e os indicadores oficiais; assinatura e acesso continuam sendo conceitos separados.

## 9. Cenário F — Suspensão operacional
Com a empresa `LIVE`, altere para **Operação suspensa**.

Esperado:
- `lifecycle_status = suspended`;
- `suspended_at` preenchido;
- banner de suspensão aparece para o cliente;
- histórico anterior de produção permanece disponível;
- novas esperas/SLA oficiais ficam pausadas enquanto não houver novo período `LIVE`;
- login não é bloqueado apenas pela suspensão operacional (o status de acesso da empresa continua separado).

## 10. Auditoria
Na visão geral da empresa, abra o histórico do ciclo. Também valide `audit_logs`:

```sql
SELECT action, context_json, created_at
FROM audit_logs
WHERE tenant_id = ID_DA_EMPRESA
  AND action LIKE 'company.lifecycle_%'
ORDER BY id DESC;
```

Esperado: cada transição contém origem, destino, usuário/data e observação quando informada.

## 11. Critérios para aprovar a Fase A
Marque a fase como aprovada apenas se todos forem verdadeiros:

- [ ] Onboarding permite testes sem SLA oficial.
- [ ] Ready continua sem métricas oficiais.
- [ ] Go-Live exige ação explícita do Superadmin.
- [ ] `went_live_at` é registrado.
- [ ] Conversas iniciadas após Go-Live entram nas métricas oficiais.
- [ ] Conversas de onboarding não entram retroativamente no SLA.
- [ ] Cobrança manual de produção é bloqueada fora de LIVE.
- [ ] Suspensão pausa novas métricas produtivas sem apagar histórico.
- [ ] Histórico do ciclo e Audit registram as mudanças.
- [ ] WhatsApp, IA, agenda e atendimento continuam funcionando durante homologação.

## 12. Evidência sugerida
Para cada cenário, salve:
- print do status operacional da empresa;
- print da conversa usada no teste;
- print do relatório/SLA antes e depois do Go-Live;
- print do histórico do ciclo;
- horário aproximado de cada ação.

Com essas evidências, a próxima fase (Evolution Reliability) pode ser iniciada sem misturar falha de infraestrutura com falha de regra de negócio.
