# RS Connect — Fase 1, Configuração Expressa (back-end)

## Escopo e ambiente

Código realizado sobre o ZIP de 14/10/2026. A aplicação deste ZIP é PHP 8.2+ com roteador próprio, PDO/MySQL, **não** Laravel. Os padrões de DTO, Enum, validação equivalente ao FormRequest e Services foram utilizados sem exigir dependências Laravel. Os arquivos `app/Http/` são **adaptadores de referência Laravel**, não estão expostos pelo roteador atual.

**Não publicar diretamente em produção sem backup e testes integrados.** O ambiente de análise não forneceu driver `pdo_mysql` nem `ext-mbstring` para testar persistência real; foram realizados lint PHP e smoke test de compilação/validação sem DB.

## Fluxo

`POST /api/agents/express` (somente **depois** da tela Revisar/Aplicar)

1. `auth + csrf`, tenant vem da sessão autenticada. Nunca aceitar tenant no body.
2. `ExpressOnboardingRequest::validate()` valida JSON sem conversão implícita de booleanos, enum, IANA timezone, preços BRL, horários sem sobreposição, FAQ e link HTTPS.
3. DTO `ExpressOnboardingData` readonly com Enum `ExpressSegment` / `ExpressTone`.
4. `ExpressOnboardingService` faz lock em `tenants` (`SELECT ... FOR UPDATE`), lê versão publicada, chama `AgentBlueprintService::compileExpress()`, que delega ao `ExpressBlueprintCompiler` **puro**.
5. O serviço grava `tenant_agent_profiles`, `tenant_triage_fields`, `tenant_agent_capabilities`, `tenant_agent_policies`, `tenant_agent_workflow_steps`, vínculos em `tenants` e horário de `tenant_onboarding_settings` em **uma só transação**. Se existe um agente padrão (`ai_agents.is_default=1`), atualiza também seu nome, prompt, FAQ e horário, sem alterar `auto_reply_enabled` ou `status`.
6. `AgentRuntimeConfigurationService::audit()` verifica antes do `COMMIT` se campos obrigatórios estão ordenados antes da agenda.
7. `CalendarClientCommunicationService` consulta `ExpressConfirmationLinkService` **apenas ao formatar confirmação de compromisso com status confirmed**; somente usa HTTPS e modalidade online, priorizando `calendar_appointments.meeting_url` e fazendo fallback para o link configurado no wizard.
8. Remarcação automática é recusada por `ExistingAppointmentConversationService` e `PreSchedulingService` se `calendar.reschedule=false`; a capacidade ausente em configurações legadas mantém comportamento anterior.

## Chamada HTTP

```http
POST /api/agents/express HTTP/1.1
Content-Type: application/json
X-CSRF-TOKEN: <token da sessão RS Connect>
Cookie: <sessão autenticada>
```

Corpo igual ao contrato JSON fornecido. HTTP 200 em sucesso, 400 JSON inválido, 403 sem empresa, 413 corpo grande, 422 validação, 409 configuração/blueprint incompatível, 500 erro interno. Exemplo de resposta:

```json
{
  "ok": true,
  "data": {
    "tenant_id": 8,
    "blueprint_id": 1,
    "version_id": 1,
    "configuration_saved": true,
    "connected_agents_updated": 1,
    "audit": {"ok": true, "warnings": []},
    "requires_review": true
  }
}
```

> IDs de resposta são ilustrativos. A operação **aplica** configuração, não é um endpoint de *draft* nem de pré-visualização; deve ser acionada após revisão explícita. Em empresa já ativa, as mudanças de perfil e agente padrão podem afetar as próximas conversas imediatamente. `requires_review` aponta para o próximo passo de testes/homologação, não significa que o banco permanece inalterado. Não foram construídos o wizard visual, a tabela de rascunhos nem bloqueio geral de ativação por testes nesta entrega.

## Tabelas e compatibilidade

Todas as tabelas usadas já existem nas migrations incluídas no ZIP (`103_agent_blueprints_policy_engine.sql`, `060_free_trial_guided_first_access.sql` e AI Agents). Não são criadas migrations novas. Validar execução das migrations antes de liberar a rota.

