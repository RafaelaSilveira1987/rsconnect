# RS Connect 36.26.9

## Correção aplicada

O monitor de pagamentos considerava recuperação somente quando `payment_gateway_events.status = 'success'`. Isso mantinha o alerta aberto mesmo depois de o endpoint receber e autenticar corretamente um webhook que terminava como `ignored` por não localizar uma cobrança local.

A partir desta versão, também é considerado evidência de recuperação técnica:

```text
payment.webhook.<provider> + status ignored
```

A regra permanece restrita ao evento principal do webhook do gateway. Outros eventos ignorados não são classificados automaticamente como recuperação.

## Resultado esperado

Quando a última falha possui ID menor que o último webhook autenticado, o check financeiro passa para `ok` e o incidente `operations.alert.payments` é normalizado automaticamente no próximo ciclo do monitor.

Exemplo validado:

```text
última falha: ID 42
última recuperação técnica: ID 43
```

## Implantação

Não há migration nova.

```bash
cd /var/www/html
php bin/operations-monitor.php
php tests/Feature/payment-webhook-technical-recovery-v36269-smoke.php
```
