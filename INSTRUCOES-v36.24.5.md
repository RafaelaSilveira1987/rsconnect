# RS Connect v36.24.5 — endereço no Checkout Asaas

## Correção

O Asaas exige endereço completo quando o objeto `customerData` é enviado em um Checkout de cartão. O cadastro público coletava apenas nome, documento, e-mail e telefone, por isso a API recusava com `O campo address deve ser informado`.

A partir desta versão, o RS Connect não envia `customerData` parcial. Os dados essenciais da empresa e do responsável continuam armazenados no RS Connect, enquanto o pagador informa e confirma endereço e cartão diretamente no ambiente seguro do Asaas.

## Publicação

Substitua os arquivos e reinicie o serviço. Não existe migration nova.
