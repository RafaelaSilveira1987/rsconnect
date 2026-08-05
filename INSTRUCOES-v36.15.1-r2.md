# RS Connect v36.15.1-r2 — Proteção para Meta AI, LID e destinatários inválidos

## O que foi corrigido

- contatos da Meta AI não entram na fila de respostas automáticas;
- um `remoteJid` terminado em `@lid` só é convertido em telefone quando a Evolution fornece um JID alternativo válido;
- a busca considera `remoteJidAlt`, `senderPn`, `participantPn` e campos equivalentes aninhados;
- LID sem telefone resolvido, grupos, status, canais e contatos de sistema são ignorados antes da criação da conversa;
- conversas históricas salvas apenas com `@lid` são encerradas como não respondíveis na próxima avaliação;
- retorno da Evolution com `exists:false` gera `ai.recipient.unavailable` e não volta para a fila a cada minuto;
- falhas temporárias, como timeout ou indisponibilidade, continuam elegíveis para nova tentativa.

## Banco de dados

Não existe migration nova. A última migration obrigatória continua sendo:

```text
075_scheduled_reports_and_deliveries.sql
```

O arquivo `database/diagnostics/meta_ai_lid_protection_v36.15.1-r2.sql` é somente leitura.

## Instalação pelo patch

Aplique o conteúdo do ZIP de patch na raiz da branch `feature/relatorios-automaticos`, preservando o `.env`.

```powershell
git switch feature/relatorios-automaticos
git pull origin feature/relatorios-automaticos

$zip = Get-ChildItem "$env:USERPROFILE\Downloads\rs-connect-v36.15.1-r2*patch.zip" |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1

Expand-Archive -LiteralPath $zip.FullName -DestinationPath (Get-Location).Path -Force
```

Confirme:

```powershell
Select-String -Path app\Services\AppVersionService.php -Pattern "PACKAGE_LABEL|REQUIRED_MIGRATION"
```

Resultado esperado:

```text
RS Connect 36.15.1-r2 — Proteção para Meta AI, LID e destinatários inválidos
075_scheduled_reports_and_deliveries.sql
```

Depois:

```powershell
git add .
git commit -m "fix: proteger fila de IA contra Meta AI e identificadores LID"
git push origin feature/relatorios-automaticos
```

Faça Deploy/Rebuild no EasyPanel.

## Homologação

1. Envie uma mensagem comum para a instância e confirme resposta normal da IA.
2. Um payload `@lid` com `senderPn` deve usar o telefone real.
3. Um `@lid` sem telefone deve retornar HTTP 202 com `ignored: lid_without_phone`.
4. Meta AI deve retornar HTTP 202 com `ignored: system_contact` quando identificada pelo nome.
5. Uma falha Evolution `exists:false` deve aparecer uma única vez como `ai.recipient.unavailable`.
6. A execução seguinte da fila deve mostrar `errors: 0` para essa pendência.

## Segurança

Como o token da Evolution apareceu em logs de diagnóstico, gere um token novo, atualize o EasyPanel e reaplique os webhooks de todas as instâncias.
