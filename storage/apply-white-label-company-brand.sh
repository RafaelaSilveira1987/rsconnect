#!/usr/bin/env bash
set -euo pipefail

PATCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-}"

if [[ -z "$TARGET" ]]; then
    echo "Uso: bash apply-white-label-company-brand.sh /caminho/do/rs-connect" >&2
    exit 1
fi

TARGET="$(cd "$TARGET" && pwd)"
if [[ ! -f "$TARGET/app/Controllers/WhiteLabelController.php" ]]; then
    echo "Projeto RS Connect não encontrado em: $TARGET" >&2
    exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/storage/backups/white-label-company-brand-$STAMP"
mkdir -p \
    "$BACKUP/app/Controllers" \
    "$BACKUP/app/Services" \
    "$BACKUP/app/Views/white_label" \
    "$BACKUP/app/Views/layouts" \
    "$BACKUP/tests/Feature"

FILES=(
    "app/Controllers/WhiteLabelController.php"
    "app/Services/BrandingService.php"
    "app/Views/white_label/index.php"
    "app/Views/layouts/app.php"
    "tests/Feature/white-label-company-brand-smoke.php"
)

for REL in "${FILES[@]}"; do
    if [[ -f "$TARGET/$REL" ]]; then
        cp -a "$TARGET/$REL" "$BACKUP/$REL"
    fi
    mkdir -p "$(dirname "$TARGET/$REL")"
    install -m 0644 "$PATCH_DIR/$REL" "$TARGET/$REL"
done

# O arquivo da logo precisa ser gravável pelo Apache/PHP e permanecer no storage.
install -d -m 0775 "$TARGET/storage/app/white-label"
if [[ "${EUID:-$(id -u)}" -eq 0 ]] && id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$TARGET/storage/app/white-label"
fi
chmod -R u+rwX,g+rwX "$TARGET/storage/app/white-label"

echo "Backup criado em: $BACKUP"

php -l "$TARGET/app/Controllers/WhiteLabelController.php"
php -l "$TARGET/app/Services/BrandingService.php"
php -l "$TARGET/app/Views/white_label/index.php"
php -l "$TARGET/app/Views/layouts/app.php"
php -l "$TARGET/tests/Feature/white-label-company-brand-smoke.php"
php "$TARGET/tests/Feature/white-label-company-brand-smoke.php"

echo "Correção de nome e logo da empresa aplicada com sucesso."
