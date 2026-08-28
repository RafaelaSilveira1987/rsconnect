# Atualização v36.20.15 — ENT-030

## Entrega

- bloqueio de SVG e ICO em uploads white label;
- validação de MIME, estrutura, tamanho e dimensões reais de imagens;
- pasta `public/uploads` limitada a PNG, JPEG e WEBP;
- CSP com nonce para scripts e estilos embutidos;
- remoção de handlers JavaScript inline das views;
- correção de pontos de inserção dinâmica com risco de XSS;
- auditoria CLI dos uploads públicos;
- neutralização do endpoint legado `public/health.php` que ainda expunha detalhes técnicos.

## Compatibilidade

Não há alteração de banco. A aplicação mantém os SVGs estáticos confiáveis incluídos em `public/assets`, mas não permite novos SVGs enviados por usuários.
