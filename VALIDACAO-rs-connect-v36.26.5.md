# Validação — RS Connect v36.26.5

Pacote reconstruído a partir do arquivo `rs-connect-vps-ready(8).zip` para recuperação da plataforma.

## Corrupções encontradas e corrigidas

- `bootstrap.php` continha o script Bash de backup do MySQL;
- `.dockerignore` continha texto de README;
- `docker-compose.yml` continha o conteúdo de `composer.json`;
- `composer.json` estava vazio;
- `README.md` estava vazio;
- `.env.example` continha código de controller PHP;
- `.env.local.example` e `.env.vps.example` continham documentos de versões antigas;
- `app/Controllers.tmp` e `app/Controllers.tmp.php` eram arquivos temporários indevidos.

## Resultado das validações

- integridade crítica: aprovada;
- autoload de `App\\Services\\OperationsService`: aprovado;
- 363 arquivos PHP: sintaxe válida;
- 4 arquivos JavaScript: sintaxe válida;
- 117 de 117 smoke tests: aprovados;
- migrations: 103 reconhecidas;
- parser SQL: 2.051 instruções reconhecidas;
- rollbacks isolados: 1;
- nenhuma migration nova.

## Comandos de pós-deploy

```bash
cd /var/www/html
bash scripts/verify-critical-integrity.sh /var/www/html
php -d opcache.enable_cli=0 -r "require 'bootstrap.php'; echo class_exists('App\\Services\\OperationsService') ? 'CLASSE OK' : 'CLASSE AUSENTE'; echo PHP_EOL;"
php -d opcache.enable_cli=0 bin/operations-monitor.php
```
