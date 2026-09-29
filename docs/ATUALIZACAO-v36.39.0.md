# Atualização RS Connect 36.39.0

## Objetivo

A versão 36.39.0 adiciona continuidade de compromissos existentes e comunicação automática da Agenda com o cliente/paciente.

## Banco de dados

Execute:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
```

A migration obrigatória é `122_calendar_client_communications.sql`. Ela cria as configurações de comunicação por empresa, a fila de mensagens ao cliente e os campos que separam confirmação de presença do status do compromisso.

## Configuração

Acesse **Agenda → Configurações → Comunicação e confirmação do agendamento**. É possível definir:

- identificação de compromisso existente antes de uma nova triagem;
- resposta sobre o próprio agendamento fora do expediente;
- mensagens automáticas ao registrar, confirmar, cancelar/recusar e remarcar;
- lembrete antes do atendimento;
- pedido de confirmação de presença;
- textos e variáveis das mensagens.

## Processamento automático

Não é necessário criar um novo cron. O endpoint de processamento de notificações existente também processa `calendar_client_message_jobs`.

## Regra de segurança

Mensagens do cliente pedindo cancelamento ou remarcação não alteram automaticamente o compromisso nem liberam a vaga. O pedido é registrado e a equipe recebe uma notificação para decidir a alteração.

## Homologação sugerida

1. Confirme um compromisso pela Agenda e valide a mensagem automática configurada.
2. Pergunte pelo WhatsApp “minha consulta de amanhã está confirmada?” e confirme que o RS Connect responde o compromisso real, sem oferecer novos horários.
3. Ative um pedido de presença e valide que “sim” atualiza apenas a confirmação do cliente.
4. Peça cancelamento/remarcação pelo WhatsApp e confirme que o horário continua preservado até a equipe alterar o compromisso.
5. Teste a pergunta fora do expediente com a opção de consulta operacional desligada e ligada.
