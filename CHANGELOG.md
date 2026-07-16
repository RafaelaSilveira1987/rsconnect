# ZIP 30.5 — Administração técnica de instâncias e agentes

- Permite ao Super Admin atualizar nome, URL, API Key, status e nome Evolution sem perder o ID interno.
- Adiciona recuperação/reassociação do agente de IA após recriação da instância.
- Permite atualizar informações técnicas do agente sem apagar prompt, base ou credenciais.
- Adiciona exclusão protegida da instância, com migração opcional de agentes, contatos, conversas e campanhas.
- Consolida conversas duplicadas durante a substituição para preservar mensagens e histórico.
- Mantém essas ações ocultas e bloqueadas para usuários finais.

# ZIP 30.4 — Conversas em lote como lidas

- Adiciona modo de seleção na caixa de entrada.
- Permite selecionar uma ou todas as conversas visíveis.
- Adiciona comando “Marcar como lidas” com escopo por empresa.
- Remove os contadores pendentes do menu, dashboard e lista após a atualização.
- Preserva o hotfix de memória das conversas, a Agenda Inteligente e o prompt editável.

# ZIP 30.3 — Prompt editável e diagnóstico de onboarding

- Permite editar prompt principal e base de conhecimento de agentes existentes.
- Protege a alteração por permissão, CSRF e tenant.
- Corrige o falso positivo de `onboarding_progress` no diagnóstico técnico.
- Preserva as correções de Agenda Inteligente e Conversas do ZIP 30.2.

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
