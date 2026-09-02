#!/usr/bin/env bash
set -euo pipefail

# RS Connect — wrapper da homologação Evolution/WhatsApp E2E.
# Funciona tanto dentro do container quanto no host Docker/EasyPanel.

ARGS=("$@")

if command -v php >/dev/null 2>&1 && [[ -f /var/www/html/bin/evolution-e2e.php ]]; then
  exec php /var/www/html/bin/evolution-e2e.php "${ARGS[@]}"
fi

if ! command -v docker >/dev/null 2>&1; then
  echo '[ERRO] PHP da aplicação e Docker não foram encontrados neste ambiente.' >&2
  exit 1
fi

APP_CONTAINER="$(
  for c in $(docker ps -q); do
    if docker exec "$c" sh -lc 'test -f /var/www/html/bin/evolution-e2e.php && command -v php >/dev/null 2>&1' >/dev/null 2>&1; then
      echo "$c"
      break
    fi
  done
)"

if [[ -z "$APP_CONTAINER" ]]; then
  echo '[ERRO] Container do RS Connect não encontrado ou ainda está com uma versão sem bin/evolution-e2e.php.' >&2
  docker ps --format 'table {{.ID}}\t{{.Names}}\t{{.Image}}' || true
  exit 1
fi

echo "[OK] Container RS Connect: $APP_CONTAINER"
exec docker exec -it "$APP_CONTAINER" php /var/www/html/bin/evolution-e2e.php "${ARGS[@]}"
