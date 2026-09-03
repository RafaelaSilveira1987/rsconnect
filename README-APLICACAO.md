# RS Connect — nome e logo da empresa no painel

Este pacote corrige a identificação visual mostrada no cabeçalho lateral do painel do cliente.

## Resultado esperado

- o nome exibido vem automaticamente de `tenants.name`;
- a logo configurada em White Label aparece ao lado do nome da empresa;
- quando não houver logo, o sistema mostra as iniciais da empresa;
- a imagem personalizada não herda o fundo degradê do símbolo padrão;
- cores, textos, favicon, login e demais elementos continuam padronizados pela RS Connect;
- a tela White Label continua permitindo apenas enviar, substituir ou remover a logo.

## Correções de persistência

- o upload é gravado em `storage/app/white-label/tenant-{ID}`;
- o instalador prepara a pasta e ajusta as permissões para o Apache/PHP;
- a atualização no banco usa transação;
- depois do `UPDATE`, o controller relê `white_label_enabled` e `brand_logo_url`;
- se o banco não confirmar exatamente o valor gravado, a operação é revertida e uma mensagem clara é exibida.

## Arquivos alterados

- `app/Controllers/WhiteLabelController.php`
- `app/Services/BrandingService.php`
- `app/Views/white_label/index.php`
- `app/Views/layouts/app.php`
- `tests/Feature/white-label-company-brand-smoke.php`

Nenhuma migration é necessária.

## Aplicação automática

Extraia o pacote e execute:

```bash
cd /caminho/onde/extraiu/rs-connect-whitelabel-company-brand-v2
bash apply-white-label-company-brand.sh /var/www/html
```

O instalador cria um backup em:

```text
/var/www/html/storage/backups/white-label-company-brand-AAAAMMDD-HHMMSS
```

Em seguida, valida a sintaxe e executa 42 verificações específicas.

## Validação após a aplicação

1. Entre como administrador da RS Connect.
2. Abra **White Label**.
3. Selecione a empresa.
4. Envie a logo e clique em **Salvar logo**.
5. Entre com um usuário da empresa ou atualize a sessão desse cliente.
6. O cabeçalho lateral deverá mostrar o nome cadastrado em Empresas e a logo enviada.

Para confirmar pelo servidor:

```bash
find /var/www/html/storage/app/white-label -type f -maxdepth 3 -ls
php /var/www/html/tests/Feature/white-label-company-brand-smoke.php
```

## Observação de infraestrutura

A pasta `/var/www/html/storage` deve estar em volume persistente no EasyPanel para que imagens sobrevivam a uma recriação completa do container. O instalador corrige permissões, mas não cria automaticamente um volume no painel de hospedagem.


## Ajuste visual adicional
- O bloco da logo no menu lateral e na prévia recebeu fundo neutro suave, com borda leve e sombra discreta, para melhorar logos com transparência ou fundo branco.

## Ajuste visual consolidado da logo

- O fundo do quadro da logo respeita a cor hexadecimal configurada no White Label, inclusive contra regras globais com `!important`.
- O quadro da logo do cliente no menu lateral usa 54x54 px, sem padding interno.
- A imagem usa `object-fit: cover` e zoom visual de 1.35 para reduzir margens internas presentes em arquivos de logo.
- A prévia do White Label atualiza a cor do fundo em tempo real e usa o mesmo enquadramento da aplicação.

## Homologação Evolution / WhatsApp E2E

O pacote inclui uma homologação assistida que valida a configuração local e remota da Evolution e acompanha uma mensagem real desde o webhook até a resposta automática.

No host Docker/EasyPanel:

```bash
bash scripts/run-evolution-e2e.sh --instance=gestaodetempo --timeout=180
```

Ou, dentro do container da aplicação:

```bash
php bin/evolution-e2e.php --instance=gestaodetempo --timeout=180
```

Somente pré-teste, sem aguardar mensagem real:

```bash
php bin/evolution-e2e.php --instance=gestaodetempo --no-wait
```

O runner verifica: conexão local e remota, recebimento habilitado, `MESSAGES_UPSERT`, token do webhook, rota pública autenticada, vínculo com agente ativo, chegada da mensagem real, criação/localização da conversa, roteamento para agente, resposta automática, ID devolvido pela Evolution, estado de envio e ausência de resposta duplicada.

As evidências são gravadas em `storage/logs/e2e/evolution-e2e-AAAAMMDD-HHMMSS.json`. Nenhuma API key ou token é exibido no relatório.

## Homologação real de backup + restauração

O projeto inclui `scripts/verify-backup-restore.sh` para provar que o backup oficial pode ser restaurado de verdade sem sobrescrever produção.

O verificador:

1. executa `scripts/rsconnect-backup.sh`;
2. valida `status=success`, `verified=true`, gzip e SHA-256;
3. cria um banco MySQL temporário com prefixo `rsconnect_restore_verify_`;
4. restaura integralmente o `.sql.gz` nesse banco temporário;
5. compara quantidade e lista completa de tabelas;
6. compara contagens de registros de tabelas críticas disponíveis;
7. grava uma evidência JSON no diretório de backup;
8. remove o banco temporário ao finalizar, inclusive em caso de erro.

Uso no host Docker/EasyPanel:

