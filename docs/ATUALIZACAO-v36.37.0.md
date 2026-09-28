# RS Connect 36.37.0 — Motor genérico de atendimento

## Objetivo

Remover travas específicas de psicologia do fluxo conversacional e permitir que cada empresa defina o atendimento real — psicologia, nutrição, fisioterapia, clínica, estética ou outros segmentos — sem regras ocultas obrigando perguntas que não fazem sentido.

## Alterações principais

1. **Ordem do atendimento como fonte de verdade**
   - Cada etapa de coleta pode ter suas próprias informações marcadas/desmarcadas.
   - Informações posicionadas antes da primeira ação `calendar.*` são sincronizadas como obrigatórias antes da agenda.
   - Novas perguntas adicionadas ao workflow continuam sendo respeitadas pelo runtime.

2. **Triagem administrativa editável**
   - A etapa pode conter somente os campos que a empresa deseja.
   - Os campos não ficam mais presos à composição original do blueprint.

3. **Forma de atendimento genérica**
   - **Não se aplica**: não pergunta online/presencial e a agenda aceita modalidade indefinida.
   - **Uma única forma**: registra automaticamente Online ou Presencial e não pergunta ao contato.
   - **Mais de uma forma**: mantém a escolha como dado coletável quando realmente necessário.

4. **Normalização do agente**
   - Se a configuração já determina a modalidade, o campo `modality` é removido da triagem efetiva em memória.
   - O prompt operacional informa explicitamente à IA quando ela não deve perguntar modalidade.
   - O pré-agendamento só retorna `modality_required` quando a empresa está configurada para escolha.

5. **Agenda / n8n**
   - `CalendarAvailabilityService` aceita consultas sem modalidade quando ela não se aplica.
   - Callbacks filtram por modalidade apenas quando uma modalidade efetiva foi definida.
   - O template `template-agenda-google-eventos-vago.json` acompanha o novo contrato.

## Compatibilidade

Não exige nova migration. A migration mínima continua sendo `120_agent_turn_state_cursor.sql`. Configurações antigas são convertidas em memória: duas modalidades habilitadas = escolha; somente uma habilitada = modalidade única.

## Testes adicionados/ajustados

- `tests/Feature/agent-generic-workflow-v36370-smoke.php`
- `tests/Feature/calendar-modality-before-availability-smoke.php`
- regressões de ordem configurada e contrato de resposta atualizadas para declarar explicitamente o cenário de modalidade escolhida.
