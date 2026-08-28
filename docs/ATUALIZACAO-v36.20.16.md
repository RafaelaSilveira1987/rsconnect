# Atualização v36.20.16 — migrations normalizadas

## Entrega

- manifesto canônico com sequência única;
- tabela `schema_migrations`;
- checksum SHA-256 por migration;
- lock MySQL contra execução concorrente;
- comandos `verify`, `status`, `install`, `baseline`, `up`, `seed` e `bootstrap`;
- parser compatível com `DELIMITER`, procedures e triggers;
- instalação Docker centralizada em um serviço de migration;
- readiness bloqueado quando o schema está incompleto;
- monitoramento operacional de pendências e drift.

## Compatibilidade

Os nomes dos arquivos históricos foram preservados. Numerações repetidas não foram renomeadas, evitando divergência entre código e bancos já implantados.

A ordem oficial passou a ser definida por `database/migrations/manifest.php`.
