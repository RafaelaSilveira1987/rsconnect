# Homologação final — RS Connect v36.6.1

## Evidências que motivaram a correção

- O n8n recusou o node `Validar e normalizar` com `access to env vars denied`; o template 36.6.0 ainda possuía referências a `$env`.
- A Fila da IA mostrava a instância Mariana como `Conectada/open`, porém novas tentativas continuavam terminando em `HTTP 400: Bad Request`.
- O Monitoramento atribuía esse `HTTP 400` ao card OpenAI/IA, apesar de o `AiModelService` usar o prefixo `IA HTTP ...` e o erro genérico vir do envio Evolution.

## Alterações 36.6.1

### Backup
- Nenhum node do template usa `$env` ou `process.env`.
- O RS Connect injeta URL/token no arquivo no momento do download.
- Cada solicitação de backup carrega caminho do script, URL de callback e token de callback no payload.
- É necessário substituir/reimportar o workflow no n8n; deploy do ZIP não altera um workflow já salvo no n8n.

### Fila da IA / Evolution
- Antes de reprocessar, consulta o estado real na Evolution.
- Se a conexão não estiver operacional, preserva as pendências sem chamar o provedor de IA.
- Se estiver operacional e o envio falhar, o erro passa a indicar explicitamente `Evolution sendText`.
- Telefones brasileiros armazenados com 10/11 dígitos recebem DDI `55` automaticamente antes do envio.
- O card OpenAI/IA ignora falhas reconhecidas como Evolution; o card Evolution passa a usar essas falhas como evidência de atenção.

## Reteste
1. Subir a 36.6.1.
2. Em n8n no RS Connect, baixar novamente `Backup automático RS Connect`; substituir o workflow anterior e selecionar a credencial SSH.
3. Executar backup real e validar callback/arquivo.
4. Abrir Fila da IA e conferir o estado ao vivo da instância Mariana.
5. Executar reprocessamento. A próxima tentativa deve ficar claramente em uma destas situações: `Aguardando conexão`, `Geração da resposta pela IA` com erro `IA HTTP...`, ou `Envio pelo WhatsApp / Evolution` com erro `Evolution sendText HTTP...`.

Não exige migration.
