# RS Connect 36.28.7 — mensagens das regras e fila da IA destravadas

## Causa

O serviço responsável por enviar mensagens configuradas em regras (ex.: idade mínima) consultava a coluna `mode` na tabela `conversations`. A tabela usa `attendance_mode`. A consulta falhava antes de enviar a mensagem. Como a triagem já havia decidido `skip_ai=true`, a IA também não respondia e a conversa passava a aparecer como “aguardando resposta da IA”.

## Correção

- `ConversationAutomationMessageService` agora lê `attendance_mode`.
- Mensagens configuradas em políticas voltam a ser enviadas normalmente.
- A conversa permanece em modo IA quando a regra bloqueia somente a agenda.
- O reprocessamento manual pode retomar as pendências antigas: após o deploy, use “Tentar respostas novamente”.

## Homologação

1. Enviar “é para minha filha, ela tem 8 anos”.
2. A regra de idade deve enviar a mensagem configurada.
3. A agenda deve permanecer bloqueada.
4. Enviar “quero uma indicação”.
5. O assistente deve continuar a conversa normalmente.
6. O painel de saúde não deve manter essa conversa como aguardando resposta.
