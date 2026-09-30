# Atualização RS Connect 36.41.1

## Objetivo

Concluir o fluxo de comunicação automática da Agenda com o próprio cliente/paciente.

## Alterações

- remove da interface da Agenda o campo redundante de texto original/mensagem que originou o pedido;
- mantém as informações estruturadas do pré-agendamento como fonte visual do atendimento;
- confirmação e lembretes usam o contato vinculado ao compromisso e, como fallback, o contato da conversa;
- o número pode vir de `contacts.phone`, `contacts.remote_jid` ou do `remote_jid` da conversa;
- o cron CLI `php bin/process-notifications.php` passa a processar também `calendar_client_message_jobs`;
- o endpoint HTTP de cron continua processando a mesma fila;
- lembretes e pedidos de presença só são enviados quando o compromisso continua confirmado e com a mesma data/hora esperada.

## Banco de dados

Não há migration nova. Permanece obrigatória:

`123_published_slots_min_notice_policy.sql`

## Homologação

1. Confirme um agendamento vinculado a um contato com WhatsApp e valide a mensagem de confirmação.
2. Ative o lembrete, configure alguns minutos antes e execute o processador de notificações no horário devido.
3. Confirme que um compromisso cancelado/remarcado não recebe o lembrete antigo.
4. Confirme que a Agenda não exibe mais o texto original redundante no bloco de informações do pré-agendamento.
