# RS Connect v36.23.0 — publicação

## 1. Backup

Faça backup do banco e da pasta da aplicação antes da substituição.

## 2. Publicação

Substitua os arquivos pelo pacote v36.23.0 e reinicie/reimplante o serviço RS Connect.

## 3. Banco de dados

```bash
cd /var/www/html
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Resultado esperado:

```text
99 aplicada(s), 0 pendente(s), 0 divergente(s)
```

Não execute `baseline`, pois o banco já possui histórico.

## 4. Agendador da fila

No EasyPanel, crie uma tarefa para executar a cada minuto:

```bash
php /var/www/html/bin/process-notifications.php
```

Alternativa por HTTP:

```text
https://SEU_DOMINIO/webhooks/notifications/process?token=SEU_TOKEN
```

Nesse caso, configure no ambiente:

```env
NOTIFICATION_CRON_TOKEN=uma-chave-longa-e-aleatoria
```

## 5. Configuração por empresa

Acesse **Notificações > Notificações de agenda e orçamento**.

Para cada evento:

1. ative o evento;
2. marque **Aviso no sistema**;
3. marque **WhatsApp para equipe**, quando desejar;
4. informe o número com DDI e DDD;
5. configure antecedência do lembrete ou tempo de escalonamento;
6. salve.

O telefone da equipe deve ser diferente do próprio número conectado à Evolution.

## 6. Homologação

- crie um agendamento e confirme o aviso interno;
- use **Processar fila agora** para testar o WhatsApp;
- confirme, cancele e solicite remarcação;
- envie uma mensagem pedindo orçamento;
- confirme a criação do aviso e da tarefa comercial;
- deixe uma solicitação vencer para testar o alerta de atraso.
