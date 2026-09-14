# TESTE DA VERSÃO — RS Connect 36.34.0

## Objetivo

Homologar a **Fase C — SLA operacional**. O foco é provar que o relógio da primeira resposta humana usa a política configurada, gera alerta preventivo antes do estouro, não é encerrado por IA e respeita o expediente quando configurado para isso.

## 1. Atualização

Faça backup do banco e dos arquivos. Depois substitua o pacote e execute:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

Resultado esperado:

```text
[OK] Manifesto: 122 migrations de subida.
```

A migration nova é `115_sla_operational_policy.sql`.

## 2. Configuração de homologação

Na empresa de teste, confirme primeiro que o ciclo operacional está em **LIVE**.

Acesse **Implantação / Regras de atendimento** e configure temporariamente:

- **Meta da 1ª resposta:** `5 min`;
- **Alerta preventivo:** `80%`;
- horário de atendimento: mantenha o horário real da empresa;
- **Contabilizar também fora do horário de atendimento:** para os testes A–D, marque esta opção; no teste E, desmarque.

Com meta de 5 minutos e alerta em 80%:

```text
0:00 ───────── 4:00 ───── 5:00
NORMAL          EM RISCO    VIOLADO
```

Depois de homologar, restaure a meta comercial desejada, por exemplo `30 min`.

## 3. Cenário A — resposta humana antes do alerta

1. Inicie uma conversa nova pelo WhatsApp.
2. Abra a conversa no RS Connect.
3. Responda como humano antes de 4 minutos.
4. Atualize Relatórios.

Esperado:

- a conversa não entra em **SLA em risco**;
- a primeira resposta humana é medida;
- a resposta fica **dentro do SLA**;
- o relógio para no instante da resposta da equipe.

## 4. Cenário B — alerta preventivo em 80%

1. Inicie outra conversa nova.
2. Não dê resposta humana por pelo menos 4 minutos.
3. Mantenha a tela de Conversas aberta.

Esperado ao atingir 80%:

- a linha da conversa recebe **SLA em risco**;
- aparece o percentual e a meta;
- o contador de SLA da caixa de entrada aumenta;
- o supervisor recebe um aviso visual na atualização automática;
- ainda **não** é uma violação.

### Aceleração opcional em ambiente de homologação

Se não quiser aguardar, use apenas uma conversa de teste. Identifique o ciclo ativo e ajuste o relógio para 4 minutos:

```sql
SELECT id, conversation_id, first_incoming_at, first_response_at,
       sla_target_minutes, sla_warning_percent
FROM conversation_service_cycles
WHERE cycle_status = 'active'
  AND first_incoming_at IS NOT NULL
ORDER BY id DESC
LIMIT 10;
```

Depois, substitua `ID_DO_CICLO`:

```sql
UPDATE conversation_service_cycles
SET first_incoming_at = UTC_TIMESTAMP() - INTERVAL 4 MINUTE,
    sla_target_minutes = 5,
    sla_warning_percent = 80,
    sla_count_outside_business_hours = 1
WHERE id = ID_DO_CICLO
  AND first_response_at IS NULL;
```

Aguarde a próxima atualização automática da caixa de entrada.

## 5. Cenário C — violação em 100%

Na mesma conversa de teste, aguarde passar de 5 minutos ou, em homologação, ajuste para 6 minutos:

```sql
UPDATE conversation_service_cycles
SET first_incoming_at = UTC_TIMESTAMP() - INTERVAL 6 MINUTE,
    sla_target_minutes = 5,
    sla_warning_percent = 80,
    sla_count_outside_business_hours = 1
WHERE id = ID_DO_CICLO
  AND first_response_at IS NULL;
```

Esperado:

- a conversa muda para **SLA violado**;
- o contador permanece sinalizando risco;
- o banner da conversa mostra **Prazo excedido**;
- Relatórios contabilizam o ciclo como fora da meta quando houver resposta humana posterior.

