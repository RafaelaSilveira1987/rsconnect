# RS Connect — v36.20.1

Esta versão revisa a linguagem da aplicação para que pessoas sem conhecimento técnico consigam entender as telas e tomar decisões com segurança.

## O que mudou

- Menus e títulos com nomes mais claros.
- Estados em inglês, como `healthy` e `attention`, convertidos para **Dentro do esperado** e **Precisa de atenção**.
- Termos técnicos substituídos por explicações simples nas telas de uso diário.
- Campos, botões, mensagens de erro, filtros e textos de ajuda revisados.
- Detalhes técnicos preservados apenas em áreas avançadas, logs e documentação de suporte.
- Camada de proteção no navegador para traduzir conteúdos carregados dinamicamente.
- Nova regra de experiência: uma pessoa adolescente ou iniciante deve entender o que a tela faz e qual ação tomar.

## Exemplos

| Antes | Agora |
|---|---|
| Governança de orçamento | Limite de gasto e proteção |
| Telemetria | Dados de uso |
| Rentabilidade IA | Resultados por cliente |
| Memória progressiva | Memória da conversa |
| MRR | Receita mensal |
| Snapshot | Registro mensal |
| Takeover | Assumir atendimento |
| Gateway | Meio de pagamento |
| Credencial | Chave de acesso |
| Prompt | Instruções do assistente |

## Atualização

Não existe migration nova nesta versão.

A última migration obrigatória continua sendo:

```text
database/migrations/084_ai_profitability_history.sql
```

Depois do deploy, faça um rebuild completo e use `Ctrl + F5` para evitar que o navegador carregue textos antigos do cache.

Consulte `INSTRUCOES-v36.20.1.md` e `docs/guias/guia-linguagem-simples.md`.
