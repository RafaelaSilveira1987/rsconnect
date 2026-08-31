#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/html}"
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PAYLOAD="$BASE_DIR/payload"

[[ -d "$ROOT/app/Services" && -f "$ROOT/bootstrap.php" ]] || {
  echo "[ERRO] Raiz do RS Connect inválida: $ROOT" >&2
  exit 1
}

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$ROOT/storage/integrity-repair-$STAMP"
mkdir -p "$BACKUP/app/Services" "$BACKUP/bin" "$BACKUP/scripts"

for rel in \
  app/Services/OperationsService.php \
  bin/operations-monitor.php \
  scripts/verify-critical-integrity.sh; do
  if [[ -f "$ROOT/$rel" ]]; then
    cp -a "$ROOT/$rel" "$BACKUP/$rel"
  fi
done

install -D -m 0644 "$PAYLOAD/app/Services/OperationsService.php" "$ROOT/app/Services/OperationsService.php"
install -D -m 0755 "$PAYLOAD/bin/operations-monitor.php" "$ROOT/bin/operations-monitor.php"
install -D -m 0755 "$PAYLOAD/scripts/verify-critical-integrity.sh" "$ROOT/scripts/verify-critical-integrity.sh"

# Remove cache de opcode do processo CLI; o serviço web deve ser reiniciado após a instalação.
php -d opcache.enable_cli=1 -r 'if (function_exists("opcache_reset")) { opcache_reset(); }' >/dev/null 2>&1 || true

bash "$ROOT/scripts/verify-critical-integrity.sh" "$ROOT"

echo "[OK] Reparo instalado. Backup dos arquivos anteriores: $BACKUP"
echo '[ATENÇÃO] Reinicie/reimplante o serviço para limpar o OPcache do Apache.'
