# RS Connect 36.29.3

## Laboratório de assistentes — seleção consistente por empresa

Corrige um caso em que o navegador podia restaurar visualmente o valor antigo do seletor de empresa após refresh/back-forward, enquanto o servidor já havia carregado os assistentes de outra empresa. Isso fazia a tela exibir, por exemplo, o nome de uma empresa ao lado de um assistente pertencente a outro tenant.

### Alterações

- O servidor continua validando `tenant_id` e `agent_id` como fonte de verdade.
- A página usa `Cache-Control: no-store` para evitar reutilização de estado antigo.
- Os selects são sincronizados explicitamente com os IDs renderizados pelo servidor no carregamento e no evento `pageshow`.
- A troca de empresa consulta `/agent-tests/agents?tenant_id=...` e navega usando a primeira opção realmente retornada para aquela empresa.
- A troca de assistente sempre preserva o `tenant_id` confirmado pelo servidor.
- A interface mostra o ID da empresa carregada e quantos assistentes foram encontrados nela.
- O endpoint de agentes também valida se a empresa existe.

Não há migration nova.
