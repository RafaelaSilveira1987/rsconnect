# ROLLBACK — RS Connect 36.33.0

## Quando usar
Use este procedimento se a Fase B causar regressão no gerenciamento das conexões WhatsApp.

## Rollback recomendado
1. faça backup do banco atual;
2. restaure os arquivos da **36.32.2**;
3. reinicie a aplicação:

```bash
docker compose restart app
```

4. valide login, WhatsApp, IA, atendimento humano, agenda, Go-Live e relatórios.

## Banco de dados
A migration `114_evolution_reconciliation_observability.sql` é aditiva. Em rollback de código, **não é necessário remover**:
- `remote_connection_state`;
- `reconciliation_status`;
- `reconciliation_reason`;
- `last_reconciled_at`;
- `reconciliation_failures`;
- `evolution_reconciliation_runs`.

A 36.32.2 ignora esses campos/tabela. Preservá-los mantém o histórico para uma nova tentativa de atualização.

Também não reverta as migrations 112 e 113 já homologadas.

## Validação pós-rollback
- [ ] conexão WhatsApp aparece no painel;
- [ ] mensagens entram e saem;
- [ ] IA responde conforme configuração;
- [ ] atendimento humano funciona;
- [ ] relatório mantém SLA homologado na Fase A;
- [ ] Go-Live continua `LIVE` quando aplicável;
- [ ] nenhuma conversa/contato foi removido.

## Compatibilidade histórica da Fase A
**Não remova a migration 112** durante rollback. Se for necessária contingência mais ampla, a versão **36.31.3** pode ser restaurada preservando as migrations aditivas no banco, conforme o procedimento histórico já homologado.
