# TESTE DA VERSÃO — RS Connect 36.32.1

## Objetivo
Validar o hotfix encontrado durante a homologação da Fase A: o dia selecionado no relatório deve respeitar o fuso da empresa, e **Tempo médio da 1ª resposta humana** e **SLA da 1ª resposta humana** devem medir exatamente o mesmo conjunto de respostas humanas.

Esta versão **não inicia a Fase B**. A Fase A só é aprovada depois destes testes.

## 1. Pré-requisitos
- backup recente do banco e dos arquivos;
- RS Connect 36.32.0 já funcionando ou instalação que executará todas as migrations pendentes;
- empresa de teste com ciclo operacional em `LIVE`;
- fuso da empresa configurado como `America/Sao_Paulo` para reproduzir o caso observado;
- acesso ao WhatsApp e ao atendimento humano no RS Connect.

## 2. Aplicar e validar a migration
Na raiz do projeto:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
```

Resultado esperado:
- `113_human_first_response_report_consistency.sql` executada uma vez;
- manifesto com **120 migrations de subida**;
- nenhum erro de parser;
- trigger `trg_rs_messages_after_update_human_metrics` existente.

Consulta opcional:

```sql
SHOW TRIGGERS LIKE 'conversation_messages';
```

Deve existir o trigger `trg_rs_messages_after_update_human_metrics` além dos triggers anteriores.

## 3. Validar o fuso do relatório — reprodução do problema de 11/09
Este teste reproduz exatamente a situação encontrada na homologação.

### Configuração
1. mantenha a empresa em `LIVE`;
2. confirme o fuso `America/Sao_Paulo`;
3. faça o teste próximo ao fim do dia local ou use uma conversa cuja interação ocorreu em **11/09/2026** à noite;
4. abra **Relatórios**;
5. filtre **DE 13/08/2026 ATÉ 11/09/2026**.

### Esperado
Uma interação ocorrida em 11/09 no horário local deve aparecer no relatório de 11/09 mesmo quando o banco a persistir em 12/09 UTC.

Para `America/Sao_Paulo`, o intervalo local:

```text
11/09/2026 00:00:00 -03
até
11/09/2026 23:59:59 -03
```

corresponde a:

```text
11/09/2026 03:00:00 UTC
até
12/09/2026 02:59:59 UTC
```

**Não deve mais ser necessário aumentar o filtro para 12/09 apenas para enxergar uma resposta feita no dia 11 local.**

## 4. Validar tempo médio + SLA com a mesma conversa
Com a empresa em `LIVE`, crie **um novo ciclo depois do Go-Live**:

1. cliente envia uma nova mensagem;
2. aguarde alguns segundos;
3. responda como atendente humano pelo RS Connect;
4. abra o relatório incluindo o dia local da conversa;
5. use meta de primeira resposta de **30 minutos**.

### Esperado
Se a resposta ocorreu, por exemplo, em **1min 53s**, os dois cards devem medir a mesma resposta:

```text
Tempo médio da 1ª resposta humana
1min 53s
1 resposta medida

SLA da 1ª resposta humana
100,0%
1/1 em até 30 min
```

É falha se ocorrer qualquer combinação como:

```text
Tempo médio: 1 resposta medida
SLA: 0/0
```

ou o inverso.

## 5. Validar a reparação do dado já existente
A migration 113 tenta recuperar ciclos em que já existe uma mensagem humana real, mas `first_response_user_id` ficou vazio.

Consulta sugerida antes/depois, caso queira auditar:

```sql
SELECT id, tenant_id, conversation_id, cycle_number,
       first_incoming_at, first_response_at, first_response_user_id, source
