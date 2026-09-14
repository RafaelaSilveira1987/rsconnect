# TESTE DA VERSÃO — RS Connect 36.34.4

## Hotfix H4 — estado atual do expediente prevalece sobre histórico da IA

### 1. Atualizar

Não há migration nova.

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

**Esperado:** manifesto com **123 migrations de subida** e nenhuma migration pendente.

### 2. Manter a configuração real

Para o agente Digi, preserve Seg–Sex, `08:00–18:00`, `America/Sao_Paulo`. Não apague o histórico antigo da conversa; ele faz parte deste teste.

### 3. Repetir dentro do expediente

Em uma segunda-feira entre 08:00 e 18:00, envie uma mensagem nova como:

```text
Teste horário 36.34.4
```

**Esperado:** a mensagem entra normalmente e a resposta automática **não** pode ser `Estamos fora do horário de atendimento agora.`.

### 4. Conferir o log da IA

```sql
SELECT id, incoming_message_id, event, status, response_preview, error_message, raw_json, created_at
FROM ai_automation_logs
WHERE tenant_id = SEU_TENANT_ID
  AND conversation_id = SUA_CONVERSA
ORDER BY id DESC
LIMIT 20;
```

**Esperado:** resposta normal via `ai.replied`, sem novo `ai.after_hours`. Caso o provedor ainda tente afirmar falsamente que está fechado, deve surgir `ai.operating_policy.blocked` e a frase incorreta não deve ser enviada ao WhatsApp.

### 5. Validar o fechamento real

Depois das 18:00, ou temporariamente com uma faixa que exclua o horário atual, envie nova mensagem.

**Esperado:** agora sim o backend pode registrar `ai.after_hours` e enviar a mensagem configurada de ausência.

### 6. Retomar Fase C

Após validar dentro/fora do expediente, retome os cenários do SLA com conversa nova: dentro da meta → alerta em 80% → violação em 100% → primeira resposta humana.

---

# TESTE DA VERSÃO — RS Connect 36.34.3

## Hotfix H3 — expediente do onboarding interpretado corretamente

### 1. Atualizar

Não há migration nova. Depois de substituir os arquivos:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

**Esperado:** manifesto com **123 migrations de subida** e nenhuma migration pendente.

### 2. Conferir o JSON salvo

```sql
SELECT id, name, business_hours_enabled, business_timezone, business_hours_json
FROM ai_agents
WHERE tenant_id = SEU_TENANT_ID
ORDER BY id;
```

O formato compacto abaixo é válido e não precisa ser convertido manualmente:

```json
{"days":["mon","tue","wed","thu","fri"],"start":"08:00","end":"18:00"}
```

### 3. Testar dentro do expediente

Configure Seg–Sex, `08:00–18:00`, `America/Sao_Paulo`. Em uma segunda-feira entre 08:00 e 18:00, envie uma mensagem nova.

**Esperado:** a mensagem entra normalmente e o agente **não** envia a mensagem configurada de fora do horário.

### 4. Testar fora do expediente

Após 18:00, ou usando temporariamente uma faixa que exclua o horário atual, envie outra mensagem nova.

**Esperado:** a política retorna fora do expediente e usa a mensagem configurada para esse cenário.

### 5. Retomar o SLA

Volte à meta temporária de 5 min / alerta 80% e crie uma conversa nova para os cenários normal → warning → breached.

---

# TESTE DA VERSÃO — RS Connect 36.34.2

## Hotfix H2 — recebimento Evolution bloqueado pelo trigger de SLA

### 1. Aplicar a correção

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

**Esperado:** manifesto com **123 migrations de subida** e execução de `116_sla_trigger_mysql_compat.sql`.

### 2. Confirmar o trigger corrigido

```sql
SHOW CREATE TRIGGER trg_rs_messages_after_insert_metrics;
```

**Esperado:** o trigger seleciona o ciclo ativo com `SELECT ... ORDER BY ... LIMIT 1` e depois faz `UPDATE conversation_service_cycles ... WHERE id = active_cycle_id`. Não deve existir `UPDATE ... LEFT JOIN ... ORDER BY ... LIMIT`.

### 3. Enviar uma mensagem nova pelo WhatsApp

Envie, por exemplo:

```text
RS TESTE 36.34.2
```

Depois confira:

```sql
SELECT id, conversation_id, evolution_message_id, direction, sender_type, content, sent_at
FROM conversation_messages
ORDER BY id DESC
LIMIT 10;
```

**Esperado:** a mensagem nova aparece uma única vez.

### 4. Conferir o ledger do webhook

```sql
SELECT id, status, attempts, duplicate_count, response_code, last_error, first_received_at, last_received_at
FROM webhook_security_events
WHERE source = 'evolution'
ORDER BY id DESC
LIMIT 20;
```

**Esperado para o novo `MESSAGES_UPSERT`:** `status = processed`, `response_code = 200` e `last_error = NULL`. Eventos antigos que falharam antes da migration podem permanecer no histórico como evidência.

### 5. Confirmar que o SLA ainda registra a entrada

```sql
SELECT id, conversation_id, first_incoming_at, first_response_at, first_response_user_id,
       sla_target_minutes, sla_warning_percent, sla_count_outside_business_hours
FROM conversation_service_cycles
ORDER BY id DESC
LIMIT 10;
```

**Esperado:** o ciclo da conversa nova possui `first_incoming_at` e snapshot de SLA. Depois disso, retome os cenários da Fase C abaixo.

---

# TESTE DA VERSÃO — RS Connect 36.34.1

## Hotfix — salvamento das Regras de atendimento

Antes de continuar os cenários da Fase C abaixo, valide o problema observado na 36.34.0.

### Teste H1 — salvar regras e SLA
1. Abra Implantação / Regras de atendimento.
2. Configure horário, dias, fuso e SLA (ex.: 15 min / 80%).
3. Clique em Salvar.

**Esperado:** mensagem de sucesso, sem `Warning 1265 Data truncated for column handoff_action`, e os valores persistem após recarregar a página.

### Teste H2 — conferir o agente

```sql
SELECT id, name, handoff_action, business_hours_enabled, business_timezone
FROM ai_agents
WHERE tenant_id = SEU_TENANT_ID
ORDER BY id;
```

**Esperado:** `handoff_action` deve ser apenas `paused` ou `human`. O onboarding sincroniza `paused` ao reaplicar as regras de atendimento.

### Teste H3 — conferir a política de SLA

```sql
SELECT tenant_id, enabled, target_minutes, warning_percent, count_outside_business_hours, timezone, updated_at
FROM tenant_sla_settings
WHERE tenant_id = SEU_TENANT_ID;
```

**Esperado:** os valores correspondem ao formulário salvo.

---

## Roteiro completo da Fase C

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
