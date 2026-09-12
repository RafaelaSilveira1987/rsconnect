# RS Connect 36.32.0 — Production Readiness: Go-Live

Primeira fase do roadmap de estabilização pré-produção.

## Entrega
- ciclo operacional independente do acesso e da assinatura;
- estados Onboarding, Ready, Live e Suspended;
- Go-Live explícito e auditado;
- banner de homologação/suspensão no tenant;
- histórico de transições;
- métricas oficiais de SLA e primeira resposta restritas a janelas LIVE;
- MRR operacional do RS Admin restrito a tenants LIVE;
- cobrança manual de produção bloqueada antes do Go-Live.

## Migration
`112_tenant_lifecycle_go_live.sql`

## Implantação

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
```

Depois acesse **Empresas**, valide a configuração do cliente, altere para **Pronta para produção** e somente então confirme **Colocar em produção**.

Consulte `TESTE_DA_VERSAO.md` antes de avançar para a fase Evolution Reliability.
