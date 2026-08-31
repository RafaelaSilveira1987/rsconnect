# RS Connect v36.24.1 — correção da URL do Asaas

## Correção

- O gateway Asaas passa a usar exclusivamente a URL oficial do ambiente:
  - Sandbox: `https://api-sandbox.asaas.com/v3`
  - Produção: `https://api.asaas.com/v3`
- Valores antigos em `payment_gateways.api_base_url`, inclusive `rsconnect.local`, são removidos pela migration 094.
- O campo URL base fica desabilitado quando o provedor selecionado é Asaas.
- A correção também vale para cobranças avulsas e para a inscrição pública.

## Atualização

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Resultado esperado: 101 migrations aplicadas, 0 pendentes e 0 divergentes.

## Teste

1. Use o gateway Asaas em Sandbox.
2. Abra `/signup`.
3. Cadastre uma empresa com e-mail e documento ainda não utilizados.
4. O navegador deve ser redirecionado para o checkout hospedado pelo Asaas.
