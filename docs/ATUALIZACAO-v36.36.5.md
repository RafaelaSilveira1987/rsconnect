# RS Connect 36.36.5 — Isolamento de tenant no Mobile

## Objetivo

Endurecer o isolamento multiempresa da API mobile, com foco especial na listagem de contatos e na identidade de empresa vinculada ao Bearer Token.

## Correções

- O `tenant_id` gravado em `mobile_api_tokens` no momento do login passa a ser a fronteira de segurança da sessão mobile.
- Para usuários de cliente, se o tenant atual do usuário divergir do tenant gravado no token, o token é revogado e o aplicativo exige novo login.
- A API deixa de trocar silenciosamente de tenant caso o vínculo do usuário seja alterado após a emissão do token.
- A listagem de contatos continua exigindo `contacts.tenant_id = :tenant_id` e agora também exige que o responsável associado à conversa pertença ao mesmo tenant.
- As subconsultas de conversa e CRM permanecem vinculadas ao tenant do contato.
- `GET /mobile/contacts` passa a retornar também o tenant público da resposta, facilitando conferência sem expor IDs numéricos.

## Migration

Não há nova migration. A `117_mobile_api_tokens.sql` continua sendo a migration necessária para a API mobile.

## Teste

```bash
php tests/Feature/mobile-api-v36365-smoke.php
```

Resultado esperado:

```text
OK mobile-api-v36365-smoke
```

Após atualizar o servidor, faça logout/login no aplicativo para que seja emitido um token com o tenant atual.
