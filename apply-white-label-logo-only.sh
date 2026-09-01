#!/usr/bin/env bash
set -euo pipefail

PATCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-}"

if [[ -z "$TARGET" ]]; then
    echo "Uso: bash apply-white-label-logo-only.sh /caminho/do/rs-connect" >&2
    exit 1
fi

TARGET="$(cd "$TARGET" && pwd)"
if [[ ! -f "$TARGET/app/Controllers/WhiteLabelController.php" ]]; then
    echo "Projeto RS Connect não encontrado em: $TARGET" >&2
    exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/storage/backups/white-label-logo-only-$STAMP"
mkdir -p "$BACKUP/app/Controllers" "$BACKUP/app/Services" "$BACKUP/app/Views/white_label" "$BACKUP/tests/Feature"

FILES=(
    "app/Controllers/WhiteLabelController.php"
    "app/Services/BrandingService.php"
    "app/Views/white_label/index.php"
    "tests/Feature/white-label-logo-only-smoke.php"
)

for REL in "${FILES[@]}"; do
    if [[ -f "$TARGET/$REL" ]]; then
        cp -a "$TARGET/$REL" "$BACKUP/$REL"
    fi
    mkdir -p "$(dirname "$TARGET/$REL")"
    install -m 0644 "$PATCH_DIR/$REL" "$TARGET/$REL"
done

echo "Backup criado em: $BACKUP"

php -l "$TARGET/app/Controllers/WhiteLabelController.php"
php -l "$TARGET/app/Services/BrandingService.php"
php -l "$TARGET/app/Views/white_label/index.php"
php -l "$TARGET/tests/Feature/white-label-logo-only-smoke.php"
php "$TARGET/tests/Feature/white-label-logo-only-smoke.php"

echo "Correção aplicada com sucesso."
