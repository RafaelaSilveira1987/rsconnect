#!/usr/bin/env bash
set -euo pipefail
ROOT="${RS_CONNECT_ROOT:-/var/www/html}"
exec php "$ROOT/bin/plan-limits-audit.php"
