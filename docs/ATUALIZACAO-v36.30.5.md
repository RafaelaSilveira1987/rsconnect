# RS Connect 36.30.5 — Correção de identidade das instâncias

## Objetivo

Corrigir dois problemas observados após a v36.30.4: marcadores técnicos aparecendo como texto no topo do login/aplicação e conexões Evolution ativas sem número reconhecido.

## O que foi corrigido

1. Os marcadores históricos de cache foram movidos para dentro do PHP e não produzem mais saída visual.
2. Valores curtos, como status HTTP `200`, não podem mais ser tratados como telefone.
3. Quando `/instance/connectionState/{instance}` informa apenas `state=open`, o RS Connect consulta `/instance/fetchInstances?instanceName=...` para obter `ownerJid/number`.
4. O status feed atualiza Número autorizado e Número conectado no card sem exigir recarregar a página.
5. A migration 109 limpa `profile_phone`/`authorized_phone` inválidos já gravados. Na próxima consulta ao vivo, o número real pode ser adotado novamente.

## Migration obrigatória

`109_evolution_instance_identity_cleanup.sql`

Depois do deploy:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

## Observação

Uma instância pode estar corretamente **Conectada** mesmo quando o endpoint `connectionState` não fornece o número. O estado e a identidade são dados diferentes; a v36.30.5 passa a buscar a identidade no endpoint apropriado antes de declarar que o número não foi confirmado.
