# RS Connect 36.36.2 — API Mobile segura

Esta atualização prepara o backend real para o aplicativo RS Connect Mobile 0.2.0.

## O que foi incluído

- autenticação mobile por Bearer Token com validade de 30 dias;
- token armazenado no banco apenas como hash SHA-256;
- isolamento por empresa e reaproveitamento das permissões já existentes;
- endpoints reais de conversas, contatos, agenda, agentes, indicadores e notificações;
- envio de mensagens do app reutilizando o fluxo oficial da Evolution API;
- alteração do modo IA/humano por conversa;
- IDs públicos opacos no aplicativo, sem exposição dos IDs numéricos internos;
- migration `117_mobile_api_tokens.sql`.

## Endpoints

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

## Implantação

1. Atualize os arquivos do projeto web.
2. Execute o executor de migrations já utilizado pelo RS Connect, garantindo a aplicação de `117_mobile_api_tokens.sql`.
3. Confirme que `https://rsconnect.rsautomacaodigital.cloud/health/live` responde normalmente.
4. Instale o APK/mobile 0.2.0 apontado para `https://rsconnect.rsautomacaodigital.cloud`.
5. Entre usando o mesmo e-mail e senha da plataforma web.

O aplicativo nunca recebe credenciais do MySQL. A comunicação é feita exclusivamente pela API HTTPS do RS Connect.
