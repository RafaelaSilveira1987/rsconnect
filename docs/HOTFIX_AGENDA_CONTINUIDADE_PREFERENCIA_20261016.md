# RS Connect — Hotfix da preferência de agenda em múltiplas mensagens (16/10/2026)

## Problema reproduzido

No WhatsApp, o cliente informou a data 17/10 e depois o período **pela manhã**. O assistente continuou pedindo dia e horário e não chamou a grade de disponibilidade da Etapa 5. O problema ocorria antes da exibição da grade.

## Causas encontradas no código

1. `AgentTriageService::hasSchedulePreference()` não reconhecia datas numéricas (`17/10`) como preferência estruturada.
2. `AgentTriageService::extractDeterministic()` sobrescrevia `preferred_schedule` com a última resposta parcial, perdendo data anterior.
3. `PreSchedulingService` exigia dia + horário/período para iniciar a consulta, inclusive quando o cliente só precisava conhecer as opções do dia.
4. Uma preferência só de período podia usar uma data padrão em `periodFromIntent()` caso não houvesse contexto anterior.
5. A checagem preliminar de sobreposição de compromisso tratava “manhã” como horário exato fictício (09:00), antes da busca da grade.

## Mudanças incluídas

- Novo `SchedulingPreferenceContextService`: mescla data, dia, período e horário de turnos distintos, sem usar texto livre da LLM como autoridade transacional.
- Captura de datas numéricas na triagem e preservação da preferência entre mensagens.
- Combinação da preferência recebida com um pré-agendamento pendente da mesma empresa/conversa.
- Consulta de um dia informado sem exigir a escolha prévia de um horário específico, respeitadas as etapas obrigatórias e a modalidade configurada.
- Mudança de dia sem horário não transfere uma hora exata anterior para uma nova reserva automática.
- A verificação antecipada de conflito por horário exato não impede a navegação pela grade por período; a reserva continua submetida às validações de disponibilidade e capacidade.
- Consulta de outro dia invalida o resultado anterior quando aplicável, em vez de manter uma lista de opções desatualizada.

## Segurança e limites

Este hotfix **não** libera confirmação automática, cancelamento ou reagendamento. Não cria vagas, não altera a capacidade e não remove as restrições de triagem, aprovação humana, horário operacional, modalidade e antecedência. A busca pode resultar em ausência de vagas válidas após a verificação real.

A consulta do dia inteiro continua submetida às regras `CalendarAvailabilityService`, `InternalCalendarSlotService` e ao filtro da modalidade. A seleção e pré-reserva permanecem transacionais. Se a empresa exige informações adicionais antes de consultar a agenda, o agente precisa coletá-las primeiro.

## Arquivos de aplicação

- `app/Services/SchedulingPreferenceContextService.php` (novo)
- `app/Services/AgentTriageService.php`
- `app/Services/PreSchedulingService.php`
- `tests/Feature/agenda-date-period-continuation-20261016-smoke.php` (novo)

Sem novas tabelas, índices ou migrations.

## Implantação

1. Faça backup dos arquivos de aplicação e do banco.
2. Aplique o patch sobre a versão que **já inclui Agenda Etapa 5**.
3. Valide os PHPs com `php -l` e reinicie o contêiner PHP/OPcache se necessário.
4. Teste com uma conversa nova em uma empresa de homologação (um fluxo antigo pode ter respostas já guardadas de maneira incorreta).
5. Caso de teste: `Gostaria de agendar uma consulta para o dia 17/10` -> resposta à triagem -> `pela manhã`. O agente deve consultar as vagas reais do dia e período, sem repetir a solicitação genérica de data.
6. Teste adicional: solicite `17/10` sem período; caso não haja outros campos obrigatórios, deve iniciar consulta diária. Teste `18/10` depois de uma consulta anterior e verifique nova busca.
7. Confirme que o sistema **não** confirma uma reserva sem autorização e que horários indisponíveis não recebem número selecionável.

## Testes executados localmente

- Testes puros de composição de preferências (sem MySQL): aprovados.
- `triage-agenda-two-scenarios-regression-smoke.php`: aprovado.
- `agent-turn-workflow-and-calendar-isolation-v363623-smoke.php`: aprovado.
- `calendar-before-ai-guard-v362718-smoke.php`: aprovado.
- `agent-demand-single-source-v36372-smoke.php`: falha preexistente de expectativa de desativação em massa de campos no Blueprint; esta mudança não altera essa rotina.
- Sintaxe PHP: aprovada.

**Pendente:** homologação com MySQL real, Evolution/WhatsApp e diferentes configurações de fluxo. Os testes unitários não garantem comportamento idêntico da LLM quando uma etapa de triagem adicional estiver configurada.
