#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-$(pwd)}"
AI="$ROOT/app/Services/AiReprocessService.php"
BACKUP="$ROOT/scripts/rsconnect-backup.sh"

[[ -f "$AI" ]] || { echo "[ERRO] $AI ausente."; exit 1; }
[[ -f "$BACKUP" ]] || { echo "[ERRO] $BACKUP ausente."; exit 1; }

php -l "$AI"
bash -n "$BACKUP"

grep -q '\$claimRecorded = false;' "$AI"
grep -q 'last_scheduled_claimed_at = NULL' "$AI"

python3 - "$AI" "$BACKUP" <<'PY'
from pathlib import Path
import sys

ai = Path(sys.argv[1]).read_text(encoding="utf-8")
backup = Path(sys.argv[2]).read_text(encoding="utf-8")

start = ai.index("    public function runScheduledIfDue(")
end = ai.index("    public function validCronToken(", start)
method = ai[start:end]

run_pos = method.index("$result = $this->runAll(")
mark_pos = method.index("SET last_scheduled_run_on = :run_on", run_pos)

assert run_pos < mark_pos
assert "catch (Throwable $exception)" in method
assert "last_scheduled_claimed_at = NULL" in method

fn_start = backup.index("finish_error() {")
fn_end = backup.index("\n}\n\n", fn_start)
block = backup[fn_start:fn_end]

assert "exit 1" in block
assert "exit 0" not in block

print("[OK] Ordem da contingência diária validada.")
print("[OK] Falhas de backup retornam código diferente de zero.")
PY

if [[ -x "$ROOT/scripts/verify-critical-integrity.sh" ]]; then
    bash "$ROOT/scripts/verify-critical-integrity.sh" "$ROOT"
fi

echo "[RESULTADO] HARDENING DE DEPLOY APROVADO"