# RS Connect 36.6.26 — Agenda resiliente e identidade confiável

## Objetivo
Corrigir duas regressões observadas em produção:

1. consultas de disponibilidade que ficavam paradas em "Vou verificar a disponibilidade..." mesmo após o n8n receber/processar a solicitação;
2. contatos sem nome real recebendo indevidamente o nome do proprietário da conta/WhatsApp conectado.

## Migration obrigatória

```sql
SOURCE database/migrations/059_contact_identity_confidence.sql;
```

A migration acrescenta metadados de confiança ao nome do contato:

- `name_source`
- `whatsapp_name_candidate`
- `whatsapp_name_seen_count`

## Agenda: recuperação do retorno de disponibilidade

A Fila rápida da IA passa a executar também a recuperação conversacional da Agenda.

### Callback chegou, mas a mensagem não foi enviada
O resultado salvo em `calendar_availability_requests` é reprocessado de forma idempotente. A conversa recebe as opções ou a mensagem de indisponibilidade sem criar uma nova solicitação.

### Callback não chegou
Depois do cooldown de retry, o RS Connect reenvia o mesmo request/token ao n8n. Não é criado um novo pré-agendamento.

Padrões:

```env
CALENDAR_AVAILABILITY_RETRY_MINUTES=2
CALENDAR_AVAILABILITY_RECOVERY_LIMIT=25
```

Essas variáveis são opcionais; os valores acima já são os padrões.

A Fila rápida deve continuar publicada/ativa no n8n.

## Nome do contato

A origem `pushName` da Evolution deixa de ser considerada nome definitivo em uma única ocorrência.

Regras:

- mensagens `fromMe` nunca alimentam o nome automático do contato;
- sem nome confiável, a interface mostra somente o telefone;
- nome digitado manualmente é preservado;
- um nome automático precisa aparecer de forma consistente antes de ser promovido;
- nomes que coincidam com usuário da empresa, empresa/instância ou apareçam em números diferentes são tratados como suspeitos;
- colisões removem nomes que tenham sido promovidos automaticamente.

### Contatos já contaminados antes da 059
A migration não apaga nomes existentes porque a origem histórica não pode ser determinada com segurança. Se um contato antigo estiver com o nome errado, abra os Dados do lead/Contato, apague o campo Nome e salve. O telefone passa a ser o fallback e as novas regras assumem dali em diante.

## Assets
Após o deploy, faça atualização forçada do navegador. Os assets usam `v=36.6.26`.
