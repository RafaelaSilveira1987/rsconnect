# RS Connect 36.36.3 — Mobile 0.3 enxuto

Esta atualização otimiza a API usada pelo aplicativo RS Connect Mobile 0.3.0.

## Objetivo

O aplicativo deixa de reproduzir as telas do sistema web e passa a usar uma interface própria para celular com Início, Conversas, Contatos, Agenda e Mais.

## API mobile

Mantidos:
- `POST /auth/login`
- `POST /mobile/logout`
- `GET /mobile/me`
- `GET /mobile/dashboard`
- `GET /mobile/conversations`
- `POST /mobile/conversations/send`
- `POST /mobile/conversations/mode`
- `GET /mobile/contacts`
- `GET /mobile/appointments`
- `GET /mobile/agent`
- `GET /mobile/notifications`

Novos/otimizados:
- `GET /mobile/conversations/messages?conversation_id=...` — carrega o histórico somente ao abrir o chat.
- `POST /mobile/conversations/read` — zera o contador de não lidas da conversa aberta pelo app.
- `GET /mobile/conversations` agora retorna somente os dados necessários da lista, reduzindo o payload inicial.

## Banco de dados

Não existe nova migration nesta versão. A migration `117_mobile_api_tokens.sql`, introduzida na 36.36.2, continua obrigatória para quem ainda não instalou a API mobile.

## Segurança

A autenticação mobile continua usando Bearer Token aleatório, armazenado no banco somente como SHA-256. Todas as consultas continuam limitadas ao tenant do usuário autenticado e às permissões do RS Connect.
