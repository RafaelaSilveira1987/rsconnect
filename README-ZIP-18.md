# RS Connect — ZIP 18

## QR Code Evolution por empresa

Esta etapa adiciona autonomia para o cliente conectar o WhatsApp pelo próprio painel do RS Connect.

### Recursos incluídos

- Botão **Gerar QR Code** na tela de Instâncias.
- Modal profissional para exibir o QR Code retornado pela Evolution API.
- Código de pareamento exibido quando a Evolution retornar `pairingCode`.
- Botão **Atualizar status** por instância.
- Atualização do status local da instância: conectada, pendente ou desconectada.
- Registro de auditoria para geração de QR Code e consulta de status.
- Campos de controle na tabela `evolution_instances`:
  - `connection_state`
  - `last_status_check_at`
  - `qrcode_requested_at`
  - `connected_at`
  - `disconnected_at`

### Endpoints adicionados

```text
POST /instances/qrcode
POST /instances/status
```

### Evolution API usada

O RS Connect chama:

```text
GET /instance/connect/{instanceName}
GET /instance/connectionState/{instanceName}
```

A chamada usa o `base_url`, o `instance_name` e a API Key criptografada salvos no cadastro da instância.

### Como atualizar

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. Execute no Adminer:

```text
database/migrations/017_evolution_qrcode_status.sql
```

4. Limpe cache do navegador com `Ctrl + F5`.

### Como usar

1. Entre em **Instâncias**.
2. Cadastre a instância vinculada à empresa correta.
3. Clique em **Gerar QR Code**.
4. O cliente escaneia pelo WhatsApp em **Aparelhos conectados**.
5. Clique em **Atualizar status** para confirmar se conectou.

### Observações

- O QR Code depende da Evolution retornar `base64`. Em algumas versões/configurações, a Evolution pode retornar apenas `code` ou `pairingCode`.
- Se a Evolution não retornar imagem, tente clicar novamente em **Gerar QR Code** ou validar a versão/configuração da Evolution API.
- O webhook do RS Connect continua sendo configurado como antes, por instância.