`psychology_clinic` usa o niche `psychology`; `health_clinic` usa `health_clinic`; `beauty_salon` usa `beauty`; `general_services` usa `services`; **`real_estate` temporariamente usa o blueprint base `services`** e mantém o código específico no DTO/express config. Antes de produção imobiliária, publicar um blueprint imobiliário dedicado.

`routing_and_schedule.business_hours` é convertido para o esquema **operacional** `{ "mon": [["08:00","18:00"]] }`. **Não substitui as regras independentes de disponibilidade, capacidade por horário, feriados, profissionais ou tempo mínimo de agendamento.** Não alterar slots/agenda somente com o horário comercial.

Os campos de triagem existentes no template (por exemplo, `brief_demand` e `patient_age`) são preservados. As perguntas customizadas são indexadas por hash determinístico. O fluxo compilado cria uma etapa de coleta por campo antes da ação de agenda e mantém restrições de aprovação humana do blueprint.

`tenant_agent_profiles.status=active` é necessário para que o `AgentPolicyEngineService` **não ignore** o Policy Engine (perfis `inactive` são ignorados pelo motor atual). Essa flag não liga o agente de WhatsApp. **A mudança de política será efetiva na próxima interação para um tenant que já opera.** Portanto, rascunho/rollback isolado exige fase de staging futura.

**Limitações desta entrega:**

- `escalate_to_human_on_error` é guardado em `handoff.on_error` e refletido no workflow e no prompt. Os caminhos de exceção de todos os provedores ainda precisam ser associados a essa capability, se desejado enforcement global.
- A regra de preço fixo é persistida estruturalmente e introduzida no prompt, mas **não** existe um filtro determinístico que valide toda mensagem de saída com valores de preço. Não tomar o prompt como garantia absoluta de preço correto. Para enforcement estrito, respostas de preço devem usar função/catálogo estruturado.
- A URL padrão não é persistida automaticamente no compromisso (`calendar_appointments.meeting_url`); é aplicada durante a composição da mensagem de confirmação, quando permitido e sem criar links falsos. Compromissos precisam estar realmente confirmados.
- O endpoint só atualiza o agente padrão **existente**. Ele não cria agente sem `instance_id`/número WhatsApp associado, por segurança. Se `connected_agents_updated=0`, ainda é preciso criar/vincular o agente usando os fluxos atuais antes de operar.
- O compilador novo é puro. O `AgentBlueprintService` **legado** ainda possui métodos históricos que gravam no banco (`applyBlueprint`) e não foram removidos para não quebrar rotas antigas; o caminho express não os chama.
- Aplicar a configuração recompila campos, políticas e workflow; ajustes avançados anteriores nesses três conjuntos podem ser substituídos. Exija revisão e mantenha backup da configuração anterior.

## Validação

```bash
php -l app/Services/ExpressOnboardingService.php
php tests/Feature/express-onboarding-phase1-smoke.php
```

O smoke test inclui polyfills **apenas no teste** para este ambiente sem `ext-mbstring`. O ambiente de produção deve satisfazer `composer.json` (`ext-mbstring`, `ext-pdo_mysql`, etc.). Além disso, antes da implantação: backup MySQL, teste ponta a ponta com DB de homologação, dois tenants para isolamento, rollback, agenda indisponível, aprovação humana, fila fora do horário, mensagens confirmadas com e sem link, casos online/presencial, capacidade de múltiplos slots, e comportamento em tenant já ativo.

## Futuro Laravel (sem migração forçada)

- `app/Http/Requests/ExpressAgentFormRequest.php` usa `Rule::enum()`, validações de estrutura e repassa a regra estrita do domínio.
- `app/Http/Controllers/ExpressAgentLaravelController.php` recebe `FormRequest` + service por DI e obtém `tenant_id` somente de `Auth`.
- Em um projeto Laravel, registrar rota em `routes/api.php` com guard autenticado e proteção adequada para a autenticação escolhida. **Não registrar as duas rotas simultaneamente no projeto atual.**
- O serviço de persistência poderá passar de PDO para `DB::transaction()` / Repositories Eloquent numa migração de framework; o DTO e o compilador continuam reutilizáveis.
