# Validação 36.36.21 — Ritmo da conversa

## Configuração recomendada
Em Assistentes > Conversa, modalidades e encaminhamentos > Respostas em mensagens curtas:

- Ritmo da conversa: **Responder só ao que foi perguntado e avançar 1 etapa**
- Como enviar respostas maiores: **Automático conforme o conteúdo**
- Máximo de blocos: **3 mensagens**

## Comportamento esperado
Para uma mensagem como:

> Gostaria de saber como funciona o atendimento

A assistente deve:

1. responder somente ao funcionamento que foi perguntado;
2. não antecipar valor/pagamento se isso não foi perguntado;
3. fazer somente a próxima pergunta obrigatória da Ordem do atendimento;
4. aguardar a resposta do contato antes de avançar para a etapa seguinte;
5. quando a resposta precisar de mais de um assunto, separar em balões curtos conforme a configuração.

## Regressões que devem permanecer
- Ordem do atendimento continua sendo a fonte de sequência da triagem.
- Policy Engine e demanda obrigatória continuam bloqueando a agenda quando aplicável.
- Disponibilidade real e aprovação humana continuam validadas pelo backend.
- Recuperação fora do horário permanece inalterada.
- Migration obrigatória continua sendo `119_agent_workflow_runtime_contract.sql`.
