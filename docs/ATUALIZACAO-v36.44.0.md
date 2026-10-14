# Atualização RS Connect 36.44.0

## Objetivo

A versão 36.44.0 inicia a **Ativação Assistida** adicionando uma fronteira clara entre o que a IA pode redigir e o que a empresa precisa decidir de forma determinística. O novo passo **Limites e Condutas** grava políticas estruturadas e as executa antes da resposta da IA.

## O que configurar

Em **Primeiros passos → Limites e condutas** revise:

- pedidos de desconto;
- reembolso/estorno;
- pedidos de exceção/encaixe;
- idade mínima e máxima;
- casos ou serviços específicos não atendidos;
- situações urgentes/sensíveis quando a empresa desejar uma resposta controlada.

Cada regra permite definir a ação e a resposta aprovada. `Encaminhar` pausa a automação e coloca a conversa para atendimento humano. `Informar e continuar` envia a resposta configurada sem permitir que a IA negocie no mesmo turno. `Não permitir agenda` bloqueia somente ações de agenda e mantém a conversa disponível.

## Homologação recomendada

1. Configure desconto como **Encaminhar para responsável**.
2. Envie “Se eu fechar hoje, consegue me dar desconto?”.
3. Confirme que a resposta é exatamente a cadastrada e que a conversa entra em atendimento humano.
4. Envie uma nova conversa sem pedido de desconto e confirme que a intenção anterior não permanece ativa.
5. Configure idade mínima/máxima e teste dentro e fora da faixa.
6. Cadastre um caso extra objetivo e valide o comportamento.
7. Rode `php tests/Feature/assisted-activation-agent-limits-v36440-smoke.php`.

## Banco de dados

Não há migration nova. A versão reutiliza `tenant_agent_policies`, criada pela arquitetura de Policy Engine, e permanece exigindo `125_native_financial_lifecycle_notifications.sql` como migration atual do pacote.
