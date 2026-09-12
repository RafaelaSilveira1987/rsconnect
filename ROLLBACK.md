# ROLLBACK — RS Connect 36.32.1

## Quando usar
Use este procedimento se a 36.32.1 causar regressão durante a homologação do relatório/SLA.

## Rollback recomendado
1. faça backup do banco atual;
2. restaure os arquivos da **36.32.0**;
3. reinicie o container/processo PHP;
4. valide login, WhatsApp, IA, conversas, agenda e relatório.

Exemplo Docker:

```bash
docker compose restart app
```

## Banco de dados
**Não remova as migrations 112 e 113 e não apague dados reparados.** A 36.32.0 ignora o trigger adicional sem precisar de mudança de schema no código PHP.

A migration 113 pode ter preenchido `first_response_at` e `first_response_user_id` a partir de mensagens humanas reais já existentes. Esses dados são correções de consistência e devem ser preservados.

O trigger `trg_rs_messages_after_update_human_metrics` também pode permanecer instalado durante um rollback de arquivos, pois apenas completa métricas quando uma mensagem de saída passa a ser identificada como humana.

## Se for indispensável remover somente o trigger 113
Faça isso apenas para diagnóstico e com backup:

```sql
DROP TRIGGER IF EXISTS trg_rs_messages_after_update_human_metrics;
```

Não reverta os valores reparados dos ciclos.

## Validação pós-rollback
- [ ] login funcionando;
- [ ] mensagens recebidas e enviadas;
- [ ] IA respondendo conforme configuração;
- [ ] fila/atendimento humano funcionando;
- [ ] agenda funcionando;
- [ ] empresa continua com o mesmo lifecycle (`onboarding`, `ready`, `live` ou `suspended`);
- [ ] logs sem erro fatal relacionado ao relatório.

## Compatibilidade com rollback anterior
Não remova a migration 112. Em uma contingência mais ampla, também é possível restaurar os arquivos da 36.31.3, mantendo as migrations 112 e 113 no banco; versões anteriores ignoram os campos adicionais de ciclo operacional.
