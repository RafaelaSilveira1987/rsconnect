# RS Connect v36.20.15 — ENT-030 / PA-004

## Proteção contra XSS, SVG e CSP

Esta entrega bloqueia SVG e formatos ativos nos uploads de identidade visual, endurece a pasta pública de uploads e substitui JavaScript inline por uma política CSP baseada em nonce.

## Publicação

1. Faça backup da aplicação, do banco, do `.env`, de `storage` e dos volumes.
2. Extraia a nova versão em uma pasta limpa.
3. Preserve o `.env` e os arquivos persistentes.
4. Faça rebuild/redeploy para atualizar o Apache e limpar o OPcache.
5. Não execute migration nova.

A migration vigente continua sendo:

```text
database/migrations/088_payment_reconciliation_schema_compat.sql
```

## Auditoria dos uploads existentes

Antes ou logo após o deploy, execute:

```bash
php bin/audit-public-uploads.php
```

Resultado esperado:

```text
[OK] Nenhum arquivo público inseguro foi encontrado.
```

Caso o comando liste SVG, HTML, JavaScript, PHP ou arquivos com conteúdo incompatível, remova-os da pasta pública ou converta as imagens para PNG, JPEG ou WEBP.

## Homologação

1. Acesse o login e confirme que a tela carrega normalmente.
2. Entre no painel e teste menu, busca, gavetas e confirmações.
3. Acesse **White label**.
4. Envie uma imagem PNG, JPG ou WEBP válida.
5. Tente enviar um SVG e confirme a rejeição.
6. Confira no navegador o header `Content-Security-Policy`.
7. Confirme que `script-src` contém nonce e não contém `unsafe-inline`.
8. Teste relatórios, gráficos, impressão e atualização de páginas.
9. Teste reiniciar/desconectar instância, excluir agenda e outros formulários com confirmação.

## Observação sobre estilos

Os scripts inline foram eliminados da política. Alguns estilos dinâmicos usados em gráficos, barras de progresso e cores de departamentos continuam autorizados apenas pela diretiva específica `style-src-attr 'unsafe-inline'`, evitando quebra visual enquanto a modularização completa de CSS não é executada.