FROM conversation_service_cycles
WHERE tenant_id = ID_DA_EMPRESA
ORDER BY id DESC
LIMIT 20;
```

Para o ciclo usado no teste, o esperado é:
- `first_incoming_at` preenchido;
- `first_response_at` preenchido;
- `first_response_user_id` preenchido com o usuário que respondeu.

## 6. Validar a corrida Evolution × painel
Este cenário valida a proteção nova sem exigir manipulação de banco.

1. mantenha a instância WhatsApp conectada normalmente;
2. receba uma mensagem do cliente;
3. responda rapidamente pelo painel;
4. aguarde o eco/status da Evolution;
5. recarregue a conversa e o relatório.

Esperado:
- apenas uma mensagem humana visível;
- `sender_type = user` e `sender_user_id` preenchido na mensagem enviada pelo painel;
- primeira resposta humana persistida no ciclo;
- tempo médio e SLA continuam com o mesmo denominador.

Consulta opcional:

```sql
SELECT id, conversation_id, evolution_message_id, direction, sender_type,
       sender_user_id, sent_at
FROM conversation_messages
WHERE conversation_id = ID_DA_CONVERSA
ORDER BY sent_at, id;
```

## 7. Validar que o Go-Live continua protegendo o histórico
Use duas conversas/ciclos:

- **A:** iniciada em `ONBOARDING` ou `READY` antes do Go-Live;
- **B:** iniciada depois de `LIVE`.

Responda humanamente nas duas.

Esperado:
- A não entra retroativamente no SLA oficial;
- B entra no tempo médio e no SLA;
- se B estiver dentro de 30 min, o SLA deve mostrar pelo menos `1/1 em até 30 min`.

## 8. Validar período comparativo e gráficos
No relatório:
1. escolha um intervalo com atividade em mais de um dia;
2. confira o gráfico diário;
3. confira interações por horário e heatmap.

Esperado:
- mensagens noturnas não “pulam” para o dia seguinte por causa do UTC;
- os horários apresentados correspondem ao fuso da empresa;
- os comparativos usam intervalos locais equivalentes.

## 9. Teste de suspensão
Depois dos testes anteriores:
1. altere a empresa de `LIVE` para `SUSPENDED`;
2. faça nova interação;
3. confira o relatório;
4. retorne a `LIVE` após o teste.

Esperado: a nova interação durante suspensão não cria SLA oficial de produção, preservando o comportamento da 36.32.0.

## 10. Critérios para aprovar a Fase A
Só avançar para Evolution Reliability quando todos estiverem verdadeiros:

- [ ] `php bin/migrate.php verify` informa 120 migrations.
- [ ] O filtro até 11/09 inclui interações de 11/09 à noite no fuso da empresa.
- [ ] Não é necessário colocar 12/09 para compensar UTC.
- [ ] Tempo médio e SLA possuem o mesmo número de respostas medidas.
- [ ] Uma resposta em menos de 30 min gera `1/1 em até 30 min` no cenário unitário.
- [ ] `first_response_user_id` fica preenchido para resposta humana do painel.
- [ ] Conversas anteriores ao Go-Live continuam fora do SLA oficial.
- [ ] Conversas posteriores ao Go-Live entram no SLA.
- [ ] Suspensão continua pausando novas métricas oficiais.
- [ ] WhatsApp, IA, atendimento humano, agenda e PDF continuam funcionando.

## 11. Evidências sugeridas
Salve quatro prints:
1. empresa em `LIVE`;
2. conversa nova com horário da entrada e resposta humana;
3. relatório filtrado terminando no próprio dia local (ex.: 11/09/2026);
4. cards **Tempo médio da 1ª resposta humana** e **SLA da 1ª resposta humana** mostrando o mesmo denominador.

Se esses testes passarem, a Fase A pode ser encerrada e iniciamos a Fase B — Evolution Reliability.

## Referência da homologação 36.32.0 preservada
Os cenários completos da Fase A continuam válidos e devem permanecer aprovados após este hotfix:

- **Cenário A — Onboarding:** homologação sem SLA oficial;
- **Cenário B — Ready:** ambiente pronto, ainda fora de produção;
- **Cenário C — Go-Live:** entrada explícita em produção;
- **Cenário D — Métricas depois do Go-Live:** apenas ciclos produtivos entram no SLA;
- **Cenário E — Cobrança manual:** liberada somente em `LIVE`;
- **Cenário F — Suspensão operacional:** pausa novas métricas oficiais sem apagar histórico.

Esses itens fazem parte dos **Critérios para aprovar a Fase A** e devem ser rechecados caso o hotfix altere algum comportamento operacional.
