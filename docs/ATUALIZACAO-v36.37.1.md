# RS Connect 36.37.1 — Exclusão segura de etapas da Ordem do atendimento

## Objetivo

Permitir que uma empresa remova etapas de coleta que deixaram de fazer sentido no seu fluxo sem depender de alteração manual no banco ou de um blueprint específico do segmento.

## Como funciona

Na seção **Ordem do atendimento**, toda etapa do tipo **Coleta** passa a exibir **Excluir etapa**. Ao clicar, a interface confirma a operação e remove visualmente o card. A exclusão é persistida quando o formulário de regras é salvo.

Etapas técnicas de **Ação**, **Validação**, **Equipe** e **Conclusão** continuam protegidas, pois podem representar contratos internos necessários para agenda, segurança ou encerramento.

## Comportamento dos campos vinculados

Se a etapa excluída era o último lugar do workflow que utilizava uma informação:

- o campo deixa de ficar ativo;
- deixa de ser obrigatório antes da agenda;
- deixa de ser obrigatório para conclusão;
- continua cadastrado para poder ser incluído novamente em **Adicionar uma informação já cadastrada**.

Se a mesma informação ainda estiver vinculada a outra etapa, ela permanece ativa.

A compatibilidade histórica de `brief_demand` também é desligada quando seu último vínculo é removido, evitando que uma regra antiga recrie a pergunta fora da Ordem do atendimento.

## Banco de dados

Não há nova migration. Continua obrigatória:

`120_agent_turn_state_cursor.sql`
