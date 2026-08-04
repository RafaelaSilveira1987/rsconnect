#!/usr/bin/env bash
set -euo pipefail

# Execute este script a partir da raiz do projeto RS Connect.
# Ele remove somente os arquivos novos da implementação Relatórios V2.

rm -f \
  IMPLEMENTACAO-RELATORIOS-V2.md \
  ARQUIVOS-ALTERADOS.txt \
  app/Views/reports/index_v2.php \
  app/Views/reports/admin_v2.php \
  public/assets/css/reports-v2.css

echo "Arquivos novos dos Relatórios V2 removidos."
echo "Os arquivos originais incluídos neste pacote devem estar extraídos na raiz do projeto."
