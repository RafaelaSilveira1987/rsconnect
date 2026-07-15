# Changelog — ZIP 28

- configuração por empresa para `free_slots` ou `marked_events`;
- dois templates n8n importáveis;
- leitura de eventos `opaque` e `transparent`;
- busca por títulos `VAGO — ONLINE` e `VAGO — PRESENCIAL`;
- ações `hold`, `confirm` e `release`;
- revalidação com `etag`/`If-Match`;
- vínculo persistente com `google_event_id`;
- logs de sincronização;
- patches de tela, rotas e serviços PHP para integração ao ZIP 27.

## ZIP 30.2 — Conversas sem sort_buffer

- Corrige `SQLSTATE[HY001] 1038 Out of sort memory` ao abrir conversas extensas.
- Seleciona primeiro apenas os IDs das mensagens e busca o conteúdo completo em uma segunda consulta.
- Ordena no PHP para evitar filesort de colunas `TEXT` e `JSON`.
- Aplica a mesma estratégia na abertura, no polling e no contexto usado pela IA.
- Adiciona a migration `032_conversation_messages_compact_index.sql`.
