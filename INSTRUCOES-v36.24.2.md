# RS Connect v36.24.2 — User-Agent obrigatório do Asaas

## Correção

O Asaas rejeita chamadas sem o cabeçalho HTTP `User-Agent`. Esta versão adiciona o cabeçalho em:

- criação do checkout do cadastro público;
- criação/localização de clientes Asaas;
- criação de cobranças Asaas;
- demais chamadas financeiras realizadas pelo `PaymentGatewayService` aos endpoints oficiais do Asaas.

Valor padrão:

```text
RS-Connect/36.24.2
```

Opcionalmente, pode ser personalizado no ambiente:

```env
ASAAS_USER_AGENT=RS-Connect/36.24.2
```

Não há migration nova. Após substituir os arquivos, reinicie ou reimplante o serviço RS Connect e refaça o cadastro de teste.
