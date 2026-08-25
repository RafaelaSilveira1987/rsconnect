# Guia de criação da instância Evolution

1. Acesse **Canais WhatsApp**.
2. Clique em **Nova conexão**.
3. Informe nome interno e identificador técnico.
4. Defina se será a conexão padrão.
5. Ative webhook e eventos necessários.
6. Configure grupos, status, listas, chamadas, leitura e histórico.
7. Clique em **Criar conexão**.
8. Aguarde a confirmação de criação na Evolution.
9. Gere o QR Code e conecte o aparelho.
10. Vincule a instância ao assistente responsável.

## Eventos mínimos recomendados

- `MESSAGES_UPSERT`
- `MESSAGES_UPDATE`
- `CONNECTION_UPDATE`
- `QRCODE_UPDATED`
- `CONTACTS_UPSERT`
- `CONTACTS_UPDATE`

## Falhas comuns

- URL da Evolution incorreta.
- API Key global ausente ou inválida.
- `APP_URL` não pública ou sem HTTPS.
- Instância criada, mas canal não vinculado ao assistente.
- Webhook sobrescrito com eventos insuficientes.
