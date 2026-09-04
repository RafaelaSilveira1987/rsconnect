# Validação RS Connect 36.27.15

## 1. Deploy e migration

```bash
cd /var/www/html
php bin/migrate.php up
```

Esperado: `101_agent_scheduling_specialist_routing.sql` executada uma única vez.

## 2. Teste estático da versão

```bash
php tests/Feature/agent-compact-scheduling-routing-v362715-smoke.php
php tests/Feature/multi-agent-routing-ui-v36272-smoke.php
php tests/Feature/multi-agent-round-robin-v36270-smoke.php
php tests/Feature/ai-to-ai-specialist-handoff-v36271-smoke.php
php tests/Feature/ai-whatsapp-agent-signature-v36274-smoke.php
```

Todos devem terminar com `OK`/`APROVADO`.

## 3. Confirmar Ana/agendamento no banco

```bash
php <<'PHP'
<?php
require 'bootstrap.php';
use App\Core\Database;

$pdo = Database::connection();
$rows = $pdo->query(
    'SELECT a.id, a.name, a.segment, i.name AS canal,
            b.is_primary, b.routing_keywords
     FROM ai_agent_instance_bindings b
     INNER JOIN ai_agents a ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
     INNER JOIN evolution_instances i ON i.id = b.instance_id
     WHERE b.status = "active"
       AND LOWER(TRIM(a.segment)) LIKE "%agend%"
     ORDER BY a.id, i.id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as $row) {
    echo ($row['name'] ?? '-')
        . ' | área=' . ($row['segment'] ?? '-')
        . ' | canal=' . ($row['canal'] ?? '-')
        . ' | primary=' . ($row['is_primary'] ?? '-')
        . ' | keywords=' . ($row['routing_keywords'] ?? '-')
        . PHP_EOL;
}
PHP
```

Para um agente de agendamento não principal e anteriormente sem configuração, esperado:

```text
primary=0
keywords=agendar, agendamento, marcar, remarcar, reagendar, reservar
```

## 4. Teste E2E real

Use uma conversa que esteja atualmente pinada no agente principal e envie de outro número:

```text
Quero agendar uma demonstração.
```

Validar:

- a próxima etapa de agenda é atribuída ao agente de agendamento;
- o WhatsApp identifica o agente de agendamento, e não o principal;
- `conversations.ai_agent_id` passa para o especialista;
- mensagens seguintes permanecem no especialista até nova regra de handoff ou troca manual.

## 5. Tela com múltiplos agentes

Com três ou mais agentes:

- nenhum card deve ficar cortado lateralmente;
- cards devem permanecer compactos;
- **Detalhes técnicos** inicia fechado;
- **Configurações completas** inicia fechado;
- **Configurar** abre o agente escolhido e posiciona a tela no roteamento;
- em telas pequenas, os cards devem cair para uma coluna.

## 6. Regressões críticas

```bash
php tests/Feature/operational-alert-service-contract-v362714-smoke.php
php tests/Feature/operational-commercial-n8n-v362711-smoke.php
php tests/Feature/operations-monitor-workflow-autoreactivate-v362712-smoke.php
php tests/Feature/operations-monitor-workflow-identity-v362713-smoke.php
```

As correções de monitoramento 36.27.11–36.27.14 devem continuar aprovadas.
