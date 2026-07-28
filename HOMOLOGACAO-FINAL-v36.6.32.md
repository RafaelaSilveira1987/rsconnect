# Homologação — RS Connect 36.6.32

## Configuração

- [ ] `Fila rápida da IA` está publicada.
- [ ] O assistente não possui `/webhook/rsconnect-agenda-cliente` no campo Integração externa.
- [ ] A URL de disponibilidade da empresa aponta para Eventos VAGO ou Espaços livres, conforme o modo configurado.
- [ ] O writer `Agenda Google Calendar por Empresa` continua publicado, mas somente para compromisso real.

## Retomada pós-horário

1. Fora do horário, envie:

```text
Tem horário para quarta às 13h?
```

2. Confirme o aviso de ausência.
3. Aguarde a próxima abertura.

Esperado:

- [ ] A Agenda retoma a intenção antes da IA geral.
- [ ] A mensagem de consulta de disponibilidade não fica solta.
- [ ] Existe `calendar.availability.requested` no fluxo de disponibilidade correto.
- [ ] O writer não recebe `ai.replied`.
- [ ] O cliente recebe opções ou indisponibilidade após o callback.

## Mensagens fragmentadas

Fora do horário, envie em mensagens separadas:

```text
Quero agendar
quarta às 13h
online
```

Esperado:

- [ ] As três mensagens são reunidas na retomada.
- [ ] Modalidade = online.
- [ ] Dia/horário = quarta às 13h.
- [ ] Uma única consulta de disponibilidade é criada.

## Conversa já travada antes do deploy

- [ ] Sem enviar nova mensagem, aguarde dois ciclos da Fila rápida.
- [ ] O pré-agendamento sem request atual é reparado.
- [ ] O log contém `calendar.availability_missing_request_recovered`.
- [ ] Não é criado compromisso definitivo no Google Calendar nesta etapa.

## Writer do Google

Execute manualmente um payload `ai.replied` contra a URL `rsconnect-agenda-cliente`.

Esperado:

- [ ] O RS Connect não encaminha esse evento ao writer.
- [ ] O writer somente recebe `calendar.appointment.created` com contrato válido.

## Agenda interna em nova empresa

- [ ] Criar empresa nova sem URLs Google/n8n.
- [ ] Ativar disponibilidade interna e fallback interno.
- [ ] Confirmar que nenhuma configuração da empresa Google existente precisa ser desfeita.
