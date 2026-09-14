# TESTE DA VERSÃO — RS Connect 36.33.0

## Fase B — Evolution Reliability

## Objetivo
Provar que o WhatsApp continua operacional mesmo quando o estado local fica desatualizado, a Evolution cai temporariamente ou o mesmo webhook chega mais de uma vez.

A regra desta fase é:

```text
Webhook = caminho rápido
Reconciliação = caminho de recuperação
Banco local não deve ser a única fonte de verdade do estado da conexão
```

## 1. Atualização
Faça backup antes da atualização. Na raiz do projeto:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

Esperado:

```text
[OK] Manifesto: 121 migrations de subida.
[OK] 1 migration(s) executada(s):
- 114_evolution_reconciliation_observability.sql
```

Em instalações que já executaram a migration, `up` pode informar que não existem migrations pendentes.

## 2. Configuração
Abra **Canais WhatsApp** e localize a conexão usada na homologação.

Mantenha:
- número autorizado correto;
- **Recuperação automática: Ativa**;
- webhook habilitado;
- eventos mínimos `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`, `QRCODE_UPDATED`, `CONTACTS_UPSERT` e `CONTACTS_UPDATE`.

O card deve mostrar:

```text
Estado no RS Connect
Estado observado na Evolution
Última reconciliação
Último webhook
```

As ações agora têm responsabilidades diferentes:

```text
Diagnosticar       = testa estado + webhook + settings
Reconciliar agora  = compara RS Connect x Evolution e corrige o estado local
Reaplicar webhook  = reaplica webhook e settings salvos
Recuperar conexão  = tenta recuperar/reiniciar uma queda técnica elegível
```

## 3. Teste A — estado saudável
1. deixe o WhatsApp conectado;
2. clique **Reconciliar agora**;
3. atualize a página.

Esperado:
- Estado no RS Connect: `open`, `connected`, `online` ou `active`;
- Estado observado na Evolution: estado conectado equivalente;
- badge **Reconciliação: Consistente**;
- `reconciliation_failures = 0`;
- nova linha em `evolution_reconciliation_runs` com `result_status = healthy`.

Consulta opcional:

```sql
SELECT id, connection_state, remote_connection_state, reconciliation_status,
       reconciliation_reason, last_reconciled_at, reconciliation_failures
FROM evolution_instances
ORDER BY id DESC;
```

## 4. Teste B — estado local propositalmente divergente
Este teste altera apenas o estado local e deve ser feito na conexão de homologação.

1. anote o `id` da instância;
2. com a Evolution realmente conectada, execute:

```sql
UPDATE evolution_instances
SET status = 'disconnected', connection_state = 'disconnected'
WHERE id = SEU_ID;
```

3. abra **Canais WhatsApp** sem reiniciar a Evolution;
4. clique **Reconciliar agora**.

Esperado:
- o estado volta para conectado;
- `remote_connection_state` mostra o estado observado na Evolution;
- `reconciliation_status = corrected`;
- histórico registra `action_taken = local_state_updated`;
- nenhuma conversa, contato ou mensagem é duplicada.

## 5. Teste C — Evolution temporariamente indisponível
Faça este teste somente se puder interromper a Evolution de homologação por alguns minutos.

1. pare ou torne a Evolution inacessível;
2. clique **Reconciliar agora**.

Esperado:
- a tela informa falha da Evolution;
- `reconciliation_status = unreachable`;
- `reconciliation_failures` aumenta;
- o histórico registra a tentativa e o erro;
- contatos/conversas/mensagens existentes não são apagados;
- o sistema não inventa estado `connected`.

Religue a Evolution e clique **Reconciliar agora** novamente.

Esperado:
- `reconciliation_failures` volta para `0`;
- estado local volta a refletir o estado real;
- a nova tentativa aparece no histórico.

## 6. Teste D — queda recuperável
Com **Recuperação automática: Ativa**:

1. provoque uma queda técnica sem logout voluntário e sem trocar o número;
2. execute o monitor operacional ou aguarde a rotina configurada. Para forçar o ciclo pela CLI:

```bash
php bin/operations-monitor.php
```

3. acompanhe a conexão.

Esperado:

```text
reconciliação detecta estado remoto
↓
monitor classifica queda
↓
recuperação automática tenta restart
↓
conexão volta a connected/open
```

O sistema **não** deve reiniciar automaticamente quando o estado for:
- `logged_out` / logout voluntário;
- QR Code pendente;
- `identity_mismatch`.

## 7. Teste E — webhook duplicado
Use preferencialmente uma ferramenta de replay/HTTP ou o payload de homologação da Evolution.

Envie duas vezes o mesmo `MESSAGES_UPSERT` com o mesmo `key.id`/event id.

Esperado:

```text
1 mensagem persistida
1 processamento da automação
1 consumo/resposta da IA, quando aplicável
0 conversas duplicadas
```

Validação do ledger:

```sql
SELECT source, event_key, status, attempts, duplicate_count, response_code, last_error
FROM webhook_security_events
WHERE source = 'evolution'
ORDER BY id DESC
LIMIT 20;
```

Na repetição, `duplicate_count` deve aumentar sem repetir o efeito de negócio.

## 8. Teste F — segurança de identidade
Com o número autorizado preenchido, **não conecte intencionalmente outro número em produção**. Este cenário pode ser validado apenas em ambiente controlado.

Esperado diante de divergência real:
- `identity_status = mismatch`;
- `reconciliation_status = identity_mismatch`;
- mensagens de entrada e saída ficam bloqueadas pela política já existente;
- **Recuperar conexão** não transforma o estado em saudável sem corrigir o número.

## 9. Teste G — Reaplicar webhook
1. clique **Diagnosticar**;
2. se o diagnóstico indicar falha de webhook/settings, clique **Reaplicar webhook**;
3. clique **Diagnosticar** novamente.

Esperado:
- webhook acessível;
- settings acessíveis;
- recebimento de uma nova mensagem continua funcionando.

## 10. Critério de aprovação da Fase B
Marque como aprovada somente se:

- [ ] reconciliação saudável funciona;
- [ ] estado local divergente é corrigido pela Evolution;
- [ ] indisponibilidade não produz falso `connected`;
- [ ] recuperação automática não interfere em logout/QR/mismatch;
- [ ] webhook duplicado não duplica mensagem/conversa/IA;
- [ ] diagnóstico e reaplicação de webhook funcionam pelo painel;
- [ ] envio e recebimento continuam normais depois dos testes;
- [ ] migration 114 e manifesto 121 estão válidos.

Depois disso, seguimos para a **Fase C — SLA operacional e alerta preventivo de 80%**.

---

## Compatibilidade dos cenários já homologados
Os testes das versões anteriores permanecem válidos e devem continuar passando como regressão.

### Fase A — referências preservadas
- **Cenário A — Onboarding**: atendimento funciona, mas métricas oficiais não contam.
- **Cenário C — Go-Live**: nova conversa iniciada em LIVE entra nas métricas oficiais.
- **Critérios para aprovar a Fase A**: ciclo operacional, auditoria, SLA e suspensão já foram homologados.

### Hotfix 36.32.1 — virada UTC reproduzida
Cenário histórico de **11/09/2026** em `America/Sao_Paulo`: a janela local alcança **12/09** em UTC, sem obrigar o usuário a ampliar manualmente o filtro. Para uma resposta humana de 1min53s e meta de 30 minutos, o resultado esperado permanece **1/1 em até 30 min**.

### Hotfix 36.32.2 — PDO nativo
O erro histórico **HY093** foi eliminado com placeholders exclusivos no cálculo do SLA. A Fase B não altera esse cálculo.
