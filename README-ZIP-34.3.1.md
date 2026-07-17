# RS Connect — ZIP 34.3.1

## Intervalo entre respostas preservado

O campo **Intervalo mínimo entre respostas (seg.)** continua disponível por assistente.

A configuração não é global: cada empresa e cada assistente podem usar o tempo adequado ao próprio atendimento.

Quando uma mensagem chega antes de terminar o intervalo:

1. a mensagem continua salva na conversa;
2. o sistema registra `ai.cooldown`;
3. a mensagem não recebe uma resposta duplicada imediatamente;
4. o botão **Reprocessar IA** pode ser usado manualmente;
5. ao salvar as configurações ou instruções do assistente, a última mensagem pendente daquele assistente é reavaliada automaticamente.

O reprocessamento automático usa proteção contra duplicidade:

- processa somente a mensagem recebida mais recente que ficou em `ai.cooldown`;
- exige que a conversa continue em modo IA;
- ignora conversas encerradas;
- não responde quando já existe qualquer mensagem enviada depois da mensagem recebida;
- ignora pendências de reação quando a opção de responder a reações estiver desligada;
- reprocessa no máximo uma mensagem por salvamento.

Salvar o formulário já redireciona e recarrega a tela de Assistentes de IA.

## Reprocessamento manual

O comando existente em **Conversas → Reprocessar IA** agora ignora apenas a trava de intervalo durante aquela tentativa.

As demais regras continuam valendo:

- assistente ativo;
- respostas automáticas ligadas;
- conversa em modo IA;
- horário configurado;
- credencial válida;
- empresa com acesso liberado.

## Reações do WhatsApp

Por padrão, reações como curtidas e emojis são ignoradas antes de criar conversa, notificação, CRM, agenda ou automação.

Cada assistente possui a opção:

`Responder a reações em mensagens`

Quando ativada, a reação é enviada para a IA como contexto. CRM, agenda e fluxos externos continuam sem ser acionados pela reação.

## Migration

Execute:

`database/migrations/038_ai_reaction_preferences.sql`

A migration somente cria a coluna `reply_to_reactions`.

Ela **não zera nem altera** `cooldown_seconds`.

## Aplicação

1. Não aplique o ZIP 34.3 anterior.
2. Suba o conteúdo do ZIP 34.3.1 no GitHub.
3. Execute a migration 038 no Adminer.
4. Faça o redeploy.
5. Reinicie o serviço do RS Connect.
6. Pressione `Ctrl + F5`.
7. Abra Assistentes de IA e salve o assistente.

## Teste recomendado

1. Configure o intervalo como 60 segundos.
2. Faça a IA responder uma mensagem.
3. Envie outra mensagem antes de completar os 60 segundos.
4. Confirme o registro `ai.cooldown`.
5. Abra Assistentes de IA e salve as configurações.
6. Confirme que a última mensagem pendente foi reavaliada sem duplicar respostas.
7. Reaja a uma mensagem com a opção de reações desligada.
8. Confirme que não houve resposta nem nova notificação.

## Diagnóstico

Execute:

`database/diagnostics/ai_reactions_and_response_flow_check.sql`

Payload de teste:

`tests/evolution-reaction-message.json`
