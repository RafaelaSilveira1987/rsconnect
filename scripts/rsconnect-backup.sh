#!/usr/bin/env bash
set -uo pipefail

# RS Connect v36.3.0 — backup real do MySQL em Docker Swarm/EasyPanel.
# Uso:
#   ./scripts/rsconnect-backup.sh /backups/rs-connect 5 rs_connect
# Variável opcional:
#   RS_CONNECT_MYSQL_SERVICE=sites_mysql

STARTED_AT="$(date --iso-8601=seconds)"
OUTPUT_DIR="${1:-/backups/rs-connect}"
RETENTION_DAYS="${2:-5}"
DATABASE_NAME="${3:-rs_connect}"
MYSQL_SERVICE="${RS_CONNECT_MYSQL_SERVICE:-sites_mysql}"
TEMP_SQL=""
TEMP_GZ=""
FINAL_PATH=""

json_escape() {
  local value="${1:-}"
  value=${value//\\/\\\\}
  value=${value//\"/\\\"}
  value=${value//$'\n'/\\n}
  value=${value//$'\r'/\\r}
  printf '%s' "$value"
}

finish_error() {
  local message="${1:-Falha desconhecida ao gerar o backup.}"
  [[ -n "$TEMP_SQL" ]] && rm -f "$TEMP_SQL" 2>/dev/null || true
  [[ -n "$TEMP_GZ" ]] && rm -f "$TEMP_GZ" 2>/dev/null || true
  [[ -n "$FINAL_PATH" ]] && rm -f "$FINAL_PATH" 2>/dev/null || true
  printf '{"status":"error","verified":false,"message":"%s","started_at":"%s","finished_at":"%s"}\n' \
    "$(json_escape "$message")" "$STARTED_AT" "$(date --iso-8601=seconds)"
  exit 0
}

[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || finish_error "Retenção inválida."
(( RETENTION_DAYS >= 1 && RETENTION_DAYS <= 365 )) || finish_error "Retenção fora do intervalo permitido."
[[ "$DATABASE_NAME" =~ ^[A-Za-z0-9_]+$ ]] || finish_error "Nome do banco inválido."
[[ "$OUTPUT_DIR" == /* ]] || finish_error "O caminho do backup deve ser absoluto."

CONTAINER_ID="$(docker ps \
  --filter "label=com.docker.swarm.service.name=${MYSQL_SERVICE}" \
  --format '{{.ID}}' 2>/dev/null | head -n 1)"

if [[ -z "$CONTAINER_ID" ]]; then
  CONTAINER_ID="$(docker ps --format '{{.ID}} {{.Names}}' 2>/dev/null \
    | awk -v service="$MYSQL_SERVICE" '$0 ~ service {print $1; exit}')"
fi

[[ -n "$CONTAINER_ID" ]] || finish_error "Container MySQL do serviço ${MYSQL_SERVICE} não encontrado."

mkdir -p "$OUTPUT_DIR" || finish_error "Não foi possível criar o diretório ${OUTPUT_DIR}."
chmod 750 "$OUTPUT_DIR" 2>/dev/null || true

STAMP="$(date +%Y-%m-%d-%H%M%S)"
FILE_NAME="rs-connect-${STAMP}.sql.gz"
FINAL_PATH="${OUTPUT_DIR%/}/${FILE_NAME}"
TEMP_SQL="${FINAL_PATH%.gz}.tmp.sql"
TEMP_GZ="${FINAL_PATH}.tmp"

if ! docker exec "$CONTAINER_ID" sh -lc '
  MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump \
    -uroot \
    --single-transaction \
    --set-gtid-purged=OFF \
    --routines \
    --triggers \
    --events \
    "$1"
' sh "$DATABASE_NAME" > "$TEMP_SQL"; then
  finish_error "mysqldump falhou. Verifique o banco e as credenciais internas do container."
fi

SQL_SIZE="$(stat -c%s "$TEMP_SQL" 2>/dev/null || echo 0)"
(( SQL_SIZE >= 1024 )) || finish_error "O dump SQL ficou vazio ou pequeno demais (${SQL_SIZE} bytes)."

grep -q '^CREATE TABLE' "$TEMP_SQL" || finish_error "O dump não contém estruturas CREATE TABLE."

gzip -9 -c "$TEMP_SQL" > "$TEMP_GZ" || finish_error "Falha ao compactar o dump."
gzip -t "$TEMP_GZ" || finish_error "A validação gzip falhou."

mv -f "$TEMP_GZ" "$FINAL_PATH" || finish_error "Não foi possível publicar o arquivo final."
rm -f "$TEMP_SQL"
TEMP_SQL=""
TEMP_GZ=""
chmod 640 "$FINAL_PATH" 2>/dev/null || true

FILE_SIZE="$(stat -c%s "$FINAL_PATH" 2>/dev/null || echo 0)"
(( FILE_SIZE >= 1024 )) || finish_error "O arquivo compactado ficou pequeno demais (${FILE_SIZE} bytes)."

CHECKSUM="$(sha256sum "$FINAL_PATH" | awk '{print $1}')"
[[ "$CHECKSUM" =~ ^[a-f0-9]{64}$ ]] || finish_error "Não foi possível calcular o SHA-256."

TABLE_COUNT="$(gzip -cd "$FINAL_PATH" | grep -c '^CREATE TABLE' || true)"
(( TABLE_COUNT > 0 )) || finish_error "O arquivo validado não contém tabelas."

# Retenção somente dos arquivos gerados por esta rotina.
find "$OUTPUT_DIR" -maxdepth 1 -type f -name 'rs-connect-*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete 2>/dev/null || true

printf '{"status":"success","verified":true,"file_name":"%s","location":"%s","size_bytes":%s,"checksum":"%s","table_count":%s,"database":"%s","started_at":"%s","finished_at":"%s"}\n' \
  "$(json_escape "$FILE_NAME")" \
  "$(json_escape "$FINAL_PATH")" \
  "$FILE_SIZE" \
  "$CHECKSUM" \
  "$TABLE_COUNT" \
  "$(json_escape "$DATABASE_NAME")" \
  "$STARTED_AT" \
  "$(date --iso-8601=seconds)"
