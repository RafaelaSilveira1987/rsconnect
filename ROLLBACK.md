# ROLLBACK — RS Connect 36.32.0

## Quando usar
Use este procedimento se a 36.32.0 causar regressão que impeça a operação normal durante a homologação.

## Rollback recomendado
1. faça backup do banco atual;
2. restaure os arquivos da 36.31.3;
3. reinicie o container/processo PHP;
4. valide login, WhatsApp, IA, conversas e agenda.

Exemplo Docker:

```bash
docker compose restart app
```

## Banco de dados
**Não remova a migration 112 nem apague as colunas/tabela.** A 36.31.3 ignora os campos adicionais, portanto a forma mais segura de rollback é somente voltar o código.

Manter os dados de `tenant_lifecycle_events` preserva a auditoria feita durante a homologação e facilita retornar à 36.32.0 depois da correção.

## Caso uma empresa tenha sido colocada em LIVE por engano
Antes de voltar o código, pelo painel 36.32.0 retorne a empresa para `READY`, registrando o motivo. Isso cria uma trilha de auditoria correta.

Se o painel estiver indisponível e for indispensável corrigir diretamente no banco:

```sql
UPDATE tenants
SET lifecycle_status = 'ready', lifecycle_changed_at = UTC_TIMESTAMP()
WHERE id = ID_DA_EMPRESA;
```

Depois registre manualmente o incidente. O SQL acima é apenas contingência e não cria o evento/auditoria completo.

## Validação pós-rollback
- [ ] login funcionando;
- [ ] mensagens recebidas e enviadas;
- [ ] IA respondendo conforme configuração;
- [ ] fila/atendimento humano funcionando;
- [ ] agenda funcionando;
- [ ] logs sem erro fatal relacionado a `lifecycle_status`.
