# RS Connect 36.36.4 — Mobile 0.4 atendimento rápido

## Objetivo

Aprimorar a API do aplicativo para que a tela de Conversas funcione como central operacional de atendimento no celular, sem replicar a interface desktop.

## Novidades da API Mobile

- `POST /mobile/auth/login`: alias mobile do login Bearer.
- `GET /mobile/conversations/context`: estado operacional da conversa, permissões de ação, equipe e setores disponíveis.
- `POST /mobile/conversations/assignment`: assumir, atribuir, transferir ou liberar atendimento usando `ConversationOwnershipService`.
- `POST /mobile/conversations/department`: transferir a conversa para outro setor.
- `POST /mobile/conversations/mode`: agora reutiliza as mesmas regras de ownership da plataforma web para IA, humano e IA pausada.
- Lista de conversas informa modo de atendimento, responsável, setor e se a conversa pertence ao usuário atual.

## Autorização Bearer

O backend agora aceita `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` e fallback por `getallheaders()`.
O Dockerfile também preserva o header `Authorization` com `SetEnvIf`, evitando perda da configuração após rebuild do container.

## Mobile 0.4

A nova interface coloca no topo de cada chat três ações rápidas:

1. **Ativar / Pausar IA**
2. **Assumir atendimento**
3. **Transferir**

A transferência pode ser feita para outro profissional ou, quando configurado, para outro setor. A interface também exibe claramente quem está atendendo e bloqueia composição quando a conversa possui atendimento exclusivo de outro profissional.
