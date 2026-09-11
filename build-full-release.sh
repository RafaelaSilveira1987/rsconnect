#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

VERSION="${1:-36.30.7}"
OUTPUT="${2:-$ROOT/../rs-connect-vps-ready-v${VERSION}.zip}"

echo "[1/7] Requisitos PHP do host"
if ! php bin/check-requirements.php; then
  if [[ "${STRICT_RUNTIME_CHECKS:-0}" == "1" ]]; then
    echo "[ERRO] O host não possui todos os requisitos de runtime." >&2
    exit 1
  fi
  echo "[AVISO] O host de empacotamento não possui todas as extensões de produção; o Dockerfile instala o runtime canônico."
fi

echo "[2/7] Manifesto de migrations"
php bin/migrate.php verify

echo "[3/7] JSON de infraestrutura"
php -r '$files=["composer.json","manifest.json"]; foreach($files as $f){json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo "[OK] $f\n";}'

echo "[4/7] Smoke de infraestrutura"
php tests/Feature/infrastructure-installation-v36307-smoke.php

echo "[5/7] Docker Compose"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  docker compose config -q
else
  echo "[AVISO] docker compose não disponível; validação estrutural ficou a cargo do smoke test."
fi

echo "[6/7] Checksums"
find . -type f \
  ! -path './.git/*' \
  ! -path './storage/logs/*' \
  ! -path './storage/cache/*' \
  ! -path './storage/generated-reports/*' \
  ! -path './storage/conversation-attachments/*' \
  ! -path './storage/app/white-label/*' \
  ! -name '.env' \
  ! -name 'SHA256SUMS.txt' \
  ! -name '*.zip' \
  -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt

echo "[7/7] ZIP"
rm -f "$OUTPUT"
zip -qr "$OUTPUT" . \
  -x '.git/*' '.env' 'storage/logs/*' 'storage/cache/*' 'storage/generated-reports/*' 'storage/conversation-attachments/*' '*.zip'

echo "Pacote criado: $OUTPUT"
sha256sum "$OUTPUT"
