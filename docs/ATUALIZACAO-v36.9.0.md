# Atualização RS Connect v36.9.0 — Identificadores públicos UUID

## Objetivo

Evitar que chaves numéricas sequenciais do banco, como `tenant_id=2`, `contact_id=15` ou `conversation_id=91`, apareçam nas URLs públicas do RS Connect.

A atualização adota uma camada de identificadores públicos UUID sem substituir as chaves primárias e estrangeiras numéricas do banco. Dessa forma, os relacionamentos e consultas existentes continuam eficientes e o risco de uma migração estrutural ampla é evitado.

## Exemplo

Antes:

```text
/contacts?tenant_id=2
```

Depois:

```text
/contacts?tenant_uuid=xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx
```

O UUID é opaco, autenticado e vinculado ao tipo do registro. Um UUID de empresa não é aceito como UUID de contato, e qualquer alteração no token resulta em 404.

## Compatibilidade

Links antigos com IDs numéricos continuam funcionando. Ao serem abertos, o Router redireciona imediatamente para a URL canônica com UUID.

Controllers e serviços continuam recebendo IDs numéricos internamente após a validação do UUID. Isso reduz o impacto sobre o código existente.

## Segurança

UUID não substitui autorização. Todas as regras de empresa, usuário e permissão continuam obrigatórias.

Os identificadores públicos dependem da `APP_KEY`. Não altere a `APP_KEY` em produção sem um plano de rotação, pois a mesma chave já protege outras informações criptografadas e a troca invalidará links UUID previamente gerados.

## Banco de dados

Não há migration nova. A última migration obrigatória permanece:

```text
database/migrations/066_contact_schedule_overlap_guard_compat.sql
```

## Validação após o deploy

1. Abra Contatos como Super Admin e selecione uma empresa.
2. Confirme que a URL contém `tenant_uuid=` e não `tenant_id=`.
3. Abra uma conversa e confirme `conversation_uuid=`.
4. Abra uma empresa e confirme `company_uuid=`.
5. Copie um UUID, altere um caractere e confirme que o sistema retorna 404.
6. Abra um favorito antigo com ID numérico e confirme o redirecionamento para UUID.
7. Valide envio e recebimento de mensagens e o webhook da Evolution.
