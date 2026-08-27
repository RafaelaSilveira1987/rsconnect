# ENT-028 — Hotfix de autenticação PagBank/PagSeguro v36.20.13.1

## Evidência observada

Ao gerar o link de pagamento, o gateway retornou mensagem semelhante a:

```text
Invalid key=value pair (missing equal-sign) in Authorization header
```

Esse retorno é compatível com uma chamada enviada para um caminho incorreto do API Gateway ou com um valor de autorização montado a partir de uma credencial colada com prefixo indevido.

## Causas tratadas

1. URL base cadastrada como `https://sandbox.api.pagseguro.com/checkouts`, enquanto o serviço já acrescenta `/checkouts`.
2. Token colado como `Bearer TOKEN` ou `Authorization: Bearer TOKEN`.
3. URL Sandbox usada com ambiente Produção, ou o inverso.
4. Mensagem técnica do gateway exposta diretamente na interface.

## Correções

- normalização da URL base para o host oficial;
- remoção automática dos prefixos `Authorization:` e `Bearer`;
- validação do host conforme o ambiente;
- normalização tanto ao salvar quanto ao executar a chamada, cobrindo cadastros já existentes;
- mensagem operacional clara para o administrador;
- orientação visual no formulário.

## Validações

- 307 arquivos PHP sem erro de sintaxe;
- JavaScript validado com `node --check`;
- teste ENT-028 original: 19 aprovações;
- teste do hotfix: 10 aprovações;
- suíte completa: 77 aprovações, 9 falhas históricas, 86 testes;
- nenhuma migration nova;
- pasta `tests/` sem aplicação duplicada.

## Homologação

1. Publicar a v36.20.13.1 preservando `.env`, storage e uploads.
2. Reiniciar o serviço PHP para limpar OPcache.
3. Abrir Meios de pagamento e editar o gateway PagBank.
4. Selecionar Sandbox ou Produção corretamente.
5. Deixar a URL base vazia.
6. Colar somente o Token da API.
7. Salvar e gerar um novo link em uma cobrança aberta.
