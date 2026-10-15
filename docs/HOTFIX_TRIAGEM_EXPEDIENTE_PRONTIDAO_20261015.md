# RS Connect — Correção de triagem, expediente e prontidão (15/10/2026)

**Base exata:** `rs-connect-unificacao-configuracoes-etapa1-completo.zip`. PHP 8.2+, PDO/MySQL. Não altera schema nem exige migration.

## Diagnóstico reproduzido

1. Campos com `active=1` e `required_before_schedule=1` permaneciam sem `collect` ativa no `tenant_agent_workflow_steps`; o `AgentRuntimeConfigurationService` rejeitava a ativação, e o painel mostrava 7 erros. Uma implementação anterior da Ativação Assistida reunia todas as perguntas na primeira etapa e desativava outras, interferindo na edição avançada.
2. O formulário simplificado de expediente era montado diretamente de JSON `days/start/end`, mas o formato operacional também aceita intervalos por dia (`mon => [[08:00,12:00],[13:00,17:00]]`). Essa UI não conseguia representar tais horários e podia sobrescrevê-los.
3. Os scores de 92% e 94% mostram diagnósticos diferentes da *configuração efetivamente persistida*. Nota alta não significa autorização quando há falha crítica.

## Correções

- `WorkflowTriageIntegrityService`: planejador puro de coletores faltantes/tardios e reparador transacional. Usa as **mesmas tabelas de runtime**, não interpreta prompt nem cria campos fictícios. Preserva a ordem relativa de etapas existentes; uma etapa nova é criada para perguntas obrigatórias sem nenhum coletor, antes da ação de agenda.
- `OnboardingGuideService::syncAssistedTriageAndWorkflow()`: não substitui mais a configuração de todos os coletores pelo conteúdo da primeira etapa e não desativa coletoras especializadas ao salvar o onboarding. Reconcilia os campos selecionados, e vincula os obrigatórios antes da agenda; mantém pré-agendamento e aprovação humana inalterados.
- `AgentBlueprintService::syncWorkflowScheduleRequirements()`: elimina a desativação em massa de todos os campos ao salvar o editor avançado. A exclusão explícita de etapas continua em `deleteWorkflowSteps()`. Campos ativos sem coletor continuam visíveis como pendência, e não são desligados silenciosamente.
- `AgentReadinessEvaluator` e `AgentAutoFixService`: permitem explicitamente a ação `restore_required_triage_steps` quando a auditoria encontrou perguntas já cadastradas, com texto válido, mas sem etapa ativa ou posicionada corretamente. A ação requer autenticação, permissão `agents.manage`, CSRF e confirmação pela interface. O serviço obtém lock, reavalia antes/depois e faz rollback quando o problema recuperável persistir.
- `BusinessHoursCompactEditorService`: o onboarding exibe os horários vindos da configuração operacional, sem assumir o valor padrão quando ela usa o formato detalhado. Horários com turnos múltiplos ou diferentes por dia não são editáveis nesse painel simples; o usuário é direcionado à Configuração Expressa.
- O backend impede que o POST simplificado substitua silenciosamente janelas personalizadas e evita reconfigurar a disponibilidade interna quando não consegue representá-la. Nenhuma reserva é criada, removida ou reagendada pelo hotfix.
- Revisado o indicador de agenda habilitada e a inicialização da capacidade no template PHP.

## Como aplicar

1. Faça **backup validado** de arquivos e banco MySQL. Aplique primeiro em uma empresa de homologação.
2. Instale o patch sobre **exatamente** a versão de unificação da Etapa 1; se a instalação divergir, compare as personalizações e use o pacote completo em homologação.
3. Atualize arquivos mantendo a estrutura de diretórios; limpe OPcache e atualize `/agents` com recarga forçada. Os JS alterados têm novos `rev`.
4. Na **Ativação Assistida**, confirme as informações que devem ser coletadas e salve. Em Meu Atendimento, consulte a pontuação. Se houver “Execução do fluxo e políticas” com `Corrigir automaticamente`, revise a operação e confirme.
5. Confira em **Ordem do atendimento** a sequência das perguntas **antes** de “Consultar disponibilidade real”; salve, atualize e confira de novo.
6. Confirme, na Ativação Assistida e em Meu Atendimento, o **mesmo horário operacional**, sabendo que **a agenda de consultas tem disponibilidade própria**. Para horários diferentes por dia, use o editor completo de intervalos.
7. Teste: contato novo, pergunta de demanda, consulta de vaga, aprovação humana, mensagem fora do expediente, cancelamento/reagendamento somente de teste. Confirme que nenhum agendamento real foi criado durante a simulação.

## Limitações e segurança

- O reparo **não é automático para empresas antigas**: só altera os coletores por escolha explícita da pessoa autorizada. Salvar novos campos na Ativação Assistida é uma escolha explícita de configuração, e aí o vínculo é aplicado como parte da mesma transação.
- A ação de auto-fix nunca inventa a pergunta de um campo vazio; nesse caso, o diagnóstico continuará pedindo preenchimento manual.
- Caso o campo esteja `active=0`/desmarcado, **marque-o na configuração**; não é seguro reativar automaticamente dados intencionalmente desabilitados.
- O validador `can_operate` permanece bloqueado se houver falhas críticas, mesmo após reparar o workflow.
- A tela avançada de horários **por agente** ainda pode ter sobrescritas específicas; a conciliação global com a Agenda é uma etapa futura, não concluída aqui.
- Testes locais: sintaxe PHP/JS e smoke tests com planejador puro, Readiness e regressões. **Não houve integração real com MySQL, WhatsApp nem Google Calendar**, pois esses serviços não estão disponíveis no ambiente. Testar transações e dados legados na homologação.

## Compatibilidade de testes antigos

O teste estático `tests/Feature/agent-demand-single-source-v36372-smoke.php` ainda espera, literalmente, que o editor avançado desative TODOS os campos fora do workflow. Essa expectativa antiga é incompatível com a unificação entre telas e **falha por escolha consciente**. Não se alterou esse teste para ocultar a diferença: a nova verificação `triage-and-hours-consistency-oct2026-smoke.php` testa explicitamente que não exista mais a desativação em massa.
