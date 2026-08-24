# RS Connect v36.6.38 — Status real da Evolution

## Correção principal

O status da conexão não pode mais ser definido manualmente no cadastro. Ele passa a ser derivado exclusivamente do retorno da Evolution API.

- `open`, `connected`, `online`, `active` → `connected`;
- `connecting`, `qrcode`, `qr`, `pending`, `created` → `pending`;
- qualquer outro estado, inclusive `close` → `disconnected`.

O polling não considera um registro fresco quando `connection_state` está vazio. Isso corrige cadastros antigos que estavam com `status=connected` e `connection_state=NULL`.

O endpoint `/instances/status-feed` retorna `source_version=36.6.38-live-status`, permitindo confirmar que o código novo está realmente em execução.

## Duplicidade

O cadastro passa a impedir dois registros com a mesma combinação de URL da Evolution e `instance_name`, mesmo quando pertencem a empresas diferentes. Cadastros duplicados existentes não são excluídos automaticamente porque podem possuir conversas e contatos vinculados.

## Banco de dados

Nenhuma migration é necessária.

## Confirmação do deploy

Acesse, estando autenticado:

```text
/instances/status-feed
```

A resposta precisa conter:

```json
"source_version":"36.6.38-live-status"
```

Se esse marcador não aparecer, o contêiner ainda está executando o controller antigo. Reinicie o serviço e confira o arquivo `/var/www/html/app/Controllers/InstanceController.php`.
