# Atualização RS Connect 36.31.3

## Hotfix da saudação configurada

Esta versão corrige a situação em que uma mensagem contendo apenas uma saudação (por exemplo, `Olá`) podia cair na IA e gerar uma frase genérica quando o registro da conversa já tinha uma resposta anterior.

### Regra corrigida

- **Todos os contatos**: uma saudação explícita usa exatamente o texto salvo em **Resposta para saudação**, para lead, cliente ou paciente, independentemente de haver histórico anterior no mesmo registro de conversa.
- **Somente novos contatos**: lead/novo contato usa a resposta configurada; cliente/paciente reconhecido não recebe a apresentação de novo contato e segue para resposta natural da IA.
- **Sem saudação automática**: a regra local de saudação permanece desativada.
- Mensagens como `Olá, preciso remarcar minha consulta` continuam indo para a IA, pois contêm uma demanda e não são uma saudação pura.

### Banco de dados

Não há migration nova. A migration obrigatória continua sendo `111_agent_greeting_policy.sql`.
