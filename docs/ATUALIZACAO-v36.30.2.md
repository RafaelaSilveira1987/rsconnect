# RS Connect v36.30.2 — Notas internas da conversa

## Objetivo

Separar definitivamente o **contexto persistente do contato**, que pode ser utilizado pela IA, das **notas internas da equipe**, que são privadas e vinculadas a uma conversa específica.

## Implementação

- novo endpoint `POST /conversations/internal-notes`;
- uso da tabela já existente `conversation_internal_notes`;
- histórico limitado às 100 notas mais recentes da conversa, com autor e horário;
- gravação protegida por `conversations.manage`, tenant e regra de ownership;
- conteúdo da nota não é copiado para `contacts.notes`, `conversation_messages` ou evento de auditoria;
- o evento `internal_note.added` registra somente que uma nota foi criada, sem o texto privado;
- frontend responsivo no drawer de Conversas;
- campo antigo **Notas internas** do cadastro foi renomeado para **Contexto do contato**, pois esse conteúdo pode compor contexto para a IA.

## Banco de dados

Não há migration nova. A tabela foi introduzida anteriormente por `020_queue_team_distribution.sql`.

Migration obrigatória corrente: `107_service_department_memberships.sql`.
