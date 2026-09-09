# RS Connect 36.28.1 — Modelos de atendimento em linguagem simples

Esta atualização mantém a arquitetura de segurança da versão 36.28.0 e melhora a experiência de configuração no RS Admin.

## Principais mudanças

- `Nichos e blueprints` passou a ser apresentado como **Modelos por segmento**.
- `Blueprint` passou a ser apresentado como **Modelo de atendimento**.
- `Capabilities` passaram a ser **O que o assistente pode fazer**.
- `Policy Engine` passou a ser apresentado como **Regras de segurança** / **Proteção automática**.
- `Triagem estruturada` passou a ser **Informações que devem ser coletadas**.
- `Workflow` passou a ser **Passo a passo do atendimento**.
- chaves internas, códigos e JSON ficaram recolhidos em áreas de **Configuração técnica**.
- a configuração da empresa recebeu cards, hierarquia visual e textos explicativos em linguagem operacional.
- o histórico do motor de regras agora traduz decisões como `allow`, `block`, `collect` e `handoff` para termos comuns.
- o cadastro de empresa usa **Segmento** e **Modelo de atendimento** em vez dos nomes técnicos internos.

## Importante

Não há nova migration nesta versão. A migration obrigatória continua sendo:

`103_agent_blueprints_policy_engine.sql`

A lógica de segurança, elegibilidade e bloqueio da agenda não foi afrouxada; esta atualização é de UX, linguagem e apresentação.
