# EasyPanel: RS Connect no subcaminho /rsconnect

## Escopo
Permite publicar o app em `https://rsdigitallab.com/rsconnect`, preservando as rotas internas originais, como `/login`, `/assets/`, `/api/`, `/webhooks/` e `/health/live`. Nenhuma migração de banco de dados.

## Configuração de homologação
1. Fazer backup das configurações atuais e manter o endereço anterior `https://rsconnect.rsdigitallab.com` acessível para rollback.
2. No EasyPanel: serviço `sites/rsconnect`, host `rsdigitallab.com`, caminho público `/rsconnect`, HTTPS, destino `http:80`, caminho `/`, sem middleware de remoção de prefixo. O app faz sua própria normalização.
3. **Somente após publicar esta alteração**, definir no ambiente do RS Connect:
   `APP_URL=https://rsdigitallab.com/rsconnect`
   Preservar todas as demais variáveis e segredos. Esta variável define os links públicos gerados pela aplicação, inclusive no caso de acesso temporário pelo subdomínio antigo.
4. O serviço `portfolio` continua no host principal, caminho `/`. Após homologação, apontar o botão do site para `https://rsdigitallab.com/rsconnect/login`.

## Testes antes da produção
- `php tests/Feature/public-subpath-smoke.php`
- `php bin/qa.php --suite=lint`
- `php bin/qa.php --suite=critical`
- Verificar em navegador anônimo as URLs `/rsconnect` e `/rsconnect/login`, fontes/imagens/CSS/JS na aba Rede, login, CSRF, logout e navegação.
- Verificar CRM, conversas, anexos, agenda, APIs, chamadas assíncronas, painéis administrativo e do cliente.
- Verificar endpoints de saúde e workers após o deploy.
- **Webhooks e integrações**: não alterar endereços já configurados em Evolution, n8n ou outros provedores automaticamente. Confirmar chamadas reais, assinaturas e recepção antes de atualizar URLs externas. A mesma instância continua aceitando caminhos antigos, mas URLs internas geradas a partir de APP_URL passam a usar o novo endereço.
- Se aparecer o site institucional em qualquer rota /rsconnect/*, conferir roteamento e prioridade no EasyPanel antes de alterar o app.

## Rollback
Restaurar a imagem anterior do container e o APP_URL antigo e usar o subdomínio anterior. Não remover dados nem executar migrações para este ajuste.

## Observação técnica
A regra Apache expõe arquivos estáticos sob o prefixo. A classe PublicPath normaliza REQUEST_URI antes do roteamento e dos middlewares. O roteador Router::url() já insere o caminho contido em APP_URL.
