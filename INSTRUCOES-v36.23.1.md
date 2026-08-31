# RS Connect v36.23.1 — publicação

## 1. Substituição

Aplique o hotfix sobre a v36.23.0 e reinicie/reimplante o serviço RS Connect.

## 2. Banco de dados

```bash
cd /var/www/html
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Resultado esperado:

```text
99 aplicada(s), 0 pendente(s), 0 divergente(s)
```

Não execute `baseline`. As tabelas que já foram criadas são preservadas por `CREATE TABLE IF NOT EXISTS`.
