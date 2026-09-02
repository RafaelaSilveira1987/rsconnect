#!/usr/bin/env bash
set -uo pipefail

# RS Connect — homologação real de backup + restauração em banco temporário.
# NÃO restaura sobre o banco de produção.
# Uso:
#   ./scripts/verify-backup-restore.sh /backups/rs-connect 5 rs_connect
# Variável opcional:
#   RS_CONNECT_MYSQL_SERVICE=sites_mysql

STARTED_AT="$(date --iso-8601=seconds)"
OUTPUT_DIR="${1:-/backups/rs-connect}"
RETENTION_DAYS="${2:-5}"
DATABASE_NAME="${3:-rs_connect}"
MYSQL_SERVICE="${RS_CONNECT_MYSQL_SERVICE:-sites_mysql}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_SCRIPT="${SCRIPT_DIR}/rsconnect-backup.sh"
STAMP="$(date +%Y%m%d-%H%M%S)"
TEMP_DB="rsconnect_restore_verify_${STAMP}_$$"
TEMP_DB_CREATED=0
CONTAINER_ID=""
BACKUP_PATH=""
BACKUP_CHECKSUM=""
EVIDENCE_PATH="${OUTPUT_DIR%/}/restore-verification-${STAMP}.json"

json_escape() {
  local value="${1:-}"
  value=${value//\\/\\\\}
  value=${value//\"/\\\"}
  value=${value//$'\n'/\\n}
  value=${value//$'\r'/\\r}
  printf '%s' "$value"
}

cleanup() {
  if (( TEMP_DB_CREATED == 1 )) && [[ "$TEMP_DB" == rsconnect_restore_verify_* ]] && [[ -n "$CONTAINER_ID" ]]; then
    docker exec "$CONTAINER_ID" sh -lc '
      MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "DROP DATABASE IF EXISTS \`$1\`"
    ' sh "$TEMP_DB" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT INT TERM

fail() {
  local message="${1:-Falha desconhecida na homologação de restauração.}"
  printf '[ERRO] %s\n' "$message" >&2
  mkdir -p "$OUTPUT_DIR" 2>/dev/null || true
  printf '{"status":"error","verified":false,"message":"%s","database":"%s","temporary_database":"%s","backup":"%s","started_at":"%s","finished_at":"%s"}\n' \
    "$(json_escape "$message")" \
    "$(json_escape "$DATABASE_NAME")" \
    "$(json_escape "$TEMP_DB")" \
    "$(json_escape "$BACKUP_PATH")" \
    "$STARTED_AT" \
    "$(date --iso-8601=seconds)" > "$EVIDENCE_PATH" 2>/dev/null || true
  exit 1
}

ok() {
  printf '[OK] %s\n' "$1"
}

[[ "$DATABASE_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "Nome do banco de produção inválido."
[[ "$TEMP_DB" =~ ^rsconnect_restore_verify_[A-Za-z0-9_]+$ ]] || fail "Nome do banco temporário inválido."
[[ "$OUTPUT_DIR" == /* ]] || fail "O caminho de backup deve ser absoluto."
[[ -f "$BACKUP_SCRIPT" ]] || fail "Script de backup não encontrado em ${BACKUP_SCRIPT}."
command -v docker >/dev/null 2>&1 || fail "Docker CLI não encontrado no host."
command -v gzip >/dev/null 2>&1 || fail "gzip não encontrado no host."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum não encontrado no host."

CONTAINER_ID="$(docker ps \
  --filter "label=com.docker.swarm.service.name=${MYSQL_SERVICE}" \
  --format '{{.ID}}' 2>/dev/null | head -n 1)"

if [[ -z "$CONTAINER_ID" ]]; then
  CONTAINER_ID="$(docker ps --format '{{.ID}} {{.Names}}' 2>/dev/null \
    | awk -v service="$MYSQL_SERVICE" '$0 ~ service {print $1; exit}')"
fi

[[ -n "$CONTAINER_ID" ]] || fail "Container MySQL do serviço ${MYSQL_SERVICE} não encontrado."
ok "Container MySQL localizado: ${CONTAINER_ID}"

printf '%s\n' '=== 1. GERANDO BACKUP REAL ==='
BACKUP_OUTPUT="$(RS_CONNECT_MYSQL_SERVICE="$MYSQL_SERVICE" bash "$BACKUP_SCRIPT" "$OUTPUT_DIR" "$RETENTION_DAYS" "$DATABASE_NAME" 2>&1)"
BACKUP_EXIT=$?
printf '%s\n' "$BACKUP_OUTPUT"
(( BACKUP_EXIT == 0 )) || fail "A rotina oficial de backup retornou código ${BACKUP_EXIT}."

BACKUP_JSON="$(printf '%s\n' "$BACKUP_OUTPUT" | tail -n 1)"
printf '%s' "$BACKUP_JSON" | grep -q '"status":"success"' || fail "A rotina de backup não retornou status=success."
printf '%s' "$BACKUP_JSON" | grep -q '"verified":true' || fail "A rotina de backup não retornou verified=true."

BACKUP_PATH="$(printf '%s' "$BACKUP_JSON" | sed -n 's/.*"location":"\([^"]*\)".*/\1/p')"
BACKUP_CHECKSUM="$(printf '%s' "$BACKUP_JSON" | sed -n 's/.*"checksum":"\([a-f0-9]\{64\}\)".*/\1/p')"
[[ -n "$BACKUP_PATH" && -f "$BACKUP_PATH" ]] || fail "Arquivo de backup informado pela rotina não foi encontrado."
[[ "$BACKUP_CHECKSUM" =~ ^[a-f0-9]{64}$ ]] || fail "Checksum retornado pela rotina é inválido."
ok "Backup publicado: ${BACKUP_PATH}"

gzip -t "$BACKUP_PATH" || fail "gzip -t falhou no arquivo gerado."
ACTUAL_CHECKSUM="$(sha256sum "$BACKUP_PATH" | awk '{print $1}')"
[[ "$ACTUAL_CHECKSUM" == "$BACKUP_CHECKSUM" ]] || fail "SHA-256 atual difere do checksum retornado pela rotina."
ok "Integridade gzip e SHA-256 confirmadas"

printf '%s\n' '=== 2. CRIANDO BANCO TEMPORÁRIO ==='
docker exec "$CONTAINER_ID" sh -lc '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
' sh "$TEMP_DB" >/dev/null || fail "Não foi possível criar o banco temporário ${TEMP_DB}."
TEMP_DB_CREATED=1
ok "Banco temporário criado: ${TEMP_DB}"

printf '%s\n' '=== 3. RESTAURANDO BACKUP ==='
if ! gzip -cd "$BACKUP_PATH" | docker exec -i "$CONTAINER_ID" sh -lc '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$1"
' sh "$TEMP_DB"; then
  fail "Falha ao restaurar o dump em ${TEMP_DB}."
fi
ok "Dump restaurado integralmente no banco temporário"

mysql_scalar() {
  local db="$1"
  local sql="$2"
  docker exec "$CONTAINER_ID" sh -lc '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "$2" "$1"
  ' sh "$db" "$sql"
}

printf '%s\n' '=== 4. COMPARANDO ESTRUTURA ==='
PROD_TABLE_COUNT="$(mysql_scalar "$DATABASE_NAME" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';")" || fail "Não foi possível contar tabelas em produção."
TEMP_TABLE_COUNT="$(mysql_scalar "$TEMP_DB" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';")" || fail "Não foi possível contar tabelas restauradas."

[[ "$PROD_TABLE_COUNT" =~ ^[0-9]+$ && "$TEMP_TABLE_COUNT" =~ ^[0-9]+$ ]] || fail "Contagem de tabelas inválida."
(( PROD_TABLE_COUNT > 0 )) || fail "Banco de produção não retornou tabelas."
[[ "$PROD_TABLE_COUNT" == "$TEMP_TABLE_COUNT" ]] || fail "Quantidade de tabelas diverge: produção=${PROD_TABLE_COUNT}, restauração=${TEMP_TABLE_COUNT}."
ok "Quantidade de tabelas idêntica: ${PROD_TABLE_COUNT}"

PROD_TABLES="$(docker exec "$CONTAINER_ID" sh -lc '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SHOW TABLES" "$1"
' sh "$DATABASE_NAME" | sort)" || fail "Falha ao listar tabelas de produção."
TEMP_TABLES="$(docker exec "$CONTAINER_ID" sh -lc '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SHOW TABLES" "$1"
' sh "$TEMP_DB" | sort)" || fail "Falha ao listar tabelas restauradas."

[[ "$PROD_TABLES" == "$TEMP_TABLES" ]] || fail "A lista de tabelas restaurada difere da produção."
ok "Lista completa de tabelas idêntica"

printf '%s\n' '=== 5. COMPARANDO REGISTROS CRÍTICOS ==='
CRITICAL_TABLES=(tenants users conversations messages evolution_instances subscriptions)
MATCHED_TABLES=0
for table in "${CRITICAL_TABLES[@]}"; do
  EXISTS="$(mysql_scalar "$DATABASE_NAME" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${table}';")" || fail "Falha ao verificar tabela ${table}."
  if [[ "$EXISTS" == "1" ]]; then
    PROD_ROWS="$(mysql_scalar "$DATABASE_NAME" "SELECT COUNT(*) FROM \`${table}\`;")" || fail "Falha ao contar ${table} em produção."
    TEMP_ROWS="$(mysql_scalar "$TEMP_DB" "SELECT COUNT(*) FROM \`${table}\`;")" || fail "Falha ao contar ${table} restaurada."
    [[ "$PROD_ROWS" == "$TEMP_ROWS" ]] || fail "Contagem da tabela ${table} diverge: produção=${PROD_ROWS}, restauração=${TEMP_ROWS}."
    MATCHED_TABLES=$((MATCHED_TABLES + 1))
    ok "${table}: ${PROD_ROWS} registros"
  fi
done

(( MATCHED_TABLES > 0 )) || fail "Nenhuma tabela crítica conhecida foi encontrada para comparação."

printf '%s\n' '=== 6. RESULTADO ==='
FINISHED_AT="$(date --iso-8601=seconds)"
printf '{"status":"success","verified":true,"backup_restore_verified":true,"database":"%s","temporary_database":"%s","backup":"%s","checksum":"%s","table_count":%s,"critical_tables_compared":%s,"started_at":"%s","finished_at":"%s"}\n' \
  "$(json_escape "$DATABASE_NAME")" \
  "$(json_escape "$TEMP_DB")" \
  "$(json_escape "$BACKUP_PATH")" \
  "$BACKUP_CHECKSUM" \
  "$TEMP_TABLE_COUNT" \
  "$MATCHED_TABLES" \
  "$STARTED_AT" \
  "$FINISHED_AT" | tee "$EVIDENCE_PATH"

printf '[APROVADO] Backup gerado e restaurado com sucesso em banco temporário.\n'
printf '[EVIDÊNCIA] %s\n' "$EVIDENCE_PATH"