```bash
bash scripts/verify-backup-restore.sh /backups/rs-connect 5 rs_connect
```

A homologação só é aprovada quando a saída termina com:

```text
[APROVADO] Backup gerado e restaurado com sucesso em banco temporário.
```

## Homologação de planos e limites

O pacote inclui um auditor transacional dos planos comerciais e dos bloqueios de capacidade.

Dentro do container da aplicação:

```bash
php bin/plan-limits-audit.php
```

Ou pelo wrapper:

```bash
bash scripts/run-plan-limits-audit.sh
```

O auditor confere a matriz Inicial/Profissional/Empresarial, preços de IA própria e IA RS Connect, compromissos de 3/6/12 meses, limites de usuários/canais/agentes/franquia de IA e os pontos de enforcement do código. Para testar o comportamento no teto e abaixo do teto, altera os limites apenas dentro de uma transação da própria conexão e executa `ROLLBACK` ao final; nenhuma alteração de plano é persistida.

## Homologação multiagentes + round-robin

A partir da migration `099_ai_agent_round_robin_routing.sql`, conversas genéricas podem ser distribuídas em round-robin entre os agentes ativos e elegíveis do mesmo canal.

A ordem operacional é:

1. agente já fixado na conversa;
2. especialista encontrado por `routing_keywords`;
3. round-robin entre os demais agentes elegíveis do canal;
4. fallback compatível para o primeiro agente/principal quando a migration ainda não foi aplicada.

O round-robin usa a tabela `ai_agent_routing_state` e `SELECT ... FOR UPDATE`, evitando que duas conversas concorrentes consumam a mesma posição do cursor. Mensagens posteriores da mesma conversa mantêm o agente já fixado e não avançam a rotação. Especialistas por palavra-chave também não consomem o cursor genérico.

Aplicação da migration:

```bash
php bin/migrate.php
```

Validação estática:

```bash
php tests/Feature/multi-agent-round-robin-v36270-smoke.php
```

Validação real e reversível no banco:

```bash
php bin/multiagent-audit.php
```

O auditor usa um canal existente com pelo menos dois agentes ativos, cria conversas temporárias dentro de uma transação, prova a sequência do round-robin e a continuidade por conversa e executa `ROLLBACK` ao final. Se houver `routing_keywords` configuradas no canal, também comprova que a keyword mantém prioridade sem consumir o cursor genérico.

## RS Connect 36.27.1 — handoff IA→IA por intenção

Conversas já fixadas em um agente podem ser transferidas para outro especialista do mesmo canal quando a nova mensagem casar com `routing_keywords` do destino. A troca usa lock de linha na conversa, atualiza `conversations.ai_agent_id` e não consome o cursor do round-robin. Mensagens genéricas continuam no agente já pinado. O mecanismo é genérico e não depende de nomes de agentes.

Homologação focada:

```bash
php tests/Feature/ai-to-ai-specialist-handoff-v36271-smoke.php
php bin/multiagent-audit.php
```

## RS Connect 36.27.2 — multiagente configurável pela interface

A tela **Assistentes Virtuais** agora permite configurar o roteamento por canal sem acesso ao banco ou terminal.

Papéis disponíveis:

- **Principal / recepção:** recebe o atendimento geral do canal.
- **Especialista por assunto:** recebe somente quando uma intenção/palavra configurada for identificada; a conversa é transferida e permanece com o especialista.
- **Distribuição automática:** participa do round-robin das novas conversas gerais.

Cada card mostra o papel atual e, quando houver especialista, um resumo das intenções configuradas. A criação de um novo assistente também permite escolher o papel inicial.

A partir desta versão, assistentes com `routing_keywords` não participam da distribuição genérica enquanto existir ao menos um agente geral elegível. Isso permite cenários como **Recepção → Comercial** sem que o Comercial receba conversas comuns por sorteio.

Não há migration nova. A migration obrigatória continua sendo `099_ai_agent_round_robin_routing.sql`.

Validação focada:

```bash
php tests/Feature/multi-agent-round-robin-v36270-smoke.php
php tests/Feature/ai-to-ai-specialist-handoff-v36271-smoke.php
php tests/Feature/multi-agent-routing-ui-v36272-smoke.php
php tests/Feature/agent-instance-linking-v36182-smoke.php
```


## RS Connect 36.27.3 — handoff IA→IA identificável

- Mensagens automáticas novas gravam o emissor como `IA - Nome do assistente` quando a base possui `sender_display_name`.
- A conversa e o polling passam a exibir, por exemplo, `IA - Digi` e `IA - Carlos`, em vez do rótulo genérico `IA`.
- Quando o roteamento por intenção troca o pin entre assistentes, o motor registra `ai.routing.handoff` e injeta contexto interno no novo especialista.
- A primeira resposta do especialista recebe orientação para assumir do ponto correto, identificar-se de forma curta e não repetir perguntas já respondidas.
- Regras locais e cache exato são ignorados somente no primeiro turno imediatamente após a troca de IA, garantindo que o novo especialista gere uma resposta contextualizada.
- O prompt obrigatório impede que uma IA afirme que transferiu para outro assistente virtual quando o motor não confirmou a troca.
- Não exige migration nova; usa `sender_display_name` já disponível na base atual.
