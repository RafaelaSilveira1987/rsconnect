# Diagnóstico técnico — ENT-029 / PA-003

## Risco anterior

A aplicação não possuía endpoints independentes para diferenciar processo vivo de aplicação pronta. Ferramentas de deploy precisavam usar páginas autenticadas ou rotas funcionais, com risco de sessões desnecessárias e exposição de respostas internas.

## Solução

- `live`: não acessa banco nem serviços externos;
- `ready`: verifica somente banco, diretórios persistentes e APP_KEY;
- resposta pública limitada ao campo `status`;
- detalhes disponíveis apenas para Super Admin autenticado;
- exceções são capturadas e nunca retornadas ao cliente;
- integrações Evolution, n8n, OpenAI e gateways não bloqueiam readiness, pois a interface principal continua capaz de operar e apresentar seus estados separadamente.

## Dados que não são retornados

- host, porta, nome e usuário do banco;
- credenciais ou chaves;
- mensagens e stack traces de exceção;
- caminhos do filesystem;
- estrutura interna de tabelas;
- nomes de serviços externos configurados.
