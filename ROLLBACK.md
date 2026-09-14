# ROLLBACK — RS Connect 36.34.0

## Quando usar

Use rollback se a Fase C apresentar regressão operacional que impeça a continuidade do atendimento. Prefira corrigir a `36.34.x` quando o problema estiver restrito ao SLA, pois a migration é aditiva.

## Antes de voltar

1. faça backup do banco;
2. registre o erro e horário;
3. preserve logs do container `app`;
4. confirme que a Evolution continua saudável;
5. tenha o ZIP homologado `36.33.0` disponível.

## Retorno de código

Restaure os arquivos da `36.33.0` e reinicie a aplicação:

```bash
docker compose restart app
```

Depois valide WhatsApp, Conversas e Evolution Reliability.

## Banco de dados

**Não remova a migration 115 do histórico e não apague manualmente `tenant_sla_settings`.**

A migration `115_sla_operational_policy.sql` é aditiva. A `36.33.0` ignora os novos campos e a tabela, portanto eles podem permanecer até o hotfix ser aplicado. O trigger de métricas continua compatível com os campos antigos e apenas acrescenta snapshot do SLA.

Não execute `DROP COLUMN` ou `DROP TABLE` em produção como rollback emergencial.

## Retorno para 36.34.x

Quando a correção estiver pronta:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

A migration 115 já aplicada deve ser reconhecida como concluída e não duplicada.

## Validação mínima após rollback

- mensagens entram e saem pelo WhatsApp;
- atendimento humano funciona;
- reconciliação Evolution permanece `healthy`;
- nenhuma conversa ou mensagem foi duplicada;
- relatórios da 36.33.0 continuam acessíveis.
