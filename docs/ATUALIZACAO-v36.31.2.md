# RS Connect 36.31.2 — Saudação inteligente por contato

## Objetivo
Permitir que cada assistente defina como deve abrir a conversa sem tratar cliente ou paciente conhecido como um novo lead.

## Novas opções por assistente
Na área de respostas/saudação do Agente, o campo **Quem recebe a saudação?** oferece três modos:

- **Todos os contatos**: lead, cliente e paciente recebem uma saudação curta na primeira resposta da conversa.
- **Somente novos contatos**: leads recebem a abertura configurada; cliente/paciente reconhecido recebe continuidade natural e contextual.
- **Sem saudação automática**: o sistema não força uma mensagem de abertura; o agente responde diretamente ao conteúdo recebido.

A opção **Usar o nome do contato quando estiver disponível e soar natural** permite personalização sem transformar toda resposta em uma apresentação formal.

## Regras de segurança conversacional
- a saudação de abertura não é repetida depois que a conversa já teve resposta;
- respostas locais para “oi/olá” respeitam o modo selecionado;
- cliente/paciente é reconhecido pela classificação operacional já existente no RS Connect;
- o cache exato é ignorado na abertura da conversa e respostas de abertura não são armazenadas nele, evitando reutilização de uma saudação fora do contexto;
- demanda, agenda, modalidades, pagamento, indisponibilidade e encaminhamentos continuam com as mesmas regras da 36.31.0/36.31.1.

## Banco
Migration obrigatória: `111_agent_greeting_policy.sql`.

A migration preserva o comportamento das instalações já existentes com `all_contacts`. Novos assistentes criados pela interface usam como padrão **Somente novos contatos**, favorecendo uma conversa mais natural com clientes e pacientes reconhecidos.
