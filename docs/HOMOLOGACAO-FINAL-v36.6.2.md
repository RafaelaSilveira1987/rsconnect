# RS Connect — Homologação final v36.6.2

## Objetivo desta versão

Criar uma nova experiência de operação sem substituir ou alterar a Central de operação existente.

## Nova opção

- Menu: **Operação RS → Painel operacional**
- Rota principal: `/painel-operacional`
- Alias: `/operacao-rs`
- A Central de operação permanece disponível e intacta em `/central-operacao`.

## Princípio de UX

A nova tela trabalha por exceção: problemas e ações aparecem primeiro; itens saudáveis permanecem compactos. O detalhamento técnico continua na Central original.

## Blocos da tela

1. Saúde geral e contadores de criticidade.
2. Problemas que precisam de ação, com impacto e atalhos.
3. Serviços operando normalmente, em formato compacto.
4. Rotinas automáticas essenciais.
5. Situação por empresa: WhatsApp, IA, Agenda e cobrança.
6. Atalho explícito para a Central de operação técnica.

## Regra de segurança

Nenhuma lógica existente da Central foi removida. Esta versão adiciona uma camada de apresentação agregada sobre os serviços já existentes.
