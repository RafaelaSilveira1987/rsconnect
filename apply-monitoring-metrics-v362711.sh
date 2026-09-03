#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_ROOT="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$TARGET_ROOT/storage/backups/monitoring-commercial-n8n-v362711-$STAMP"

FILES=(
  "app/Services/TenantMetricsService.php"
  "app/Services/AccessControlService.php"
  "app/Services/N8nLiveMetricsService.php"
  "app/Services/AdminDashboardService.php"
  "app/Services/AdminExecutiveDashboardService.php"
  "app/Services/OperationalAlertService.php"
  "app/Services/OperationsService.php"
  "app/Services/AppVersionService.php"
  "tests/Feature/operational-commercial-n8n-v362711-smoke.php"
  "bin/monitoring-source-audit.php"
)

if [[ ! -d "$TARGET_ROOT/app/Services" ]]; then
  echo "[ERRO] Projeto RS Connect não encontrado em: $TARGET_ROOT" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

for relative in "${FILES[@]}"; do
  source="$SOURCE_DIR/$relative"
  target="$TARGET_ROOT/$relative"
  if [[ ! -f "$source" ]]; then
    echo "[ERRO] Arquivo do hotfix ausente: $relative" >&2
    exit 1
  fi

  if [[ -f "$target" ]]; then
    mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
    cp -a "$target" "$BACKUP_DIR/$relative"
  fi

  mkdir -p "$(dirname "$target")"
  cp -a "$source" "$target"
done

chmod +x "$TARGET_ROOT/bin/monitoring-source-audit.php" 2>/dev/null || true

echo "[OK] Backup lógico: $BACKUP_DIR"

echo "[1/3] Validando sintaxe PHP..."
for relative in "${FILES[@]}"; do
  if [[ "$relative" == *.php ]]; then
    php -l "$TARGET_ROOT/$relative" >/dev/null
  fi
done
echo "[OK] Sintaxe PHP válida."

echo "[2/3] Validando regras de monitoramento v36.27.11..."
php "$TARGET_ROOT/tests/Feature/operational-commercial-n8n-v362711-smoke.php"

echo "[3/3] Auditando fontes reais do ambiente..."
php "$TARGET_ROOT/bin/monitoring-source-audit.php"

echo
printf '%s\n' "[APROVADO] RS Connect 36.27.11 aplicado com sucesso."
printf '%s\n' "Para exigir também a prova live do n8n, configure N8N_API_KEY e execute:"
printf '%s\n' "php $TARGET_ROOT/bin/monitoring-source-audit.php --require-n8n-live"
