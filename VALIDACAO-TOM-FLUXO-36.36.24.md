# Validação — RS Connect 36.36.24

## Objetivo
Validar humanização, tom por assistente, expansão da Ordem do atendimento e bloqueio de promessa prematura de agenda.

## Configuração recomendada para a Rafa
1. Abra **Assistentes → Rafa → Configurar assistente**.
2. Em **Tom do atendimento**, selecione **Acolhedor e empático**.
3. Opcionalmente use a orientação complementar: `Acolha relatos sensíveis em uma frase curta antes da próxima pergunta, sem alongar a resposta.`
4. Em **Regras do atendimento → Ordem do atendimento**, confirme a sequência desejada.
5. Para incluir uma informação já existente, escolha-a em **Adicionar uma informação já cadastrada** e salve.
6. Para criar uma pergunta nova, preencha **Nome da informação** e **Pergunta que o assistente deve fazer**. Marque a trava antes da agenda apenas quando necessário.

## Cenário principal
- Cliente: `Boa tarde`
- Cliente: `Gostaria de saber como funciona o atendimento`
- A assistente responde somente ao pedido e segue para a próxima pergunta configurada.
- Quando o cliente responder com um relato sensível, a assistente deve acolher brevemente e só então fazer a próxima pergunta da Ordem.
- Na etapa de modalidade, responda: `Se tiver vaga, prefiro online`.
- Esperado: registrar **Online**, não prometer consulta de agenda e perguntar o dia/período/horário configurado na próxima etapa.
- Somente depois da preferência completa a agenda pode ser consultada.

## Regressões que devem permanecer válidas
- demanda obrigatória antes da agenda;
- uma pergunta de coleta por turno;
- disponibilidade real, sem inventar vaga;
- aprovação humana quando configurada;
- retomada pós-horário;
- SLA opcional;
- apresentação única do assistente.

## Banco
Não há migration nova. A migration obrigatória continua sendo `119_agent_workflow_runtime_contract.sql`.
