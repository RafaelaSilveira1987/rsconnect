# RS Connect v36.25.0 — cartão recorrente e Pix QR Code

## Regras comerciais

- Cartão de crédito: 7 dias grátis, primeira cobrança depois do trial e renovação automática.
- Pix QR Code: pagamento imediato de uma mensalidade, primeiro ciclo com 30 dias mais os dias de bônus configurados e renovações mensais por cobrança Asaas em boleto com QR Code Pix.
- A conta só é provisionada após webhook autenticado do Asaas.

## Publicação

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Migration nova: `095_public_signup_pix_qrcode.sql`.

## Homologação Pix no Sandbox

1. Em Financeiro > Inscrição pública, habilite **Oferecer Pix QR Code**.
2. Abra `/signup` e selecione Pix QR Code.
3. Conclua o checkout no Asaas Sandbox.
4. Confirme o evento `CHECKOUT_PAID` ou `PAYMENT_RECEIVED` no webhook.
5. Verifique a criação da empresa e do usuário.
6. Confirme que a sessão possui `payment_method = pix` e que a renovação externa foi criada.

## Produção

Troque o gateway para Produção, informe a API Key de produção e mantenha o mesmo endpoint de webhook com token exclusivo. Faça um cadastro real de baixo risco antes de liberar o link publicamente.
