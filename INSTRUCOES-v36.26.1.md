# RS Connect v36.26.1 — cupom com valor mínimo seguro do Asaas

## O que mudou

O Asaas rejeita qualquer Checkout cuja cobrança líquida seja inferior a R$ 5,00.

A partir desta versão:

- cupons continuam aceitando configurações que matematicamente chegariam a R$ 1,00;
- o RS Connect nunca envia ao Asaas valor inferior a R$ 5,00;
- o cliente vê antes do checkout que o valor foi ajustado para o mínimo do gateway;
- o backend mantém uma proteção adicional mesmo que a interface seja contornada;
- não existe migration nova.

## Publicação

Substitua os arquivos e reinicie o serviço. Atualize o navegador com Ctrl + Shift + R.

## Resultado esperado

Plano de R$ 99,00 com cupom de R$ 98,00:

- valor desejado pelo cupom: R$ 1,00;
- valor efetivo enviado ao Asaas: R$ 5,00;
- desconto efetivo: R$ 94,00.

O Asaas não permite uma cobrança real de R$ 1,00 no Checkout.
