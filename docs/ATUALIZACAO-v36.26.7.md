# RS Connect 36.26.7

## O que foi corrigido

- Marcar um alerta de WhatsApp como resolvido agora pausa o monitoramento da conexão afetada até ela reconectar.
- O monitor não recria o mesmo incidente nem envia novos lembretes enquanto a causa continuar sendo a conexão pausada.
- A ação **Resolver e liberar fila** cancela mensagens de saída pendentes/com falha, pendências da fila da IA e recuperações pós-horário relacionadas.
- Os registros continuam no histórico com status `cancelled`; nenhuma resposta cancelada será enviada após a reconexão.
- A reconexão reativa automaticamente o monitoramento quando a pausa foi criada pela resolução do incidente.

## Implantação

```bash
cd /var/www/html
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php status
php tests/Support/run-smoke-tests.php
```

Migration obrigatória: `database/migrations/098_operational_queue_release.sql`.
