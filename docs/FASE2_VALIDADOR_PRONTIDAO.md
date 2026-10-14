# RS Connect — Fase 2: Validador de Prontidão

## Decisão de arquitetura

Esta versão do projeto é **PHP 8.2 com PDO, MySQL, PSR-4 e Router próprio**, não uma aplicação Laravel/Eloquent. Implementamos **as mesmas separações de responsabilidades** do desenho solicitado sem importar componentes Laravel que não existem no ZIP. É possível migrar as consultas do `AgentReadinessService` para repositories Eloquent quando o sistema adotar Laravel.

O código não chama LLM, não executa DDL durante requisições e não aceita SQL ou textos livres como comandos de Auto-Fix.

## Arquivos

- `app/Services/AgentReadinessEvaluator.php`: 10 checagens e peso fixo, avaliador puro.
- `app/Services/AgentReadinessService.php`: leitura tenant-scoped no banco (incluindo agente, WhatsApp, credencial, perfil, capacidades, triagem, workflow, políticas, configuração da agenda).
- `app/Services/AgentAutoFixService.php`: comando allowlist com `SELECT ... FOR UPDATE`, `BEGIN/COMMIT/ROLLBACK` e reauditoria na mesma transação.
- `app/Controllers/AgentReadinessController.php`: HTTP JSON, erros controlados e autorização pela sessão.
- `app/Core/Router.php`: suporte adicional a rotas com `{id}` sem modificar rotas existentes.
- `routes/web.php`: endpoints protegidos.
- `app/Controllers/AgentController.php`: proteção para habilitar respostas no cadastro e atualização.
- `app/Controllers/InstanceController.php`: proteção na edição técnica do agente.
- `app/Services/OnboardingGuideService.php`: permite diagnósticos durante o wizard após aceite de privacidade.
- `tests/Feature/agent-readiness-phase2-smoke.php`: testes determinísticos sem acesso à rede/banco.

## API

Autenticação: sessão web RS Connect; tenant ID **exclusivamente** da sessão. O ID do agente é o UUID público retornado por `PublicId::encode('agent', $id)`, **não** o ID inteiro interno. Restringir permissões: `agents.view` para consulta; `agents.manage` + CSRF para alteração.

```http
GET /api/v1/agents/{agent_uuid}/readiness
```

Também há alias **GET** `/api/v1/agent/{agent_uuid}/readiness` para o contrato singular original.

Resposta 200 (exemplo ilustrativo):

```json
{
  "agent_id": "<UUID público real>",
  "status": "CONFIGURING",
  "readiness_score": 88,
  "can_operate": false,
  "checklist": [
    {
      "category": "routing_and_schedule",
      "item": "Ação fora do expediente",
      "status": "error",
      "severity": "critical",
      "message": "Defina uma ação estruturada e uma resposta fora do horário.",
      "auto_fix_available": true,
      "auto_fix_action": "set_default_out_of_hours_action"
    }
  ]
}
```

O exemplo apresenta apenas **um item**; em execução real `checklist` contém os dez diagnósticos. O score é a soma dos pesos dos itens aprovados (100 no total). Avisos `high` diminuem score, mas não bloqueiam: **qualquer `error`+`critical` impede `can_operate`, mesmo que o score seja 96 ou 88**.

```http
POST /api/v1/agents/{agent_uuid}/autofix
Content-Type: application/json
X-CSRF-TOKEN: <token da sessão>

{"auto_fix_action":"set_default_out_of_hours_action"}
```

Resposta 200:

```json
{"applied":true,"action":"set_default_out_of_hours_action","readiness":{ "agent_id":"<UUID>", "status":"CONFIGURING", "readiness_score":100, "can_operate":true, "checklist":[] }}
```

O exemplo usa uma `readiness` abreviada e **não** representa retorno completo real. O relatório completo reaparece em `readiness` após a correção.

Erros: `400` JSON inválido; `403` sessão sem empresa; `404` UUID inválido/agente de outra empresa; `409` ação desatualizada/não oferecida pela auditoria; `413` acima de 4 KiB; `422` nome da ação inválido; `500` indisponibilidade técnica. Autenticação/CSRF e permissão são tratados pelos middlewares nativos; podem redirecionar em sessões expiradas.

### Pesos dos itens (total 100)

