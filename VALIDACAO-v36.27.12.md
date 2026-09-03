# RS Connect 36.27.12 — Monitor operacional auto-reativável

## Objetivo

Consolidar as correções de métricas reais/comerciais da 36.27.11 e garantir que o workflow crítico **RS Connect - Monitor operacional** não permaneça despublicado após o deploy.

## O que muda

- mantém a fonte canônica de empresas ativas/inativas;
- mantém bloqueio comercial usando `AccessControlService`;
- mantém métricas live via API pública do n8n;
- localiza diretamente no n8n o workflow `RS Connect - Monitor operacional`;
- se ele estiver inativo/despublicado, o instalador chama `POST /api/v1/workflows/{id}/activate`;
- confirma novamente o estado do workflow após a ativação;
- exige a presença de Schedule Trigger/Cron/Interval;
- consulta a última execução quando a API key também possui permissão de execução;
- o check `n8n` da Central passa a denunciar especificamente quando o Monitor operacional estiver inativo ou sem gatilho.

## Pré-requisito

A API key do n8n precisa possuir pelo menos permissões para listar/ler e ativar workflows:

```env
N8N_BASE_URL=https://SEU-N8N
N8N_API_KEY=SUA_CHAVE
```

## Aplicação

```bash
unzip rs-connect-v36.27.12-monitor-operacional-autoreativacao-hotfix.zip
cd rs-connect-v36.27.12-monitor-operacional-autoreativacao
bash apply-monitoring-metrics-v362712.sh /var/www/html
```

A aplicação **não aprova** se o workflow continuar inativo ou se não houver gatilho automático reconhecido. Em caso de falha nos arquivos/aplicação, o instalador restaura os arquivos anteriores automaticamente.

## Validação imediata

```bash
cd /var/www/html
php bin/ensure-operations-monitor-workflow.php
php bin/monitoring-source-audit.php --require-n8n-live --require-monitor-active
```

Resultado esperado:

```text
[INFO] Publicado/ativo: SIM
[INFO] Gatilho de agenda/cron: SIM
APROVADO - Monitor operacional publicado e com gatilho automático.
```

## Validação do disparo

A publicação não força uma execução manual do Schedule Trigger. Ela registra novamente o workflow para execução automática; portanto, aguarde o próximo intervalo configurado no próprio n8n (historicamente 15 minutos no template do RS Connect) e execute:

```bash
php bin/ensure-operations-monitor-workflow.php
```

Se a chave possuir `execution:list`, o comando também exibirá a data/status da última execução.
