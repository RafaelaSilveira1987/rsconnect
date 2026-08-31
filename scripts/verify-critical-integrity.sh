#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/html}"
fail=0

check_php() {
  local file="$1"
  local needle="$2"
  if [[ ! -f "$ROOT/$file" ]]; then
    echo "[ERRO] Arquivo ausente: $file"
    fail=1
    return
  fi
  if grep -q "RS Connect v36.3.0 — backup real" "$ROOT/$file"; then
    echo "[ERRO] Script de backup encontrado dentro de $file"
    fail=1
  fi
  if ! grep -q "$needle" "$ROOT/$file"; then
    echo "[ERRO] Marcador esperado ausente em $file: $needle"
    fail=1
  fi
  php -l "$ROOT/$file" >/dev/null || fail=1
}

check_php "bin/operations-monitor.php" "new OperationsService"
check_php "app/Services/OperationsService.php" "final class OperationsService"

if [[ ! -f "$ROOT/scripts/rsconnect-backup.sh" ]]; then
  echo "[ERRO] Script de backup ausente."
  fail=1
elif ! grep -q '^#!/usr/bin/env bash' "$ROOT/scripts/rsconnect-backup.sh"; then
  echo "[ERRO] Cabeçalho inválido no script de backup."
  fail=1
fi

if [[ "$fail" -ne 0 ]]; then
  echo "[FALHA] Integridade dos arquivos críticos inválida."
  exit 1
fi

echo "[OK] Arquivos críticos íntegros."
