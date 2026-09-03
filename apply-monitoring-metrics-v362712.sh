#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
TARGET_ROOT="${1:-/var/www/html}"
TARGET_ROOT="$(cd "$TARGET_ROOT" && pwd -P)"
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

# Segurança crítica: nunca aplicar a partir da própria raiz de produção.
# Nesse cenário source e target seriam o mesmo arquivo e um rollback poderia
# interpretar arquivos não copiados como 'novos', removendo-os.
if [[ "$SOURCE_DIR" == "$TARGET_ROOT" ]]; then
  cat >&2 <<EOF
[ERRO] O instalador foi colocado dentro da própria raiz do RS Connect:
       $TARGET_ROOT

Não execute este hotfix com SOURCE_DIR = TARGET_ROOT.
Extraia o pacote em outro diretório (recomendado: /tmp) e execute de lá.

Exemplo:
  mkdir -p /tmp/rsconnect-v362712
  unzip rs-connect-v36.27.12-r1-installer-seguro.zip -d /tmp/rsconnect-v362712
  cd /tmp/rsconnect-v362712/rs-connect-v36.27.12-r1-installer-seguro
  bash apply-monitoring-metrics-v362712.sh /var/www/html
EOF
  exit 2
fi

# Confirma que todos os arquivos do pacote existem ANTES de tocar em produção.
for relative in "${FILES[@]}"; do
  source="$SOURCE_DIR/$relative"
  if [[ ! -f "$source" ]]; then
    echo "[ERRO] Arquivo do hotfix ausente: $relative" >&2
    exit 3
  fi
done

mkdir -p "$BACKUP_DIR"
MANIFEST="$BACKUP_DIR/.preexisting-files"
: > "$MANIFEST"

# Faz backup completo do estado anterior antes da primeira cópia.
for relative in "${FILES[@]}"; do
  target="$TARGET_ROOT/$relative"
  if [[ -f "$target" ]]; then
    echo "$relative" >> "$MANIFEST"
    mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
    cp -a "$target" "$BACKUP_DIR/$relative"
  fi
done

rollback() {
  code=$?
  trap - ERR
  echo >&2
  echo "[ERRO] Aplicação interrompida. Restaurando exatamente o estado anterior..." >&2
  for relative in "${FILES[@]}"; do
    target="$TARGET_ROOT/$relative"
    backup="$BACKUP_DIR/$relative"
    if grep -Fxq "$relative" "$MANIFEST" 2>/dev/null; then
      if [[ -f "$backup" ]]; then
        mkdir -p "$(dirname "$target")"
        cp -a "$backup" "$target"
      else
        echo "[ALERTA] Backup esperado ausente: $relative" >&2
      fi
    else
      rm -f "$target"
    fi
  done
  echo "[OK] Rollback concluído. Backup: $BACKUP_DIR" >&2
  exit "$code"
}
trap rollback ERR

# Só agora modifica produção.
for relative in "${FILES[@]}"; do
  source="$SOURCE_DIR/$relative"
  target="$TARGET_ROOT/$relative"

  # Defesa adicional contra mesmo inode/caminho.
  if [[ -e "$target" ]] && [[ "$source" -ef "$target" ]]; then
    echo "[ERRO] Origem e destino são o mesmo arquivo: $relative" >&2
    false
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
