# Pagamentos — ZIP 36.0

## PagBank

1. Cadastre o gateway em **Gateways de pagamento**.
2. Use a URL base de produção `https://api.pagseguro.com` ou sandbox `https://sandbox.api.pagseguro.com`.
3. Informe o token no campo API Key.
4. Configure no PagBank o webhook exibido pelo RS Connect.
5. Crie a cobrança no RS Connect e gere o link.
6. O botão **Consultar PagBank** consulta o checkout já criado.

## InfinitePay

A integração foi desenhada para o cenário em que a cobrança já existe no aplicativo/conta InfinitePay:

1. Crie a cobrança na InfinitePay.
2. No RS Connect, crie a fatura da assinatura.
3. Clique em **Importar cobrança externa**.
4. Informe o identificador e o link da cobrança.
5. O link passa a aparecer na assinatura e pode ser enviado pela régua de cobrança.

### Atualização automática opcional

Cadastre um gateway do tipo **InfinitePay — cobrança existente** e defina um token interno de webhook. Depois, um fluxo n8n pode enviar:

```json
{
  "invoice_number": "RS-202607-00001",
  "external_id": "COBRANCA-123",
  "status": "paid",
  "checkout_url": "https://link-do-provedor"
}
```

para:

```text
POST /webhooks/payments/infinitepay
X-RS-Payment-Token: TOKEN_CONFIGURADO
```

## Desbloqueio

Quando uma cobrança é confirmada como paga, o RS Connect:

- registra `paid_at`;
- atualiza a vigência conforme o período da fatura;
- ativa a assinatura quando não existem outras faturas bloqueantes;
- reativa a empresa;
- registra quando o acesso foi liberado.
