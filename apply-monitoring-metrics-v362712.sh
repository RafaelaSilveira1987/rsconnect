#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_ROOT="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$TARGET_ROOT/storage/backups/monitoring-commercial-n8n-v362712-$STAMP"

FILES=(
  "app/Services/TenantMetricsService.php"
  "app/Services/AccessControlService.php"
  "app/Services/N8nLiveMetricsService.php"
  "app/Services/N8nWorkflowControlService.php"
  "app/Services/AdminDashboardService.php"
  "app/Services/AdminExecutiveDashboardService.php"
  "app/Services/OperationalAlertService.php"
  "app/Services/OperationsService.php"
  "app/Services/AppVersionService.php"
  "tests/Feature/operational-commercial-n8n-v362711-smoke.php"
  "tests/Feature/operations-monitor-workflow-autoreactivate-v362712-smoke.php"
  "bin/monitoring-source-audit.php"
  "bin/ensure-operations-monitor-workflow.php"
)

if [[ ! -d "$TARGET_ROOT/app/Services" ]]; then
  echo "[ERRO] Projeto RS Connect não encontrado em: $TARGET_ROOT" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

rollback() {
  code=$?
  echo >&2
  echo "[ERRO] Aplicação interrompida. Restaurando arquivos do backup..." >&2
  for relative in "${FILES[@]}"; do
    target="$TARGET_ROOT/$relative"
    backup="$BACKUP_DIR/$relative"
    if [[ -f "$backup" ]]; then
      mkdir -p "$(dirname "$target")"
      cp -a "$backup" "$target"
    else
      rm -f "$target"
    fi
  done
  echo "[OK] Rollback dos arquivos concluído. Backup: $BACKUP_DIR" >&2
  exit "$code"
}
trap rollback ERR

for relative in "${FILES[@]}"; do
  source="$SOURCE_DIR/$relative"
  target="$TARGET_ROOT/$relative"
  if [[ ! -f "$source" ]]; then
    echo "[ERRO] Arquivo do hotfix ausente: $relative" >&2
    false
  fi
  if [[ -f "$target" ]]; then
    mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
    cp -a "$target" "$BACKUP_DIR/$relative"
  fi
  mkdir -p "$(dirname "$target")"
  cp -a "$source" "$target"
done

chmod +x "$TARGET_ROOT/bin/monitoring-source-audit.php" "$TARGET_ROOT/bin/ensure-operations-monitor-workflow.php" 2>/dev/null || true

echo "[OK] Backup lógico: $BACKUP_DIR"

echo "[1/5] Validando sintaxe PHP..."
for relative in "${FILES[@]}"; do
  if [[ "$relative" == *.php ]]; then
    php -l "$TARGET_ROOT/$relative" >/dev/null
  fi
done
echo "[OK] Sintaxe PHP válida."

echo "[2/5] Validando regras comerciais e métricas n8n..."
php "$TARGET_ROOT/tests/Feature/operational-commercial-n8n-v362711-smoke.php"

echo "[3/5] Validando proteção do Monitor operacional..."
php "$TARGET_ROOT/tests/Feature/operations-monitor-workflow-autoreactivate-v362712-smoke.php"

echo "[4/5] Reativando/publicando o workflow crítico no n8n, se necessário..."
php "$TARGET_ROOT/bin/ensure-operations-monitor-workflow.php" --activate

echo "[5/5] Auditando fontes reais e exigindo Monitor operacional ativo..."
php "$TARGET_ROOT/bin/monitoring-source-audit.php" --require-n8n-live --require-monitor-active

trap - ERR

echo
printf '%s\n' "[APROVADO] RS Connect 36.27.12 aplicado; Monitor operacional confirmado como publicado e agendado."
printf '%s\n' "O workflow voltará a executar no próximo ciclo do próprio Schedule Trigger."
printf '%s\n' "Para conferir a execução depois do ciclo:"
printf '%s\n' "php $TARGET_ROOT/bin/ensure-operations-monitor-workflow.php"
