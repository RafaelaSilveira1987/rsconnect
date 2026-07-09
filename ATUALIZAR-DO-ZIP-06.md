# Atualizar do ZIP 06 para ZIP 07

## 1. Arquivos

Copie os arquivos do ZIP 07 sobre o projeto atual ou faça commit/push no GitHub.

## 2. Deploy

No EasyPanel, faça **Redeploy** do serviço `rsconnect`.

## 3. Banco de dados

No Adminer, selecione o banco `rs_connect` e execute:

```text
database/migrations/008_conversations_pro_crm_auto.sql
```

## 4. Teste

1. Envie uma nova mensagem pelo WhatsApp de um lead.
2. Abra **Conversas**.
3. Confira o card **CRM automático** na lateral.
4. Clique em **Gerar sugestão** para criar uma resposta com IA sem enviar.
5. Clique em **Abrir no CRM** para validar a oportunidade criada.

## 5. Consultas úteis

```sql
SELECT id, title, source, source_conversation_id, status, priority
FROM crm_leads
ORDER BY id DESC
LIMIT 20;
```

```sql
SELECT id, crm_lead_id, ai_interest_level, ai_next_action, last_ai_suggestion_at
FROM conversations
ORDER BY id DESC
LIMIT 20;
```
