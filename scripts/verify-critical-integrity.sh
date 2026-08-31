#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/html}"
FAIL=0

error() {
  printf '[ERRO] %s\n' "$1" >&2
  FAIL=1
}

check_php_file() {
  local rel="$1"
  local marker="$2"
  local file="$ROOT/$rel"

  [[ -f "$file" ]] || { error "Arquivo ausente: $rel"; return; }

  local first_line
  first_line="$(head -n 1 "$file" | tr -d '\r')"
  if [[ "$rel" == bin/* ]]; then
    [[ "$first_line" == '#!/usr/bin/env php' ]] || error "Cabeçalho inválido em $rel: $first_line"
  else
    [[ "$first_line" == '<?php' ]] || error "O arquivo $rel não começa com <?php."
  fi

  grep -Fq "$marker" "$file" || error "Marcador esperado ausente em $rel: $marker"

  if grep -Eq '(^|[[:space:]])set -uo pipefail|RS Connect v36\.3\.0.*backup real|MYSQL_SERVICE=.*sites_mysql' "$file"; then
    error "Conteúdo de shell/backup encontrado em $rel"
  fi

  php -l "$file" >/dev/null || error "Sintaxe PHP inválida em $rel"
}

check_php_file 'bootstrap.php' 'App\Core\Autoloader::register'
check_php_file 'bin/operations-monitor.php' 'new OperationsService'
check_php_file 'app/Services/OperationsService.php' 'final class OperationsService'

# Nenhum PHP da aplicação pode conter o script Bash de backup.
while IFS= read -r file; do
  error "Script Bash encontrado dentro de PHP: ${file#$ROOT/}"
done < <(grep -IlE 'RS Connect v36\.3\.0.*backup real|(^|[[:space:]])set -uo pipefail' "$ROOT/bootstrap.php" 2>/dev/null || true; grep -RIlE 'RS Connect v36\.3\.0.*backup real|(^|[[:space:]])set -uo pipefail' "$ROOT/app" "$ROOT/bin" --include='*.php' 2>/dev/null || true)

# O autoload deve carregar a classe sem produzir qualquer saída lateral.
AUTOLOAD_OUTPUT="$(php -d display_errors=1 -d opcache.enable_cli=0 -r "require '$ROOT/bootstrap.php'; echo class_exists('App\\\\Services\\\\OperationsService') ? 'CLASSE OK' : 'CLASSE AUSENTE';" 2>&1 || true)"
if [[ "$AUTOLOAD_OUTPUT" != 'CLASSE OK' ]]; then
  error "Falha no autoload. Saída recebida: ${AUTOLOAD_OUTPUT:0:500}"
fi

if [[ "$FAIL" -ne 0 ]]; then
  echo '[FALHA] Integridade dos arquivos críticos inválida.' >&2
  exit 1
fi

echo '[OK] Arquivos críticos íntegros e classe carregada sem saída indevida.'
