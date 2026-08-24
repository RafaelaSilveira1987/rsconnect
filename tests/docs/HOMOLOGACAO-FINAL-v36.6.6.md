# Homologação final — RS Connect 36.6.6

| Cenário | Resultado esperado |
|---|---|
| Conversa em `human` recebe mensagem com intenção de agenda | Nenhuma resposta automática é enviada |
| Conversa em `paused` recebe mensagem com intenção de agenda | Nenhuma resposta automática é enviada |
| Humano envia mensagem pelo painel | Modo `human` é persistido antes do HTTP para Evolution |
| Callback de agenda chega depois do takeover humano | Mensagem automática é bloqueada |
| Contato `customer` + grupo `patient` pergunta horário | Não exige motivo/queixa |
| Contato `customer` + grupo `customer` pergunta horário | Não exige motivo/queixa |
| Estado antigo `collecting_demand/pending` de cliente | Migration 050 converte para `not_required` |
| Novo lead/interessado | Continua seguindo as regras normais de coleta de demanda |

## Arquivos principais alterados

- `app/Services/PreSchedulingService.php`
- `app/Services/CalendarConversationService.php`
- `app/Services/ConversationFlowService.php`
- `app/Services/AiModelService.php`
- `app/Controllers/ConversationController.php`
- `database/migrations/050_human_takeover_customer_context.sql`
