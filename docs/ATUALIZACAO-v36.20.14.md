# Atualização RS Connect v36.20.14

Entrega da **ENT-029 / PA-003 — Health checks seguros**.

## Alterações

- criado `/health/live` para liveness público e mínimo;
- criado `/health/ready` para readiness público sem detalhes internos;
- criado `/health/ready/details`, protegido por autenticação e perfil Super Admin;
- removida a criação de sessão nos endpoints públicos de health check;
- adicionados cabeçalhos `no-store`, `nosniff`, `noindex` e remoção de `X-Powered-By`;
- adicionado healthcheck do contêiner da aplicação apontando para `/health/live`;
- nenhuma migration ou regra comercial foi alterada.
