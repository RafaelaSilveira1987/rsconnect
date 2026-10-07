# RS Connect 36.42.2 — Antecedência clara e Demanda preservada

Esta atualização é um hotfix sem nova migration. Permanece obrigatória `124_calendar_slot_capacity_mode.sql`.

## 1. Mensagem de antecedência

Antes, o WhatsApp respondia com uma frase técnica como “Esse período está dentro da antecedência mínima...”. Agora o texto informa diretamente que os agendamentos precisam ser feitos com pelo menos **X horas de antecedência** e que o período solicitado não pode ser agendado. Quando disponível, o sistema também informa a partir de qual data/hora pode pesquisar novas opções.

Exemplo esperado para 24 horas:

> Infelizmente, os agendamentos precisam ser feitos com pelo menos 24 horas de antecedência, então não é possível agendar no período solicitado. Posso verificar horários a partir de 08/10 às 12:36.

## 2. Integridade da Demanda

A causa do campo **Demanda** receber “Prefiro na quinta-feira, na parte da manhã” era a coleta genérica de campos personalizados. Quando o cursor ainda estava em um campo livre, uma preferência de agenda podia ser aceita como texto válido.

A versão 36.42.2 adiciona duas proteções:

- campos configurados como **Demanda** validam o conteúdo como resposta de demanda; idade isolada, modalidade, pergunta informativa e preferência de agenda não são aceitas;
- qualquer campo livre não relacionado à agenda rejeita uma preferência isolada de dia/período.

Além disso, sessões que já possuem um valor claramente de agenda salvo em um campo livre são saneadas no próximo processamento. A preferência continua armazenada em `preferred_schedule`, enquanto a Demanda volta a ficar pendente até receber uma resposta adequada.

## 3. Homologação sugerida

1. Inicie uma conversa nova de teste.
2. Responda à pergunta de Demanda com um relato livre, por exemplo: “Estou muito triste depois que perdi uma pessoa.”
3. Responda a idade.
4. Na preferência, informe “quinta-feira pela manhã”.
5. Confirme que o pré-agendamento mostra o relato emocional em **Demanda** e a preferência apenas nos campos de dia/período.
6. Repita com antecedência mínima de 24 horas e solicite um período bloqueado pela regra para validar o novo texto do WhatsApp.
7. Execute `php tests/Feature/calendar-notice-and-demand-integrity-v36422-smoke.php`.

## 4. Arquivos principais alterados

- `app/Services/CalendarConversationService.php`
- `app/Services/AgentTriageService.php`
- `app/Services/AppVersionService.php`
- `manifest.json`
- `README.md`
- `CHANGELOG.md`

## 5. Banco de dados

Nenhuma migration nova. Continue com:

```bash
php bin/migrate.php verify
php bin/migrate.php up
```

A migration obrigatória continua sendo `124_calendar_slot_capacity_mode.sql`.
