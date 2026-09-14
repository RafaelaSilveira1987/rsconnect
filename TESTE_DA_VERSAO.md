# TESTE DA VERSÃO — RS Connect 36.32.2

## Objetivo
Validar o hotfix do cálculo de SLA encontrado na homologação real da Fase A.

O erro confirmado era:

```text
Tempo médio da 1ª resposta humana: 1min 53s
1 resposta medida

SLA da 1ª resposta humana: 0,0%
0/0 em até 30 min
```

Os dois cards usam o mesmo conjunto de ciclos humanos; portanto, esse estado é inválido.

## 1. Atualização
Não existe migration nova nesta versão.

Na raiz do projeto:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

Esperado:
- `up` informa que não há migration pendente, caso a 113 já tenha sido aplicada;
- manifesto: **120 migrations de subida**;
- nenhuma falha de parser.

## 2. Teste principal — repetir o cenário observado
1. mantenha a empresa em `LIVE`;
2. abra **Relatórios**;
3. use um período que contenha a conversa já homologada;
4. mantenha **Meta da 1ª resposta = 30 min**;
5. clique em **Aplicar filtros**.

### Esperado para a resposta de 1min 53s
```text
Tempo médio da 1ª resposta humana
1min 53s
1 resposta medida

SLA da 1ª resposta humana
100,0%
1/1 em até 30 min
```

Se houver outras respostas humanas elegíveis no período, o denominador pode ser maior que 1, mas obrigatoriamente:

```text
first_responses_measured == sla_measured
```

para o mesmo conjunto operacional.

## 3. Teste de uma conversa nova
1. com a empresa `LIVE`, inicie uma conversa/ciclo novo;
2. receba uma mensagem do cliente;
3. responda pelo painel como humano antes de 30 minutos;
4. atualize o relatório.

Esperado:
- o contador de respostas medidas aumenta;
- o denominador do SLA aumenta na mesma quantidade;
- a nova resposta conta como `dentro da meta`.

## 4. Teste do fuso preservado da 36.32.1
Use uma interação feita no fim do dia local e filtre somente até aquele mesmo dia.

Esperado:
- a interação aparece sem precisar acrescentar o dia UTC seguinte;
- horários e agrupamentos continuam no fuso da empresa.


### Regressão histórica da virada UTC
O caso original continua obrigatório: uma interação feita em **11/09/2026** no horário local da empresa deve aparecer com o filtro terminando em **11/09/2026**, sem precisar aumentar artificialmente o período para **12/09** apenas por causa do armazenamento UTC.

## 5. Auditoria de banco opcional
Para confirmar o ciclo humano:

```sql
SELECT id, tenant_id, conversation_id, cycle_number,
       first_incoming_at, first_response_at, first_response_user_id,
       TIMESTAMPDIFF(SECOND, first_incoming_at, first_response_at) AS response_seconds,
       cycle_status, source
FROM conversation_service_cycles
WHERE tenant_id = ID_DA_EMPRESA
  AND first_response_at IS NOT NULL
  AND first_response_user_id IS NOT NULL
ORDER BY id DESC
LIMIT 20;
```

Para uma resposta em `1min 53s`, `response_seconds` deve estar próximo de `113`.

## 6. Diagnóstico de log
A 36.32.2 separa os erros por indicador. Se o card continuar incorreto, procure:

```bash
docker compose logs app --tail=300 | grep -E "reports\.executive\.service-cycle"
```

Não deve existir erro com:

```text
reports.executive.service-cycle.sla
HY093
Invalid parameter number
```

## 7. Go-Live e suspensão
Revalide rapidamente:
- conversa iniciada antes do Go-Live não entra retroativamente no SLA oficial;
- conversa iniciada em `LIVE` entra;
- nova conversa iniciada em `SUSPENDED` não entra como produção oficial.

## 8. Critério de aprovação da Fase A
- [ ] manifesto continua com 120 migrations;
- [ ] `Tempo médio` e `SLA` possuem denominadores consistentes;
- [ ] resposta menor que 30 min aparece dentro da meta;
- [ ] não há `HY093` no log do SLA;
- [ ] filtro de data continua respeitando o fuso local;
- [ ] regras de Go-Live/Suspended permanecem corretas;
- [ ] WhatsApp, IA, humano, agenda e PDF continuam funcionando.

Somente após estes itens a Fase A deve ser encerrada e a Fase B (Evolution Reliability) iniciada.


## Referência histórica da Fase A
Os cenários originais continuam fazendo parte da homologação acumulada:

- **Cenário A — Onboarding**
- **Cenário B — Ready**
- **Cenário C — Go-Live**
- **Cenário D — Métricas depois do Go-Live**
- **Cenário E — Cobrança manual**
- **Cenário F — Suspensão operacional**

Esses itens permanecem dentro dos **Critérios para aprovar a Fase A**.
