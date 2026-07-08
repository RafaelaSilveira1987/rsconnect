# RS Connect — pacote pronto para VPS

Arquivos principais adicionados/ajustados:

- `Dockerfile`: PHP 8.3 + Apache com `pdo_mysql`, `curl`, `mbstring`, `opcache` e rewrite.
- `docker-compose.yml`: app + MySQL + Adminer.
- `.env.vps.example`: modelo seguro para homologação.
- `public/health.php`: teste de aplicação e banco.
- `database/vps_fresh_install.sql`: SQL único para instalação manual.
- `DEPLOY-EASYPANEL.md`: passo a passo do deploy e webhook.

O arquivo `.env` real foi removido do pacote para não levar credenciais locais para a VPS.
