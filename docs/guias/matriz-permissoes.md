# Matriz de permissões

| Ação | Super Admin RS | Admin do cliente | Usuário do cliente |
|---|---:|---:|---:|
| Ver todas as empresas | Sim | Não | Não |
| Criar conexão da própria empresa | Sim | Sim | Conforme permissão |
| Ver chave global Evolution/OpenAI | Não no navegador | Não | Não |
| Vincular canal e assistente | Sim | Sim | Conforme permissão |
| Assumir conversa disponível | Sim para suporte | Sim | Sim |
| Forçar transferência/liberação | Sim | Sim | Não |
| Responder conversa bloqueada por outro | Suporte explícito | Apenas após transferência | Não |
| Ver consumo oficial da organização OpenAI | Sim | Não | Não |
| Ver consumo interno da própria empresa | Sim | Sim | Conforme permissão |
| Excluir instância com vínculos | Somente fluxo assistido | Somente fluxo assistido | Não |

## Princípio

O usuário recebe somente o acesso necessário à sua função e nunca pode operar dados de outro tenant.
