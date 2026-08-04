# Rollback dos Relatórios V2

Este pacote restaura os arquivos originais da versão enviada antes da implementação dos Relatórios V2 e remove os arquivos novos dessa alteração.

## Aplicação

Extraia este ZIP na raiz do projeto, sobrescrevendo os arquivos existentes:

```bash
unzip -o rs-connect-rollback-relatorios-v2.zip -d /caminho/da/rs-connect
cd /caminho/da/rs-connect
bash ROLLBACK-RELATORIOS-V2.sh
```

Depois confira:

```bash
git status
git diff --stat
```

## Arquivos restaurados

- `CHANGELOG.md`
- `app/Controllers/ReportController.php`
- `app/Views/layouts/app.php`
- `public/assets/css/app.css`

## Arquivos removidos

- `IMPLEMENTACAO-RELATORIOS-V2.md`
- `ARQUIVOS-ALTERADOS.txt`
- `app/Views/reports/index_v2.php`
- `app/Views/reports/admin_v2.php`
- `public/assets/css/reports-v2.css`
