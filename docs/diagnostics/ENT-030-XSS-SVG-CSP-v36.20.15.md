# Diagnóstico técnico — ENT-030 / PA-004

## Achados

1. O upload white label aceitava `image/svg+xml` e ICO e gravava diretamente em `public/uploads`.
2. A pasta pública não possuía uma lista positiva de extensões servidas.
3. A CSP global usava `script-src 'unsafe-inline'` e `style-src 'unsafe-inline'`.
4. Existiam 24 handlers JavaScript inline em formulários, selects e botões.
5. Um nome de instância era inserido com `innerHTML` sem escape explícito.
6. Tooltips dos relatórios inseriam `row.label` em `innerHTML` sem escape.
7. `public/health.php` ainda retornava nome do banco e mensagem de exceção.

## Solução

- uploads de marca aceitam somente PNG, JPEG e WEBP;
- `finfo`, `getimagesize`, `is_uploaded_file`, limite de 2 MB, 4096 × 4096 e 16 milhões de pixels;
- `.htaccess` em `public/uploads` nega qualquer arquivo fora da lista positiva;
- `public/router.php` aplica a mesma proteção no servidor PHP local;
- CSP gera nonce criptográfico por requisição;
- `script-src-attr 'none'` bloqueia handlers embutidos;
- ações foram migradas para `data-*` e listeners no `app.js`;
- conteúdo dinâmico identificado foi migrado para `textContent` ou escape HTML;
- auditoria read-only disponível em `bin/audit-public-uploads.php`.

## Risco residual

`style-src-attr 'unsafe-inline'` permanece necessário para barras, gráficos e variáveis CSS calculadas no servidor. A remoção completa será tratada em conjunto com a modularização de CSS/JavaScript prevista na ENT-038.
