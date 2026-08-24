# RS Connect v36.6.37 — Correção do status Evolution

## Falha localizada

O endpoint `/instances/status-feed` consultava corretamente a Evolution API, mas o `UPDATE` seguinte reutilizava o parâmetro nomeado `:connected` duas vezes. Como o projeto usa `PDO::ATTR_EMULATE_PREPARES = false`, o MySQL/PDO gerava `SQLSTATE[HY093] Invalid parameter number`.

A exceção era capturada sem registro e apenas atualizava `last_status_check_at`. Assim, a tela aguardava mais 60 segundos, repetia a falha e nunca gravava o estado retornado pela Evolution.

O webhook `CONNECTION_UPDATE` tinha o mesmo defeito com o parâmetro `:status_connected`.

## Correções

- parâmetros separados para limpar QR Code e expiração;
- polling ao vivo reduzido para janela de 10 segundos;
- erros registrados no log PHP com instância e motivo;
- motivo seguro armazenado em `connection_reason` para diagnóstico na tela;
- endpoint do polling gerado por `Router::url`, sem depender de instalação na raiz;
- cache do JavaScript atualizado para `v=36.6.37`.

## Banco de dados

Não há migration nova. Não é necessário alterar dados nem recriar instâncias.

## Teste esperado

Com a instância `rafaela` desconectada, o feed deve registrar:

```json
{"status":"disconnected","connection_state":"close"}
```

Após conexão pelo QR Code, deve atualizar para:

```json
{"status":"connected","connection_state":"open"}
```
