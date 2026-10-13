# Atualização RS Connect 36.43.0

## Objetivo

A versão 36.43.0 torna os lembretes financeiros, avisos de vigência e comunicações de trial executáveis de forma nativa pelo RS Connect. O n8n continua disponível como canal externo opcional, mas deixa de ser requisito para regras configuradas como internas ou WhatsApp nativo.

## Migration obrigatória

Execute, na ordem normal do projeto:

```bash
php bin/migrate.php verify
php bin/migrate.php up
```

A versão requer `125_native_financial_lifecycle_notifications.sql`.

Essa migration adiciona os canais nativos à régua de cobrança, a configuração do WhatsApp central da plataforma, as regras de vigência/trial e o vínculo entre a fila nativa e os históricos financeiros.

## Após o deploy

Faça rebuild/redeploy do container. O processo de inicialização passa a manter um worker financeiro interno, controlado por:

```env
RS_FINANCIAL_NOTIFICATION_WORKER_ENABLED=true
RS_FINANCIAL_NOTIFICATION_WORKER_INTERVAL_SECONDS=300
```

O worker não contém prazos ou mensagens comerciais fixos. Ele apenas consulta e executa as regras ativas persistidas no banco.

## Configurar o WhatsApp da plataforma

Abra **Lembretes financeiros e de vigência** e, em **WhatsApp usado pelo RS Connect**, selecione a conexão que será o remetente central dos avisos da plataforma.

Essa conexão é diferente do WhatsApp da empresa cobrada. O destinatário é o `commercial_whatsapp` da empresa cliente, com fallback para o telefone cadastrado.

Se nenhuma conexão central for selecionada, regras `RS Connect (interno)` continuam funcionando; regras de WhatsApp nativo permanecem na fila de tentativas e retomam automaticamente quando a configuração for concluída.

## Atualizar as regras de cobrança existentes

As regras antigas não são convertidas silenciosamente. Edite cada regra ativa e escolha o comportamento desejado:

- **RS Connect (interno):** cria notificação no painel da empresa;
- **WhatsApp nativo da plataforma:** envia pelo número central configurado;
- **RS Connect + WhatsApp nativo:** usa os dois canais;
- **Integração externa (n8n):** mantém o comportamento externo opcional;
- **Somente registrar:** mantém histórico sem envio.

Os dias, status, mensagem, marcação de atraso e eventual suspensão continuam definidos pela própria regra.

## Vigência, trial e tolerância

A migration cria modelos iniciais inativos. Ative somente os que fizerem sentido e edite prazo, canal e mensagem. É possível criar novas regras para:

- fim da vigência (`current_period_end`);
- fim do período de teste (`trial_end`);
- fim da tolerância do trial (`trial_grace_end`).

Os dias negativos significam antes da data, zero no dia e positivos depois.

Placeholders disponíveis:

```text
{{empresa}}
{{plano}}
{{fim_vigencia}}
{{fim_teste}}
{{fim_tolerancia}}
{{dias}}
{{status}}
{{valor}}
```

Para cobrança continuam disponíveis também `{{invoice_number}}`, `{{vencimento}}` e `{{link_pagamento}}`.

## Homologação recomendada

1. Configure uma conexão WhatsApp central conectada.
2. Edite uma regra de cobrança próxima da data de uma fatura de teste e escolha `RS Connect + WhatsApp nativo`.
3. Clique **Processar agora** e confirme o histórico `Enviado` e a mensagem no destinatário da empresa.
4. Ative uma regra de vigência para uma data de teste e repita.
5. Deixe o n8n inativo e execute **Verificar sistema agora**: regras nativas não devem gerar alerta de dependência externa.
6. Para validar o worker, não clique em **Processar agora**; aguarde o próximo ciclo e confira o heartbeat do monitor operacional.

## Rollback operacional

Antes de voltar a uma versão anterior, coloque as regras novas como inativas. A migration 125 adiciona estruturas e valores de ENUM usados pela 36.43.0; não remova colunas/tabelas manualmente em produção sem backup e plano de rollback do banco.
