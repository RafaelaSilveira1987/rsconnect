# RS Connect 36.29.4

Melhoria da continuidade de conversa dos assistentes no WhatsApp.

## O que mudou

- configuração por assistente para **aguardar o cliente terminar e agrupar mensagens** antes de responder;
- o tempo configurado passa a representar silêncio após a última mensagem; se chegar outro balão, a contagem recomeça;
- o RS Connect reconstrói o **turno atual** com todas as mensagens recebidas desde a última resposta;
- o assistente recebe instrução prioritária para responder primeiro perguntas e pedidos do turno atual e só depois retomar o roteiro;
- a identidade pública do assistente é enviada como contexto confiável; perguntas como “com quem eu falo?” devem usar o nome real configurado;
- quando o telefone já veio do WhatsApp/cadastro, o prompt operacional impede pedir o telefone novamente sem necessidade;
- respostas locais/cache exato não são usados quando há vários balões no mesmo turno, evitando responder somente o último fragmento;
- agenda/triagem continuam usando o mesmo bloco agrupado e as travas do Policy Engine permanecem intactas.

## Migration

Execute:

```bash
php bin/migrate.php up
php bin/migrate.php status
php bin/migrate.php verify
```

Migration nova:

`106_agent_message_grouping_context_priority.sql`

Depois reinicie o PHP-FPM/container para limpar OPcache.
