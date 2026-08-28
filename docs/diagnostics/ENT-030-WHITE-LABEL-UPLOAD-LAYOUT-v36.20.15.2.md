# Diagnóstico — White Label v36.20.15.2

## Problema reproduzido

O controller utilizava `public/uploads/white-label/tenant-ID`. O código da aplicação não é um local persistente confiável para arquivos gerados em runtime e pode não permitir gravação pelo usuário do Apache/PHP.

## Correção

- armazenamento movido para `storage/app/white-label`;
- entrega por controller público restrito a imagens rasterizadas válidas;
- rota de imagem sem sessão;
- Dockerfile prepara e atribui permissão ao diretório persistente;
- URLs anteriores de `/uploads` continuam compatíveis;
- nenhum arquivo real é removido automaticamente.

## Segurança

- nome físico aleatório;
- limite de 2 MB;
- MIME validado por `finfo`;
- estrutura validada por `getimagesize`;
- somente PNG, JPEG e WEBP;
- validação dupla ao servir o arquivo;
- `nosniff` e CSP restrita na resposta da imagem;
- proteção contra traversal por `basename` e expressão regular.

## Validação funcional

Uma imagem PNG de teste foi colocada no armazenamento e solicitada pela rota pública. Resultado:

```text
HTTP 200
Content-Type: image/png
Content-Disposition: inline
Cache-Control: public, max-age=86400, immutable
X-Content-Type-Options: nosniff
```

O corpo retornado foi reconhecido como PNG válido.
