# RS Connect — Hotfix de respostas Sim/Não e retomada da Ordem de Atendimento

Data: 20/10/2026

## Sintoma

O contato respondeu `Sim` a "É a sua primeira consulta?", mas o agente repetiu a mesma pergunta. O episódio ocorreu depois de uma mensagem recebida fora do horário de atendimento e de uma nova interação na abertura.

## Diagnóstico no pacote

O `AgentTriageService::captureConfiguredFreeTextValue()` rejeitava respostas de baixo conteúdo como `Sim`/`Não` para campos textuais, corretamente para perguntas abertas. Entretanto, perguntas binárias antigas (incluindo perguntas de primeira consulta) podiam ter `field_type=text`, sem indicação formal de resposta binária. O valor não era persistido em `conversation_triage_sessions.collected_json`; a próxima etapa continuava pendente e era novamente perguntada.

## Alterações

- `app/Services/AgentTriageService.php`: captura binária apenas no campo atualmente em coleta; aceita campo `boolean`, `options.answer_type=boolean|yes_no` ou pergunta histórica em forma fechada (ex.: `É a sua primeira consulta?`). Respostas de campos booleanos são armazenadas como `true`/`false`; formulários históricos de texto guardam `Sim`/`Não`.
- `app/Services/VisualBlueprintEditorService.php`: futuras inclusões guiadas de `first_consultation` são do tipo booleano. Nenhum campo antigo foi convertido no banco.
- `tests/Feature/agent-binary-triage-after-hours-20261020.php`: verifica resposta positiva/negativa, avanço de etapa, continuidade no próximo turno e rejeição de `Sim` para perguntas abertas.

## Instalação

1. Faça backup da aplicação e banco antes de aplicar.
2. Para a versão baseada em `rs-connect-hotfix-whatsapp-lista-agendamentos-completo.zip`, copie somente os arquivos do patch, respeitando os caminhos.
3. Verifique a sintaxe: `php -l app/Services/AgentTriageService.php` e `php -l app/Services/VisualBlueprintEditorService.php`.
4. Reinicie o PHP ou invalide o OPcache se necessário.
5. Em empresa de homologação, **inicie uma conversa nova** para evitar que o estado incorreto anterior confunda o teste. Reproduza o cenário fora do expediente com a fila de retomada habilitada. Faça também o teste simples em horário comercial.
6. Confirme que o próximo campo configurado na Ordem de Atendimento é perguntado uma única vez. Confirme que a agenda só é consultada após todas as coletas obrigatórias.

## Observações

- Não altera agenda, aprovação humana, expediente, fila nem credenciais.
- Não tenta reconstruir automaticamente respostas anteriores já descartadas no banco; a interação deve ser retomada normalmente com a próxima resposta do contato.
- A recuperação completa entre o worker de fora do expediente, MySQL real e WhatsApp precisa ser homologada no ambiente do RS Connect. Os testes locais são unitários/simulados.
- Atenção: perguntas legadas cujo texto começa com uma formulação fechada de Sim/Não são tratadas como binárias apenas quando estão na etapa ativa. Para novos modelos, prefira sempre o tipo `boolean`.
