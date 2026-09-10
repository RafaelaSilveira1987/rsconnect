# RS Connect 36.29.4 — Conversa agrupada e continuidade contextual

## Objetivo

Evitar que o assistente responda no meio de uma sequência de mensagens do WhatsApp ou ignore uma pergunta nova para seguir cegamente a próxima etapa do fluxo.

## Configuração por assistente

Em **Assistentes de IA → Configurar assistente → Conversa natural**:

- **Aguardar o cliente terminar e agrupar as mensagens antes de responder**;
- **Tempo para juntar mensagens (seg.)**;
- **Responder primeiro o que o cliente acabou de perguntar e depois retomar o roteiro**.

Recomendação inicial: 8 a 15 segundos para WhatsApp.

## Exemplo esperado

Cliente envia em sequência:

1. `ok, quero indicação.`
2. `com quem eu falo mesmo?`

O RS Connect espera o período de silêncio, trata as duas mensagens como um único turno e envia ao modelo o bloco completo. O modelo recebe também o nome público do assistente e deve responder a pergunta direta antes de continuar qualquer coleta pendente.

## Proteções preservadas

O agrupamento não libera agenda, confirmação, elegibilidade ou outras ações críticas. Policy Engine, triagem e agenda continuam sendo avaliados no backend.

## Banco

Migration:

`106_agent_message_grouping_context_priority.sql`
