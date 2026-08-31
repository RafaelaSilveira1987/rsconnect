# RS Connect v36.24.3 — campo `name` do Checkout Asaas

## Correção

A API do Asaas podia rejeitar a criação do Checkout com `O campo name é inválido` quando o nome do responsável possuía apenas uma palavra, emojis ou caracteres incompatíveis.

A versão 36.24.3:

- usa o nome simples `RS Connect Plano Inicial` no item do Checkout;
- normaliza o nome do pagador para letras e espaços;
- só envia `customerData` quando existe nome e sobrenome válidos para o pré-preenchimento;
- se o Asaas ainda rejeitar especificamente `name`, repete uma única vez sem `customerData`, deixando o próprio pagador preencher os dados no Checkout;
- mantém os demais erros visíveis, sem repetição indiscriminada.

## Publicação

Não existe migration nova. Substitua os arquivos e reinicie/reimplante o serviço.

## Teste

1. Abra `/signup`.
2. Faça um cadastro usando nome completo do responsável.
3. Faça outro teste no Sandbox com responsável de apenas um nome.
4. Nos dois casos, o Checkout deve ser criado e o navegador redirecionado ao Asaas.
