# Agenda Google — ciclo completo (ZIP 35.0)

## Objetivo

O modo **Espaços livres** passa a cuidar do ciclo inteiro do compromisso:

1. consultar a disponibilidade no Google Agenda;
2. aplicar o horário escolhido ao pré-agendamento;
3. criar o evento quando a equipe confirmar;
4. reaproveitar o mesmo vínculo em novas tentativas;
5. manter o vínculo durante a solicitação de remarcação e atualizar o mesmo evento após a escolha do novo horário;
6. remover o evento ao cancelar, recusar ou excluir;
7. liberar automaticamente pré-reservas VAGO vencidas;
8. tentar novamente sincronizações pendentes sem criar eventos duplicados.

O modo **Eventos VAGO** continua atualizando o próprio evento marcado como disponível.

## Configuração do n8n

Importe:

```text
docs/n8n_templates/template-agenda-google-ciclo-completo.json
```

No workflow:

1. abra todos os nós Google;
2. selecione a credencial **Google Calendar OAuth2 API** do cliente;
3. confirme que o Webhook está em modo `POST`;
4. ative o workflow;
5. copie a URL de produção do Webhook;
6. no RS Connect, acesse **Agenda → Disponibilidade**;
7. como Admin RS, preencha **URL — criar, atualizar e remover eventos confirmados**;
8. salve.

O template usa um identificador estável por agendamento. Se a criação for repetida depois de uma falha de callback, ele procura o mesmo ID antes de criar um novo evento.

## Callback

O fluxo chama:

```text
POST /webhooks/calendar/availability
```

Com o evento:

```text
calendar.free_slot.updated
```

Estados aceitos:

```text
created
updated
deleted
failed
```

O `request_token` recebido pelo fluxo deve ser devolvido no corpo ou no header `X-RS-Calendar-Token`.

## Manutenção automática

Crie uma variável segura no EasyPanel:

```env
CALENDAR_MAINTENANCE_TOKEN=troque_por_um_token_longo
```

Chame a cada 10 minutos:

```text
GET https://SEU_DOMINIO/webhooks/calendar/maintenance/run?token=SEU_TOKEN
```

Também é possível executar pelo servidor:

```bash
php /var/www/html/bin/calendar-maintenance.php
```

Para uma empresa específica:

```bash
php /var/www/html/bin/calendar-maintenance.php 2
```

A rotina:

- libera eventos VAGO cuja pré-reserva venceu;
- encerra solicitações n8n sem callback há mais de 30 minutos;
- tenta criar eventos confirmados que ficaram sem vínculo;
- tenta novamente sincronizações com falha dentro do limite configurado;
- remove eventos de compromissos cancelados ou recusados.

## Teste completo

### Espaços livres

1. deixe um horário livre no Google Agenda;
2. crie um pré-agendamento no RS Connect;
3. clique em **Buscar disponibilidade**;
4. selecione **Usar este horário**;
5. confirme o agendamento;
6. confira se o evento foi criado no Google;
7. confirme que `google_event_id` aparece no diagnóstico;
8. cancele e valide a remoção do evento.

### Eventos VAGO

1. crie um evento `VAGO — ONLINE` ou `VAGO — PRESENCIAL`;
2. faça a busca;
3. use o horário;
4. confirme que o evento virou `PRÉ-RESERVADO`;
5. confirme o agendamento e valide o título `AGENDADO`;
6. crie outra pré-reserva curta e aguarde o vencimento;
7. execute a manutenção e confirme que o evento voltou para VAGO.

## Diagnóstico

Execute no Adminer:

```text
database/diagnostics/calendar_google_full_cycle_check.sql
```

A tela **Saúde e diagnóstico** também passa a indicar:

- fluxo do ciclo Google não configurado;
- compromisso confirmado sem evento;
- sincronização com falha;
- pré-reserva vencida;
- última execução da manutenção.
