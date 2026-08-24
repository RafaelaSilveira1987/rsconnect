# RS Connect 36.6.25 — Central de comunicação in-app

## Objetivo
Transformar Comunicados em uma central de mensagens entre a equipe RS e as empresas clientes, mantendo a comunicação administrativa separada das conversas de WhatsApp dos clientes finais.

## Migration obrigatória

```sql
SOURCE database/migrations/058_client_communication_center.sql;
```

A migration adiciona prioridade, modo de resposta, validade, leitura efetiva, confirmação e a tabela de respostas dos comunicados.

## O que muda para o cliente

- Uma caixa flutuante é exibida somente quando existe comunicado não lido.
- Minimizar/fechar a caixa não marca o comunicado como lido.
- Ao abrir, o usuário acessa um drawer com histórico e conteúdo completo.
- O comunicado pode ser somente informativo, solicitar confirmação de leitura ou permitir resposta.
- Respostas da equipe RS no mesmo tópico voltam a deixar a mensagem como não lida.
- O sininho continua funcionando como histórico geral de notificações.

## O que muda para o Super Admin

- A tela Comunicados passa a se chamar Central de comunicação.
- Indicadores de enviados, não lidos, respostas e incidentes ativos.
- Prioridade normal/importante/crítica.
- Preview do aviso antes do envio.
- Validade opcional do aviso flutuante.
- Resposta configurável: nenhuma, confirmação ou conversa.
- Histórico de leitura/respostas e painel de conversas administrativas.
- Respostas das empresas geram alerta no sino operacional do Super Admin.

## Canais externos
WhatsApp administrativo e e-mail continuam registrados como `pending_configuration` enquanto não houver provedor externo configurado. A entrega in-app é funcional nesta versão.

## Assets
Após o deploy faça atualização forçada do navegador. Os assets usam `v=36.6.25`.
