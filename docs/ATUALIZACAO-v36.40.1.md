# Atualização RS Connect 36.40.1

## Objetivo

Evitar que dados de um atendimento anterior contaminem o ciclo atual e impedir a repetição de informações que o contato já enviou em mensagens rápidas antes da etapa correspondente.

## Principais correções

- a memória progressiva anterior é suspensa enquanto houver triagem estruturada ativa;
- ao iniciar um novo ciclo, a memória progressiva específica da conversa também é descartada;
- `patient_name` e `is_for_self` do ciclo atual têm prioridade absoluta sobre nomes presentes em histórico/memória;
- uma mensagem que não responde ao campo atual pode preencher um campo futuro somente quando houver um único encaixe inequívoco;
- a Ordem do atendimento continua definindo qual pergunta vem a seguir; a captura antecipada apenas evita perguntar novamente algo que já foi informado;
- novo utilitário `bin/reset-test-conversation.php` para homologação, com prévia obrigatória antes do `--apply`.

## Limpeza recomendada do contato de teste

Primeiro execute apenas a prévia:

```bash
php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa
```

Se a empresa, contato, agente, conversas e quantidades exibidas estiverem corretos:

```bash
php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa --apply --purge-messages
```

A Agenda é preservada por padrão. Para apagar também compromissos/pré-agendamentos desse contato de homologação, acrescente `--purge-calendar`.

## Migration

Não há migration nova. Permanece obrigatória:

`122_calendar_client_communications.sql`

## Teste recomendado

Envie em sequência, antes da resposta da IA:

1. nome da pessoa atendida;
2. idade;
3. uma informação correspondente a uma etapa posterior, como demanda/objetivo;

O sistema deve preservar os dados já informados, seguir perguntando somente a próxima etapa ainda pendente e nunca reutilizar o nome de uma pessoa atendida em ciclo anterior.
