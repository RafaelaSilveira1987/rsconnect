# RS Connect v36.26.5 — recuperação integral da aplicação

Este pacote foi reconstruído a partir do projeto enviado em `rs-connect-vps-ready(8).zip`.

## Arquivos corrigidos

- `bootstrap.php`: restaurado como bootstrap PHP da aplicação;
- `.dockerignore`: restaurado para evitar envio de arquivos indevidos ao build;
- `docker-compose.yml`: restaurado como configuração Docker Compose;
- `composer.json`: restaurado com requisitos e autoload PSR-4;
- `.env.example`, `.env.local.example` e `.env.vps.example`: restaurados como modelos de ambiente;
- `README.md`: restaurado;
- removidos `app/Controllers.tmp` e `app/Controllers.tmp.php`.

O arquivo `scripts/verify-critical-integrity.sh` agora valida também o `bootstrap.php` e reprova qualquer conteúdo Bash inserido em arquivos PHP críticos.

## Publicação

Substitua o projeto pelo conteúdo deste ZIP e faça uma nova implantação no EasyPanel.

Não existe migration nova nesta recuperação. A última migration continua sendo:

```text
096_public_signup_coupons.sql
```

## Validação após o deploy

```bash
cd /var/www/html
bash scripts/verify-critical-integrity.sh /var/www/html
php -d opcache.enable_cli=0 -r "require 'bootstrap.php'; echo class_exists('App\\Services\\OperationsService') ? 'CLASSE OK' : 'CLASSE AUSENTE'; echo PHP_EOL;"
php -d opcache.enable_cli=0 bin/operations-monitor.php
```

Resultados esperados:

```text
[OK] Arquivos críticos íntegros e classe carregada sem saída indevida.
CLASSE OK
```
