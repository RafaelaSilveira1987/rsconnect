# Validação — RS Connect v36.24.2

## Correção entregue

A integração Asaas passou a enviar o cabeçalho HTTP obrigatório `User-Agent` em dois pontos centrais:

- `PublicSignupService`: criação do checkout recorrente do cadastro público;
- `PaymentGatewayService`: clientes, cobranças e demais requisições aos hosts oficiais do Asaas.

Valor padrão enviado:

```text
User-Agent: RS-Connect/36.24.2
```

A variável opcional `ASAAS_USER_AGENT` permite personalizar o valor. Entradas vazias ou contendo quebra de linha são rejeitadas e substituídas pelo padrão seguro.

## Proteções

- o cabeçalho não é duplicado quando já estiver presente;
- a injeção no serviço financeiro ocorre somente para `api.asaas.com` e `api-sandbox.asaas.com`;
- não existe migration nova;
- o hotfix mantém as 101 migrations atuais intactas.

## Testes executados

- 107 de 107 testes de funcionalidade aprovados;
- 349 arquivos PHP validados com `php -l`;
- 4 arquivos JavaScript validados com `node --check`;
- manifesto validado com 101 migrations;
- parser reconheceu 1.988 instruções SQL;
- teste específico da v36.24.2 confirmou a presença do User-Agent no cadastro e no serviço financeiro.

## Limite da validação

Não foi realizada cobrança real nem chamada autenticada ao ambiente Asaas, pois a credencial da conta não está disponível no ambiente de validação. A homologação final deve ser feita no Sandbox após a publicação.
