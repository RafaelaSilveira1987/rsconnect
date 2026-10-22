# RS Connect — QA Fase 2: triagem vinculada ao agendamento

**Base:** 36.44.4 (QA Fase 1, verificada pelo cliente em 26/26 e 232/292).  
**Entrega:** 36.44.5.  
**Migration nova obrigatória:** `126_appointment_triage_snapshot.sql` (sequência 133 no manifesto).  
**Ambiente:** testar exclusivamente em homologação antes de publicar.

## Defeito identificado

A tela de Agenda consultava `conversation_triage_sessions.collected_json` e `conversation_flow_states.demand_summary` da **conversa atual** para renderizar dados de agendamentos passados. Uma nova interação do mesmo contato podia, portanto, alterar o que aparecia num compromisso antigo sem que ele tivesse sido editado.

## Comportamento implementado

1. Nova tabela `calendar_appointment_triage_snapshots`: um registro por `tenant_id + appointment_id`, com respostas validadas, rótulos dos campos, campos incompatíveis, demanda e grupo do contato.
2. A criação e a atualização do pré-agendamento tentam registrar o contexto vigente. Enquanto a reserva ainda está pendente, o painel pode exibir dados provisórios da conversa, claramente identificados como tais.
3. Na **confirmação manual** e na **confirmação automática**, o sistema exige que o snapshot seja capturado para pré-agendamentos com conversa vinculada. A confirmação não é autorizada quando o contexto exigido não pode ser preservado (por exemplo, sem migration 126).
4. Ao confirmar, o snapshot recebe `locked_at`; a lógica SQL de atualização impede alterações posteriores. Nova conversa/triagem do contato não modifica o histórico já confirmado.
5. A Agenda lê snapshots com filtro explícito de tenant. Agendamentos antigos, que não possuam snapshot, **não recebem uma cópia inventada** das respostas atuais: o cartão explica que o histórico daquela triagem não está disponível. Nenhum registro confirmado anterior é reescrito automaticamente.
6. Pré-agendamentos manuais sem `conversation_id` continuam podendo ser confirmados; não existe triagem conversacional para congelar nesses casos.
7. A regra operacional de capacidade e o mecanismo de lembrete **não foram alterados nesta fase**. A prova integrada de concorrência ainda é uma pendência separada.

## Instalação em homologação

1. Faça backup do banco de homologação e do projeto.
2. Aplique **um** pacote: o patch incremental sobre 36.44.4 com Fase 1, **ou** o pacote completo.
3. Recrie/republique o contêiner usando a origem de deploy efetiva (repositório ou imagem), sem substituir `.env` nem volumes persistentes.
4. Execute na pasta da aplicação:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
php bin/qa.php --suite=critical
php bin/qa.php --suite=all
```

A migration adiciona tabela; não contém UPDATE dos agendamentos existentes nem preenche históricos retroativamente. O comando `up` altera o **schema**, portanto requer backup e aprovação para homologação.

**Resultado local (PHP CLI, sem MySQL/Evolution real):** suíte crítica 28/28; suíte completa 234/294 (60 falhas históricas/investigação permanecem); migrações validadas via parser offline. Esses números servem de referência, não de autorização para publicar em produção.

## Cenário de homologação com WhatsApp

- Crie uma conversa de TESTE com perguntas configuradas (ex.: primeira consulta, demanda e preferência de horário) e use um contato fictício.
- Gere um pré-agendamento. Enquanto pendente, conclua uma pergunta de triagem; o painel deve refletir a resposta provisória correta, sem mudar os campos de outras reservas.
- Confirme esse agendamento com aprovação humana; confira no banco que a linha correspondente em `calendar_appointment_triage_snapshots` recebeu `locked_at`.
- Volte ao mesmo contato e faça nova triagem com respostas diferentes. O agendamento confirmado deve continuar mostrando os dados da primeira confirmação, com os rótulos vigentes na ocasião.
- Crie um segundo agendamento do mesmo contato; ele deve guardar contexto independente.
- Reabra um compromisso confirmado antes desta migration e sem snapshot. Ele deve indicar ausência de histórico da triagem, sem copiar respostas da conversa mais recente.

## Integração real de banco (opcional, SOMENTE homologação)

O executor possui `tests/Integration/qa-appointment-triage-snapshot-db.php` e só roda com:

```bash
QA_TARGET=homologation QA_ALLOW_DB=1 php bin/qa.php --suite=integration
```

A conexão deve ser de homologação (`APP_ENV` e nome do banco não podem indicar produção). É necessário um pré-agendamento de **teste** com a expressão `QA_FIXTURE` no título, vinculado a uma triagem existente. O teste usa transação e `ROLLBACK`; não envia WhatsApp nem confirma reservas reais. Sem fixture, retorna falha informativa — não forjar aprovação.

## Testes e limitações

Os testes rápidos incluem verificações **comportamentais** de seleção dos dados para a interface e **contratos estáticos** para os caminhos de confirmação, migração e isolamento. Ainda falta executar em MySQL real e confirmar no WhatsApp/Evolution após deploy. Não declarar a Fase 2 homologada até esses passos serem concluídos.

## Reversão

Em incidente durante homologação: interrompa o deploy, restaure o código 36.44.4 e mantenha backup do banco. A tabela nova pode permanecer sem causar impacto ao código anterior; não faça `DROP TABLE` automaticamente — há histórico útil. Se houver necessidade de restaurar o banco, utilize o backup verificado e um procedimento de manutenção controlado.
