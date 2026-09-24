# RS Connect 36.36.20 — Validação do runtime do agente

## Objetivo

Esta versão consolida o contrato entre o que o usuário configura na tela de Assistentes e o que o runtime realmente executa.

## Princípios

1. Conteúdo e regras de negócio vêm do banco/configuração da empresa e do agente.
2. A Ordem do atendimento usa vínculos persistidos entre etapa e informação; o PHP não possui mapa de nicho para decidir qual campo pertence a cada etapa.
3. Campos obrigatórios antes da agenda continuam protegidos pelo Policy Engine.
4. Configuração inconsistente falha de forma segura; a IA não improvisa uma regra ausente.
5. A demanda não é inferida por listas de sintomas no código. Ela é registrada quando a etapa configurada de demanda está sendo respondida.
6. Agenda, disponibilidade real, modalidade, aprovação humana e retorno fora do horário continuam protegidos pelas camadas determinísticas existentes.

## Migration obrigatória

Execute:

```bash
php bin/migrate.php up
php bin/migrate.php verify
```

A versão exige `119_agent_workflow_runtime_contract.sql`.

## O que validar na tela de Assistentes

No bloco **Regras do atendimento** deve aparecer **Validação antes de atender**.

- `Configuração válida`: o fluxo possui vínculo executável entre etapas e informações.
- `Corrigir`: existe inconsistência que deve ser resolvida antes de liberar a automação.
- `Atenção`: configuração permitida, mas merece revisão.

Cada etapa de coleta mostra **Informações desta etapa**. Esta é a associação que o runtime usa.

## Homologação recomendada

Crie uma conversa nova e valide a sequência configurada. Exemplo, se a ordem salva for:

1. Identificar quem será atendido
2. Coletar idade
3. Coletar modalidade
4. Coletar demanda
5. Coletar preferência de agenda
6. Consultar agenda

O atendimento não deve solicitar preferência antes da demanda quando a demanda estiver configurada como obrigatória antes da agenda.

Depois repita um teste iniciado fora do horário. A retomada deve ocorrer na abertura do expediente sem perder apresentação, estado da triagem ou proteção contra duplicidade.