| Verificação | Peso | Severidade em falha |
| --- | ---: | --- |
| Perfil/blueprint | 14 | critical |
| WhatsApp conectado, recepção e webhook | 18 | critical |
| Credencial de IA ativa | 14 | critical |
| Horário/fuso operacional | 12 | critical |
| Ação/política fora do expediente | 12 | critical |
| Perguntas de triagem | 6 | critical |
| Workflow, dependências e ordenação | 8 | critical |
| Agenda/disponibilidade e aprovação humana | 8 | critical |
| Política comercial e preço | 4 | critical se houver conflito; high warning se não configurada |
| Capability/link HTTPS | 4 | high warning |

**Status de resposta:** `CONFIGURING` se há erros críticos; `READY_TO_OPERATE` se passou mas está sem resposta automática; `ACTIVE` apenas se passou e `ai_agents.status='active'` e `auto_reply_enabled=1`.

## Auto-Fix disponível

| Ação | Atualizações determinísticas | Efeito na operação |
| --- | --- | --- |
| `set_default_out_of_hours_action` | `tenant_agent_profiles.config_json`, `ai_agents.after_hours_message`, `tenant_onboarding_settings.after_hours_message`, `tenant_agent_policies(express.out_of_hours)` | Aplica captura de lead com promessa de contato; **não cria horários** nem liga automações |
| `disable_meeting_link_capability` | Desabilita `calendar.confirmation_link` nas capabilities/JSON e a ferramenta `calendar.confirmed.meeting_link`; ajusta apenas trecho fixo do prompt compilado | Não envia link em confirmações; **não apaga URLs salvas** nem altera agendamentos |

- Só oferece correção se o perfil Express estiver presente e a ação puder resolver a falha.
- Revalida o checklist com a linha do agente e perfil bloqueados (`FOR UPDATE`).
- Ações desconhecidas ou antigas retornam erro sem alterar registros.
- Nunca faz `CREATE`, `ALTER` ou `TRUNCATE` dentro de transações.
- Toda ação concluída recebe registro `agent.readiness_autofix` em `audit_logs`.
- A correção **não marca o agente como ativo**.

## Ativação

- `AgentController::store()` mantém o novo agente cadastrado sem `auto_reply_enabled` quando foi solicitada ativação sem prontidão.
- `AgentController::updateStatus()` e `InstanceController::updateAgent()` interrompem com rollback a tentativa de ativação quando houver erros críticos.
- Alterações feitas em outros sistemas diretamente no banco e agentes **já ativos antes da instalação** não são desativados automaticamente. Esta fase protege os caminhos de ativação mencionados, **não implementa um veto global no webhook a cada mensagem**. Para garantia contínua de prontidão, implantar um gate com cache e fallback seguro no runtime, monitorar drift de configuração e homologar o impacto em fila fora de horário, agenda e instâncias preexistentes. Não habilite o produto para clientes novos sem essa homologação operacional.

## Instalação segura

1. Fazer backup dos arquivos e banco de dados.
2. Confirmar que migrações anteriores estão aplicadas (`055`, `060`, `103`, configurações de pré-agendamento e agenda). **Não há nova migration** nesta Fase 2.
3. Aplicar patch ou versão completa; invalidar OPcache/reiniciar PHP-FPM quando aplicável.
4. Entrar como usuário com `agents.view` e consultar o endpoint para um agente do tenant; testar outro tenant (404).
5. Testar modo CONFIGURING e a correção fora do horário; conferir banco antes/depois.
6. Testar link vazio ativo: warning + botão; após auto-fix, link desativado sem envio indevido.
7. Testar ativação com pendência crítica via `/agents/status` e `/instances/agent-update` e verificar rollback da configuração.
8. Confirmar que fila fora do horário, reagendamento, aprovação humana, confirmação, multicanais e disponibilidade continuam íntegros.

### Testes locais disponíveis

```sh
php tests/Feature/agent-readiness-phase2-smoke.php
php tests/Feature/express-onboarding-phase1-smoke.php
php tests/Feature/onboarding-operation-transaction-guard-regression.php
```

No ambiente de geração do pacote, a extensão `pdo_mysql` e um banco MySQL operacional não estão disponíveis; portanto **nenhuma homologação transacional real com MySQL, Evolution API, WhatsApp ou Google Calendar foi executada**.
