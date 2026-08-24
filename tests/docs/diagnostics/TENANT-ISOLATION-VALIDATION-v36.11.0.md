# Validação de isolamento entre empresas — RS Connect 36.11.0

## Objetivo

Confirmar que um usuário de uma empresa não consegue consultar nem alterar registros de outra empresa, mesmo conhecendo um UUID válido ou alterando campos ocultos do formulário.

## Cenário mínimo

- Empresa A com um usuário comum e uma conversa ativa.
- Empresa B com outro contato, conversa, usuário e agendamento.
- Super Admin disponível para a validação administrativa.

## Teste 1 — UUID de outra empresa

1. Entre como usuário da Empresa A.
2. Copie, em uma sessão de Super Admin, o `conversation_uuid` de uma conversa da Empresa B.
3. Na sessão da Empresa A, abra `/conversations?conversation_uuid=UUID_DA_EMPRESA_B`.
4. Resultado esperado: página 404 “Registro não encontrado”.
5. A conversa da Empresa B não pode ser carregada, marcada como lida ou alterada.

Repita com `contact_uuid`, `appointment_uuid`, `user_uuid` e `instance_uuid`.

## Teste 2 — campo oculto adulterado

1. Entre como usuário da Empresa A.
2. Abra o DevTools do navegador e altere um campo oculto como `conversation_id`, `contact_id`, `appointment_id` ou `assigned_user_id` para o ID interno de um registro da Empresa B.
3. Envie o formulário.
4. Resultado esperado: 404 antes da execução do controller e nenhuma alteração no banco.

## Teste 3 — filtro de empresa adulterado

1. Entre como usuário da Empresa A.
2. Tente acessar uma URL contendo `tenant_uuid` ou `tenant_id` da Empresa B.
3. Resultado esperado: 404.
4. O usuário comum não pode trocar o tenant da sessão por query string ou formulário.

## Teste 4 — Super Admin

1. Entre como Super Admin.
2. Abra os mesmos registros das empresas A e B.
3. Resultado esperado: acesso permitido conforme as permissões administrativas já existentes.

## Auditoria

Após uma tentativa bloqueada, execute:

```sql
SELECT id, tenant_id, user_id, event, severity, context_json, ip_address, created_at
FROM security_events
WHERE event = 'tenant.cross_scope_access_blocked'
ORDER BY id DESC
LIMIT 20;
```

O evento deve registrar a rota, o método e a referência recusada, sem exibir qualquer dado da empresa alvo na resposta ao usuário.

## Integridade dos vínculos

Execute `database/diagnostics/tenant_isolation_v36.11.0.sql`.

O primeiro resultado deve apresentar `quantidade = 0` em todas as verificações. Registros diferentes de zero indicam vínculos históricos cruzados que precisam ser analisados antes de qualquer correção manual.