## 6. Cenário D — IA não encerra o SLA humano

1. Abra uma conversa nova.
2. Deixe o agente de IA responder.
3. Não responda como humano.
4. Verifique o ciclo:

```sql
SELECT id, conversation_id, first_incoming_at, first_response_at, first_response_user_id
FROM conversation_service_cycles
WHERE conversation_id = ID_DA_CONVERSA
ORDER BY id DESC
LIMIT 1;
```

Esperado após somente a IA responder:

```text
first_incoming_at       preenchido
first_response_at       NULL
first_response_user_id  NULL
```

Somente depois de uma pessoa da equipe responder, `first_response_at` e `first_response_user_id` devem ser preenchidos.

## 7. Cenário E — relógio fora do expediente

Volte às **Regras de atendimento** e desmarque **Contabilizar também fora do horário de atendimento**.

Exemplo: expediente `08:00–18:00`, segunda a sexta.

- uma mensagem recebida às 22:00 não deve acumular quatro horas de SLA durante a madrugada;
- o relógio efetivo começa na próxima abertura;
- uma mensagem de sexta após 18:00 aguarda a abertura de segunda sem consumir o fim de semana;
- depois da abertura, o alerta de 80% e a violação seguem a mesma meta configurada.

Para validar sem esperar a madrugada, use o smoke test da versão:

```bash
php tests/Feature/sla-operational-policy-v36340-smoke.php
```

O teste contém cenários determinísticos de horário comercial e fim de semana.

## 8. Cenário F — consistência dos relatórios

Depois de responder como humano a uma conversa de teste:

- abra o **Relatório executivo**;
- abra **Equipe e profissionais**;
- confira a auditoria/exportação de primeiras respostas.

Esperado:

- o tempo efetivo de primeira resposta é o mesmo conceito em todas as telas;
- o SLA usa a meta configurada como padrão;
- o filtro de meta do relatório pode simular outro limite sem alterar a configuração da empresa;
- a contagem fora do expediente segue o snapshot do ciclo.

## 9. Cenário G — empresa fora de LIVE

Mude temporariamente a empresa para `SUSPENDED` (somente se for seguro no ambiente de homologação).

Esperado:

- atendimento continua preservado conforme a política da Fase A;
- alertas produtivos de SLA não aparecem na caixa de entrada;
- novas interações fora de LIVE não contaminam métricas oficiais.

Retorne para `LIVE` ao final.

## 10. Consultas de confirmação

Configuração vigente:

```sql
SELECT tenant_id, enabled, target_minutes, warning_percent,
       count_outside_business_hours, timezone, business_hours_json, updated_at
FROM tenant_sla_settings
WHERE tenant_id = ID_DA_EMPRESA;
```

Snapshot dos últimos ciclos:

```sql
SELECT id, conversation_id, first_incoming_at, first_response_at, first_response_user_id,
       sla_target_minutes, sla_warning_percent, sla_count_outside_business_hours,
       sla_timezone, sla_business_hours_json
FROM conversation_service_cycles
WHERE tenant_id = ID_DA_EMPRESA
ORDER BY id DESC
LIMIT 10;
```

## Critérios para aprovar a Fase C

A Fase C está homologada somente se todos forem verdadeiros:

- [ ] configuração persiste após recarregar a página;
- [ ] resposta humana antes de 80% não alerta;
- [ ] 80% gera **SLA em risco**;
- [ ] 100% gera **SLA violado**;
- [ ] IA não encerra o relógio humano;
- [ ] resposta humana encerra o relógio;
- [ ] fora do expediente pausa quando a opção está desmarcada;
- [ ] caixa de entrada atualiza os estados automaticamente;
- [ ] relatórios usam o mesmo conceito de tempo efetivo;
- [ ] empresa fora de LIVE não gera alerta produtivo.

Somente depois desta validação avançar para a **Fase D — carga operacional e filas**.
