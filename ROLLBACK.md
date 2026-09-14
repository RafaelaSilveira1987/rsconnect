# ROLLBACK — RS Connect 36.32.2

## Quando usar
Use este procedimento se a 36.32.2 causar regressão durante a homologação do relatório/SLA.

## Rollback recomendado
1. faça backup do banco atual;
2. restaure os arquivos da **36.32.1**;
3. reinicie o container/processo PHP;
4. valide login, WhatsApp, IA, conversas, agenda e relatório.

```bash
docker compose restart app
```

## Banco de dados
A 36.32.2 **não adiciona migration**. Não reverta a migration 113.

Permanece instalada:
- `112_tenant_lifecycle_go_live.sql`;
- `113_human_first_response_report_consistency.sql`.

Os dados de `first_response_at` e `first_response_user_id` reparados pela 113 devem ser preservados.

## Validação pós-rollback
- [ ] login funcionando;
- [ ] mensagens recebidas e enviadas;
- [ ] IA conforme configuração;
- [ ] atendimento humano e fila funcionando;
- [ ] agenda funcionando;
- [ ] lifecycle da empresa preservado;
- [ ] relatório abre sem erro fatal.

A 36.32.1 pode voltar a apresentar `SLA 0/0` por causa do placeholder PDO duplicado; o rollback é apenas contingencial.


## Compatibilidade histórica
**Não remova a migration 112** durante rollback. Em contingência mais ampla, os arquivos da **36.31.3** também podem ser restaurados mantendo as migrations 112 e 113 no banco; versões anteriores ignoram os campos adicionais.
