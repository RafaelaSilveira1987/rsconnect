# RS Connect — v36.19.2

Esta versão transforma o custo atribuído por empresa em **governança operacional**. O Super Admin pode definir orçamento de IA por empresa, receber alertas e escolher uma ação automática sem interromper atendimento humano ou credenciais próprias do cliente.

## Destaques

- Orçamento em dólar por empresa para IA custeada pela RS Connect.
- Indicadores de custo utilizado, percentual do orçamento, chamadas e tokens no ciclo.
- Alertas configuráveis em níveis de atenção, crítico e limite.
- Ação automática opcional ao atingir atenção: **forçar modo Econômico**.
- Ação final configurável: apenas alertar, manter modo Econômico ou **bloquear novas chamadas custeadas pela RS**.
- Regras locais, cache exato, atendimento humano e credenciais próprias continuam funcionando mesmo no bloqueio.
- Configuração diretamente em **Consumo OpenAI**, filtrando uma empresa.
- Auditoria de mudanças da política e histórico de thresholds disparados.
- Documentação operacional e comercial atualizada (ponto 9).

## Atualização

Atualizando a partir da v36.19.1, execute:

```text
database/migrations/082_ai_budget_governance.sql
```

Depois valide:

```text
database/diagnostics/ai_budget_governance_v36.19.2.sql
```

O primeiro resultado deve retornar `OK`.

Não existem novas variáveis obrigatórias no `.env`.

## Política recomendada para homologação

Comece sem bloqueio automático:

```text
Atenção: 80%
Ação em atenção: Forçar modo Econômico
Crítico: 95%
Limite: 100%
Ação no limite: Somente alertar
```

Depois de validar a cobertura de tarifação e o custo por empresa, a ação final pode ser alterada para **Bloquear IA RS**.

Consulte `INSTRUCOES-v36.19.2.md` e `docs/guias/README.md`.
