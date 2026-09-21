# RS Connect 36.36.6 — Origem de contatos e bloqueio da agenda WhatsApp

## Problema corrigido

`CONTACTS_UPSERT` e `CONTACTS_UPDATE` da Evolution podiam sincronizar a agenda inteira do WhatsApp e o webhook criava cada número em `contacts`, mesmo sem conversa real e sem cadastro manual.

## Novo comportamento

- `MESSAGES_UPSERT`: pode criar o contato, pois existe interação real.
- Cadastro manual: cria o contato normalmente.
- Novo atendimento humano iniciado pela plataforma: cria o contato quando necessário.
- `CONTACTS_UPSERT` / `CONTACTS_UPDATE`: **não criam novos contatos**. Apenas enriquecem nome/avatar de contatos já existentes.
- A coluna `contacts.origin` registra a origem operacional para auditoria.

## Migration obrigatória

`database/migrations/118_contact_origin.sql`

A migration classifica a base já existente em `manual`, `conversation`, `whatsapp_sync` ou `legacy`. `whatsapp_sync` identifica registros sem conversa e sem evidência de cadastro manual que já estavam associados a uma instância/JID da Evolution.

## Importante

A migration **não exclui nenhum contato**. A limpeza dos registros `whatsapp_sync` deve ser feita somente após conferência de dependências e backup.
