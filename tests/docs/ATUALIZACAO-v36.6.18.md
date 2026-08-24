# RS Connect 36.6.18 — Manutenção da agenda auditável

## O que muda

- **Executar manutenção agora** é uma ação manual e direta do RS Connect. Ela não chama o n8n e exige sessão autenticada + CSRF.
- O n8n fica responsável pela execução automática a cada 10 minutos via `POST /webhooks/calendar/maintenance/run`.
- O template envia `X-RS-Calendar-Maintenance-Token` e `X-RS-Automation-Origin: n8n`.
- O card **Callbacks pendentes vencidos** usa a mesma regra da manutenção: status `pending/sent`, sem `responded_at` e com mais de 30 minutos.
- Uma execução automática global registra também uma linha de manutenção para cada empresa realmente processada.
- O painel mostra origem, horário, status, pré-reservas liberadas, callbacks encerrados, sincronizações tentadas e erros do último ciclo.

## Banco

Não há migration nova. A última migration obrigatória continua sendo `055_multi_whatsapp_agent_routing.sql`.

## n8n

Rebaixe/importe o template **Manutenção automática da agenda** para incluir a identificação de origem `n8n`. O header de autenticação continua o mesmo.
