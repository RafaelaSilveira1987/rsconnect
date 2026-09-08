# Asaas — pagamento recusado, assinatura e acesso

## Regra implementada

- `PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED` e `PAYMENT_RECEIVED_IN_CASH`: cobrança `paid`, assinatura `active` e acesso liberado.
- `PAYMENT_OVERDUE`: cobrança `overdue`, assinatura `overdue`; o acesso continua sujeito ao período vigente e à tolerância comercial configurada em `BILLING_ACCESS_GRACE_DAYS`.
- `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`, `PAYMENT_REPROVED_BY_RISK_ANALYSIS` e `PAYMENT_ANTIFRAUD_REPROVED`: cobrança permanece `open` para nova tentativa e assinatura passa para `overdue`. A assinatura **não é cancelada automaticamente** e o acesso não é revogado imediatamente.
- `SUBSCRIPTION_INACTIVATED` e `SUBSCRIPTION_DELETED`: cancelam a assinatura local; o `AccessControlService` bloqueia o acesso quando `billing_status = canceled`.

## Motivo

Uma recusa de pagamento não significa que a assinatura foi cancelada. Se a cobrança recusada fosse marcada como `cancelled`, o controle comercial deixaria de enxergá-la como pendência e poderia manter acesso indefinidamente. Mantendo a cobrança `open` e a assinatura `overdue`, o acesso segue liberado enquanto a vigência/tolerância permitirem e passa a ser bloqueado após o prazo comercial.

Também foi adicionada proteção para eventos fora de ordem: uma cobrança já `paid` não é rebaixada para `open`/`overdue` por um evento posterior.
