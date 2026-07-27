# Homologação — RS Connect 36.6.18

## 1. Execução manual

1. Abra Agenda → Disponibilidade/Manutenção.
2. Clique em **Executar manutenção agora**.
3. Confirme que não há execução correspondente no n8n.
4. O painel deve registrar origem **Manual** e o resultado do ciclo.

## 2. Execução automática

1. Publique o template `template-calendar-maintenance.json`.
2. Execute o node HTTP ou aguarde o gatilho de 10 minutos.
3. Confirme HTTP 200 e `ok=true`.
4. Atualize a tela da Agenda: a origem deve aparecer como **n8n** para a empresa processada.

## 3. Callbacks vencidos

O card só deve contar registros em `calendar_availability_requests` com:
- `status` em `pending`/`sent`;
- `responded_at IS NULL`;
- mais de 30 minutos.

Depois da manutenção, registros elegíveis devem ser encerrados e o card deve cair.

## 4. Último ciclo

Validar na tela:
- data/hora;
- origem;
- status;
- pré-reservas liberadas;
- callbacks encerrados;
- sincronizações tentadas;
- erros.

## 5. Regressão

- botão manual continua protegido por login/CSRF;
- endpoint automático continua protegido por `CALENDAR_MAINTENANCE_TOKEN`;
- não há migration nova.
