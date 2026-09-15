# Homologação final — RS Connect 36.36.1

> **Escopo do preflight 36.36.1:** somente tenants `LIVE` podem gerar bloqueio de Evolution/WhatsApp e entram na carga operacional produtiva. Canais e conversas de `ONBOARDING`, `READY` ou `SUSPENDED` aparecem apenas como informação e não bloqueiam a release.

Esta fase não amplia o produto. Ela prova que o núcleo já homologado continua coerente de ponta a ponta e deixa evidência objetiva para o primeiro release comercial estável.

## 1. Pré-requisitos

- empresa de teste em `LIVE`;
- Evolution conectada, identidade `verified` e reconciliação `healthy`/`corrected`;
- agente com expediente e SLA configurados;
- pelo menos um usuário humano apto a assumir/responder conversas;
- backup recente do banco e dos uploads.

## 2. Preflight automático

Na raiz do projeto:

```bash
php bin/migrate.php verify
php bin/production-readiness.php
```

Critério:

- `BLOQUEADO`: não seguir;
- `PRONTO COM ATENÇÃO`: registrar a atenção e validar se é operacional/esperada;
- `PRONTO PARA HOMOLOGAÇÃO FINAL`: seguir.

## 3. Matriz E2E obrigatória

### E1 — Lead novo / entrada WhatsApp

1. Envie uma mensagem de um número ainda não conhecido.
2. Confirme criação/continuidade de uma única conversa.
3. Confirme resposta do agente sem duplicidade.

**Aprova quando:** uma mensagem recebida gera uma única persistência e uma única sequência de automação.

### E2 — Cliente/paciente reconhecido

1. Use um contato já identificado.
2. Envie uma saudação simples.
3. Confirme abordagem natural conforme política de saudação do agente.

**Aprova quando:** não reinicia triagem de lead e não repete saudação indevidamente.

### E3 — Handoff humano

1. Peça atendimento humano ou use uma regra que provoque handoff.
2. Assuma a conversa.
3. Responda como humano.

**Aprova quando:** IA pausa conforme a configuração, responsável é registrado e a resposta humana chega ao WhatsApp.

### E4 — SLA dentro da meta

1. Abra um novo ciclo.
2. Responda como humano antes da meta.
3. Abra Relatórios.

**Aprova quando:** primeira resposta humana aparece no ciclo e o relatório contabiliza dentro da meta.

### E5 — SLA violado

1. Abra outro ciclo.
2. Deixe passar alerta preventivo e meta.
3. Responda somente depois da violação.

**Aprova quando:** Carga Operacional migra `normal → em risco → violado`; após resposta, o incidente atual sai da carga, mas o relatório preserva a violação histórica.

### E6 — Encerrar e reabrir

1. Encerre uma conversa com ciclo registrado.
2. Envie nova mensagem do mesmo contato.

**Aprova quando:** novo ciclo é criado sem apagar o anterior e o SLA recomeça de forma independente.

### E7 — Transferência de responsável

1. Assuma como usuário A.
2. Transfira para usuário B.
3. Verifique Carga Operacional.

**Aprova quando:** A perde 1 ativo, B ganha 1 ativo e o total não muda.

### E8 — Evolution: reconciliação

1. Execute **Reconciliar agora** com a instância saudável.
2. Confirme `open/open`, identidade verificada e resultado saudável.

**Aprova quando:** não cria mensagens/conversas extras e a reconciliação é auditada.

### E9 — Evolution: idempotência

Repita o mesmo `MESSAGES_UPSERT` duas vezes usando um evento controlado.

**Aprova quando:** primeiro evento é processado e o segundo retorna/é registrado como duplicado, com uma única `conversation_messages` para o `evolution_message_id`.

### E10 — Relatório e PDF

1. Abra relatório executivo no período que contém E4 e E5.
2. Confirme média e percentual do SLA.
3. Salve PDF.

**Aprova quando:** números da tela e do PDF usam o mesmo período/fuso e o PDF é gerado sem erro.

### E11 — Carga Operacional

1. Crie uma conversa sem responsável.
2. Assuma, transfira e encerre.

**Aprova quando:** `Sem responsável`, `Em atendimento humano`, responsável individual e `Total ativo` acompanham todas as mudanças sem duplicidade.

### E12 — Go-Live / suspensão

1. Em tenant de homologação, altere `LIVE → SUSPENDED`.
2. Confirme que novas métricas oficiais deixam de crescer.
3. Retorne para `LIVE`.

**Aprova quando:** operação histórica é preservada e a retomada só contabiliza os novos períodos LIVE.

## 4. Evidências mínimas

Registre para cada cenário: data/hora, `conversation_id` quando aplicável, print da tela, resultado esperado e resultado obtido.

| Cenário | Resultado | Evidência | Observação |
|---|---|---|---|
| E1 | ☐ | | |
| E2 | ☐ | | |
| E3 | ☐ | | |
| E4 | ☐ | | |
| E5 | ☐ | | |
| E6 | ☐ | | |
| E7 | ☐ | | |
| E8 | ☐ | | |
| E9 | ☐ | | |
| E10 | ☐ | | |
| E11 | ☐ | | |
| E12 | ☐ | | |

## 5. Critério de release

A release candidate pode ser aprovada quando:

- `php bin/production-readiness.php` não tiver bloqueios;
- E1–E12 estiverem aprovados ou houver justificativa explícita para cenário não aplicável;
- não houver erro 5xx recorrente no webhook Evolution;
- não houver divergência de identidade ativa;
- SLA da caixa, Carga Operacional e Relatórios estiver coerente;
- rollback e backup tiverem sido confirmados.

## 6. Após aprovação

Congele novas features até pelo menos um ciclo real de operação monitorada. Correções críticas podem entrar em `36.36.x`; novas funcionalidades devem iniciar uma linha posterior.
