# Memória progressiva das conversas

## Objetivo

Evitar que o RS Connect precise reenviar um histórico longo a cada mensagem, sem perder continuidade do atendimento.

## Onde configurar

**Assistentes de IA → Configurações → Memória progressiva da conversa**.

Configurações disponíveis:

- ativar/desativar memória progressiva;
- quantidade de mensagens entre atualizações;
- tamanho máximo do resumo.

Padrão recomendado:

- memória ativa;
- atualizar a cada 8 mensagens;
- resumo de até 2.200 caracteres.

## O que fica armazenado

A tabela `conversation_ai_memory` mantém somente o resumo operacional e fatos estruturados derivados da conversa. Não substitui o histórico original.

## Segurança de contexto

A memória é tratada como um resumo auxiliar. A ordem de prioridade é:

1. mensagem atual e mensagens recentes;
2. cadastro e regras operacionais do RS Connect;
3. memória progressiva;
4. base de conhecimento e prompt livre.

Assim, uma informação corrigida pelo cliente em uma mensagem recente não deve ser sobrescrita por um resumo antigo.

## Visibilidade

Quando houver memória, ela pode ser consultada no painel lateral da conversa em **Memória da IA**. Isso facilita auditar o que o assistente está carregando como contexto.

## Continuidade entre conversas

Além do resumo da conversa atual, a v36.19.0 consolida uma memória por contato em `contact_ai_memory`. Quando o mesmo contato inicia uma nova conversa, o RS Connect pode reaproveitar o último resumo/fatos confirmados sem anexar o histórico inteiro da conversa anterior.

A primeira atualização da nova conversa incorpora as informações anteriores e passa a manter uma memória própria daquele novo atendimento. Se uma mensagem recente corrigir uma informação antiga, a informação recente prevalece e a memória consolidada é atualizada.
