# Hotfix 36.36.7-r1 — manifesto da migration 118

## Causa
A migration `118_contact_origin.sql` estava presente em `database/migrations/`, mas não havia sido adicionada ao manifesto canônico. O comando `php bin/migrate.php verify` bloqueava o build com:

`Arquivo SQL sem classificação no manifesto: 118_contact_origin.sql.`

## Correção
- adiciona `118_contact_origin.sql` ao final de `database/migrations/manifest.php`;
- sequência canônica: `125`;
- não altera o conteúdo da migration já publicada;
- preserva a identidade visual Mobile 0.5.0 da 36.36.7.

## Validação

```bash
php bin/migrate.php verify
```

Esperado: `Manifesto: 125 migrations de subida.`

Após o deploy:

```bash
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```
