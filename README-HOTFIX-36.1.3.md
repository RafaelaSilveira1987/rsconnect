# RS Connect — HOTFIX 36.1.3

## Problema corrigido

Uma mensagem nova era salva, porém podia ficar sem resposta até o reprocessamento manual quando:

- chegava logo depois de uma resposta da IA e era confundida pelo cooldown;
- o n8n ou a busca do Google Agenda demoravam antes de a IA ser chamada;
- o evento `ai.replied` mantinha o lock da conversa enquanto aguardava integração externa.

## Comportamento novo

1. A mensagem é persistida.
2. A proteção contra duplicidade confere se aquela mensagem já recebeu uma saída posterior.
3. Uma mensagem nova, com `incoming_message_id` válido, é processada imediatamente.
4. IA ou confirmação do pré-agendamento responde primeiro.
5. Somente depois são chamados n8n, disponibilidade e eventos auxiliares.
6. Falhas dessas integrações não transformam uma resposta já enviada em pendência da IA.

## Instalação

Substitua os arquivos do hotfix e faça novo deploy. Não existe migration nova para esta versão.

## Teste recomendado

1. Envie `Como faço para agendar?`.
2. Após a resposta, envie imediatamente `Quinta às 16h tem como?`.
3. A segunda mensagem deve receber resposta sem clicar em reprocessar.
4. Confirme no banco que há um `ai.replied` ligado ao `incoming_message_id` da segunda mensagem, ou uma saída `calendar.pre_schedule_ack_sent` quando a preferência for registrada diretamente.
