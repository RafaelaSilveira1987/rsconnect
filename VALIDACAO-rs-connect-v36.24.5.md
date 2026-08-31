# Validação — RS Connect v36.24.5

## Correção

- O Checkout Asaas deixou de receber `customerData` incompleto.
- Os dados essenciais da conta permanecem gravados no RS Connect.
- Endereço e cartão são preenchidos e confirmados diretamente no ambiente seguro do Asaas.
- O checkout recorrente mensal, o trial de 7 dias, os callbacks e o webhook permanecem inalterados.
- O `User-Agent` foi atualizado para `RS-Connect/36.24.5`.

## Validações executadas

- 110 de 110 testes smoke aprovados.
- 354 arquivos PHP com sintaxe válida.
- 4 arquivos JavaScript com sintaxe válida.
- Manifesto com 101 migrations validado.
- Parser SQL reconheceu 1.988 instruções.
- Nenhuma migration nova.

## Resultado esperado

Ao enviar o cadastro público, o Asaas não deve mais retornar `O campo address deve ser informado`. O usuário será redirecionado para o Checkout e preencherá os dados de endereço e cartão no ambiente do gateway.
