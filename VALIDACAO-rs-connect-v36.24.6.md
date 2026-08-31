# Validação — RS Connect v36.24.6

## Correção principal

O cadastro público criava o Checkout no Asaas, mas respondia ao `POST /signup` com redirecionamento direto para um domínio externo. A aplicação mantém CSP estrita com `form-action 'self'`, e o navegador permanecia na página mesmo recebendo HTTP 302.

A versão 36.24.6 introduz uma ponte interna segura:

1. o POST cria e persiste o Checkout;
2. responde HTTP 303 para `/signup/checkout?token=...` no próprio domínio;
3. a página intermediária consulta o Checkout salvo;
4. valida HTTPS, domínio oficial `asaas.com` e caminho de Checkout;
5. redireciona por navegação normal;
6. mantém botão manual caso o redirecionamento automático seja impedido.

## Proteções

- Não foi liberado domínio externo em `form-action`; a CSP estrita foi preservada.
- O link externo é aceito somente em HTTPS.
- O host precisa ser `asaas.com` ou subdomínio oficial.
- O caminho precisa iniciar em `/checkoutSession/show`.
- O token público continua aleatório e armazenado somente como hash.
- `successUrl` não provisiona a empresa; o provisionamento continua dependente do webhook autenticado.
- Não existe migration nova.

## Validações executadas

- 111 de 111 testes de regressão aprovados.
- 353 arquivos PHP com sintaxe válida.
- 4 arquivos JavaScript com sintaxe válida.
- Manifesto com 101 migrations validado.
- Parser reconheceu 1.988 instruções SQL.
- Teste dinâmico da allowlist aceitou URLs oficiais e rejeitou HTTP, host semelhante malicioso e caminho incorreto.

## Limite da validação

Não foi realizado Checkout real porque a chave Sandbox do ambiente do usuário não está disponível neste ambiente de construção. A criação do Checkout já foi comprovada pelo HTTP 302 observado; esta versão corrige a etapa seguinte de navegação.
