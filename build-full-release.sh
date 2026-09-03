#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/html}"
OUT="${2:-/tmp/rs-connect-v36.27.12-completo.zip}"

if [[ ! -d "$ROOT/app" || ! -d "$ROOT/public" ]]; then
  echo "[ERRO] Pasta de projeto inválida: $ROOT" >&2
  exit 1
fi

command -v zip >/dev/null 2>&1 || { echo "[ERRO] Comando zip não instalado." >&2; exit 1; }

cd "$ROOT"
rm -f "$OUT"
zip -qr "$OUT" . \
  -x '.git/*' \
     '.env' '.env.*.local' \
     'storage/logs/*' 'storage/backups/*' 'storage/cache/*' \
     'backup/*' \
     '*.zip'

sha256sum "$OUT" > "${OUT}.sha256"
echo "[OK] ZIP completo criado: $OUT"
echo "[OK] SHA-256: ${OUT}.sha256"
