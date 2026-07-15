# RS Connect — ZIP 27

## Agenda inteligente + disponibilidade via n8n

Este ZIP adiciona uma camada de validação de disponibilidade para o pré-agendamento.

### O que foi incluído

- Menu **Agenda inteligente**.
- Rotas:
  - `/agenda-inteligente`
  - `/agenda-disponibilidade`
  - `/calendar/availability`
- Configuração por empresa:
  - ativar/desativar disponibilidade inteligente;
  - exigir disponibilidade antes de aprovar pré-agendamento;
  - consulta automática quando a IA captura dia e horário;
  - URL de webhook n8n;
  - fallback interno por horários comerciais;
  - dias de atendimento, expediente, duração, intervalo e antecedência mínima.
- Endpoint de callback:
  - `/webhooks/calendar/availability`
- Template n8n:
  - `docs/n8n_templates/template-agenda-disponibilidade.json`
- Botão **Buscar disponibilidade** no pré-agendamento.
- Lista de horários retornados com botão **Usar este horário**.
- Bloqueio opcional para impedir aprovação sem disponibilidade validada.

### Migration

Execute no Adminer:

```sql
source database/migrations/029_smart_calendar_availability_n8n.sql;
```

Ou copie o conteúdo do arquivo e execute no banco `rs_connect`.

### Fluxo recomendado

1. Acesse `/agenda-inteligente`.
2. Selecione a empresa.
3. Ative a agenda inteligente.
4. Cole a URL de produção do webhook n8n.
5. Configure dias, horários e duração.
6. Salve.
7. Importe o template no n8n.
8. No pré-agendamento, clique em **Buscar disponibilidade**.
9. Quando o n8n devolver os slots, clique em **Usar este horário**.
10. Aprove o pré-agendamento.

### Observação

O template n8n vem com um nó de geração de horários de exemplo para validar o fluxo. Substitua esse nó pela consulta real ao Google Calendar ou agenda externa do cliente.
