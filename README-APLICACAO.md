# RS Connect — White Label somente com logo

Este pacote altera exclusivamente o recurso de White Label da versão fornecida.

## Resultado

- a tela permite selecionar a empresa, enviar, substituir ou remover somente a logo;
- formatos aceitos: PNG, JPG/JPEG e WEBP;
- limite de 2 MB e até 4096 × 4096 pixels;
- o nome **RS Connect**, cores, textos, favicon, login e rodapé permanecem padronizados;
- configurações antigas continuam preservadas no banco, mas o `BrandingService` deixa de aplicá-las;
- salvar uma logo ativa a personalização automaticamente;
- remover a logo desativa a personalização e restaura o padrão integral da RS Connect;
- nenhum SQL ou migration adicional é necessário.

## Arquivos alterados

- `app/Controllers/WhiteLabelController.php`
- `app/Services/BrandingService.php`
- `app/Views/white_label/index.php`
- `tests/Feature/white-label-logo-only-smoke.php`

## Aplicação automática

Extraia este pacote e execute:

```bash
bash apply-white-label-logo-only.sh /var/www/html
```

O script cria um backup em:

```text
storage/backups/white-label-logo-only-AAAAMMDD-HHMMSS
```

Depois copia os arquivos, valida a sintaxe PHP e executa o teste de fumaça.

## Aplicação manual

Também é possível copiar as pastas `app` e `tests` sobre a raiz do projeto, preservando a estrutura de diretórios.

Depois execute:

```bash
php -l app/Controllers/WhiteLabelController.php
php -l app/Services/BrandingService.php
php -l app/Views/white_label/index.php
php tests/Feature/white-label-logo-only-smoke.php
```

## Observação sobre o banco

Os campos antigos de cores, textos, favicon, nome e domínio não são removidos. Isso evita perda de dados e mantém compatibilidade com o banco atual. Eles apenas deixam de influenciar a interface.
