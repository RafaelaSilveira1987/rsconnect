# RS Connect 36.11.0 — Hardening de isolamento entre empresas

## Instalação

1. Aplique o patch sobre a versão 36.10.7 / Beta 1.1.
2. Faça commit e push na branch de hardening.
3. Execute um novo deploy/rebuild no EasyPanel.
4. Atualize o navegador com `Ctrl + F5`.

Não existe migration nova. A migration mais recente continua sendo a `071_utc_datetime_contract_compat.sql`.

## Validação

- Execute `database/diagnostics/tenant_isolation_v36.11.0.sql`.
- Todas as verificações de vínculos cruzados devem retornar `quantidade = 0`.
- Siga `docs/diagnostics/TENANT-ISOLATION-VALIDATION-v36.11.0.md` para os testes de UUID, campos ocultos, tenant e Super Admin.
- Tentativas bloqueadas ficam registradas como `tenant.cross_scope_access_blocked` em `security_events`.

## Comportamento esperado

Usuários comuns recebem 404 ao tentar acessar um registro de outra empresa. O Super Admin continua com acesso administrativo global, sujeito às permissões e rotas já existentes.
