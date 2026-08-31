# RS Connect v36.24.6 — redirecionamento seguro ao Asaas

## O que foi corrigido

O formulário público recebia HTTP 302, mas o navegador permanecia em `/signup`. A política CSP da aplicação mantém `form-action 'self'`, portanto o POST não deve redirecionar diretamente para um domínio externo.

A jornada agora usa uma ponte interna segura:

1. `POST /signup` cria o Checkout no Asaas.
2. O servidor responde `303` para `/signup/checkout?token=...` no próprio RS Connect.
3. A página intermediária valida no banco o checkout e o domínio oficial do Asaas.
4. O navegador segue para o Checkout por navegação normal, com botão manual de contingência.

## Publicação

Não há migration nova.

1. Substitua os arquivos do pacote.
2. Reinicie/reimplante o serviço RS Connect.
3. Limpe o cache do navegador ou use recarregamento forçado.
4. Teste novamente `/signup`.

## Resultado esperado

`POST /signup` -> `303 /signup/checkout` -> página “Abrindo o checkout seguro” -> domínio oficial do Asaas.
