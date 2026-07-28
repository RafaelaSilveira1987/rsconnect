# Homologação — RS Connect 36.6.23

## 1. Conversa comum com data/hora
Enviar: `Tenho uma reunião amanhã às 10h.`

Esperado:
- nenhuma criação de pré-agendamento;
- nenhuma criação de evento Google;
- nenhum ciclo de criação do Google acionado por manutenção.

## 2. Pedido explícito de agenda
Enviar: `Quero marcar uma reunião amanhã às 10h.`

Esperado:
- pode iniciar pré-agendamento e disponibilidade;
- não cria evento definitivo do Google apenas por detectar a intenção;
- evento definitivo só nasce após confirmação/aprovação do compromisso.

## 3. Manutenção
Executar manutenção automática/manual com registros `scheduled` não confirmados.

Esperado:
- `google_events_created = 0` para esses registros;
- somente `status=confirmed` é elegível.

## 4. Pré-agendamento
Manter um compromisso `is_pre_schedule=1` com aprovação pendente.

Esperado:
- nenhuma criação de evento definitivo;
- após aprovação/confirmado, o ciclo pode sincronizar.

## 5. Templates n8n
Confirmar:
- `Agenda Google Calendar por Empresa v36.6.23` exige status confirmado;
- `Google Agenda — Ciclo completo v36.6.23` exige `calendar_confirmed_sync_v1`;
- não existe título fallback `Compromisso RS Connect` ou `Agendamento RS Connect` no writer/ciclo atualizado.
