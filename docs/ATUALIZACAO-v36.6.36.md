# Atualização RS Connect 36.6.36

## Governança de mensagens e Evolution em tempo real

Esta versão consolida a base da 36.6.35 e adiciona três capacidades:

1. identificação do usuário que enviou cada resposta humana pelo WhatsApp;
2. política configurável de retenção do conteúdo das mensagens;
3. atualização de QR Code e estado da Evolution por webhook, com reconciliação automática.

## Antes do deploy

- mantenha uma cópia do banco e do pacote atualmente implantado;
- confirme que as migrations anteriores, especialmente a `062_prompt_studio_and_versions.sql`, foram aplicadas;
- não remova o armazenamento das mensagens manualmente no banco: a limpeza deve ser feita pela política desta versão.

## Deploy

Implante o ZIP `rs-connect-vps-ready-36.6.36.zip` no mesmo serviço do RS Connect.

Depois, aplique:

```sql
SOURCE database/migrations/063_message_governance_evolution_realtime.sql;
```

Caso a 36.6.35 ainda não tenha sido homologada no banco, execute antes:

```sql
SOURCE database/migrations/062_prompt_studio_and_versions.sql;
SOURCE database/migrations/063_message_governance_evolution_realtime.sql;
```

## Variável nova

Configure no ambiente do RS Connect:

```env
MESSAGE_RETENTION_TOKEN=gere_um_segredo_forte_e_exclusivo
```

Mantenha também configurado:

```env
EVOLUTION_WEBHOOK_TOKEN=seu_token_atual_da_evolution
APP_URL=https://seu-dominio-rs-connect
```

Depois de alterar o ambiente, faça redeploy/restart.

## Identificação do atendente

Em **Minha empresa / Configurações da empresa → Identificação da equipe e retenção**:

- habilite a assinatura das mensagens humanas;
- escolha o formato: nome, nome e função ou nome e empresa.

Em **Usuários**, defina para cada pessoa:

- nome exibido ao cliente;
- função exibida;
- se a assinatura pode ser utilizada.

O RS Connect guarda o texto original separadamente do conteúdo efetivamente enviado. Exemplo:

```text
Original: Vou verificar sua solicitação.
Entregue: Rafaela — Atendimento\nVou verificar sua solicitação.
```

A assinatura é aplicada somente a mensagens humanas enviadas pela tela de Conversas. Respostas automáticas continuam identificadas como IA/agente.

## Política de retenção

Modos disponíveis por empresa:

- **Completa:** preserva o conteúdo das mensagens; payloads técnicos antigos ainda podem ser removidos.
- **Reduzida:** remove o conteúdo após a quantidade de dias configurada.
- **Efêmera:** remove o conteúdo após a quantidade de horas configurada e somente quando a conversa já estiver fora da janela ativa.

A limpeza preserva:

- data e horário;
- direção da mensagem;
- remetente humano/IA/contato;
- usuário responsável;
- status de entrega;
- métricas, tokens e custos registrados em suas tabelas próprias.

A limpeza remove:

- texto original e texto entregue, conforme a política;
- payload bruto da Evolution após a janela técnica configurada;
- preview antigo da conversa, substituído por uma indicação de retenção.

### Execução automática

Depois de configurar `MESSAGE_RETENTION_TOKEN`:

1. abra **n8n → Templates**;
2. baixe **Retenção diária de mensagens**;
3. importe no n8n;
4. publique o workflow.

O template executa diariamente às 02:30 e autentica pelo header:

```text
X-RS-Message-Retention-Token
```

O Super Admin também pode executar a política manualmente na tela de configurações da empresa.

## Evolution em tempo real

Ao criar uma instância ou solicitar um novo QR Code, o RS Connect configura na Evolution os eventos:

```text
MESSAGES_UPSERT
MESSAGES_UPDATE
CONNECTION_UPDATE
QRCODE_UPDATED
CONTACTS_UPSERT
```

A tela passa a acompanhar:

- QR Code disponível;
- conectando;
- conectado;
- desconectado;
- sessão encerrada;
- conexão recusada ou com erro;
- nome, telefone e foto do perfil quando informados;
- motivo e horário da última mudança.

O caminho principal é o webhook. A interface consulta o feed do RS Connect a cada poucos segundos e, quando o webhook estiver atrasado, o backend reconcilia diretamente o estado com a Evolution em uma janela controlada.

Para instâncias já existentes, abra a instância e gere/reconecte o QR uma vez para garantir que a lista nova de eventos tenha sido registrada na Evolution.

## Sem migration adicional

A única migration nova deste pacote é a `063_message_governance_evolution_realtime.sql`.
