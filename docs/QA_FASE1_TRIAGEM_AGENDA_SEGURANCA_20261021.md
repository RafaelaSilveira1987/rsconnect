# RS Connect — QA Fase 1 (21/10/2026)

## Base e compatibilidade

- Base de distribuição: `rs-connect-qualidade-testes-automatizados-completo.zip`, release `36.44.4`.
- A versão do pacote e a migration obrigatória **não foram alteradas** (`125_native_financial_lifecycle_notifications.sql`).
- Não há SQL novo, alteração do schema, alterações de confirmação/lotação ou criação/envio de lembretes.
- Patch incremental: aplicar **somente** sobre esta base de distribuição; manter backup de arquivos e banco antes do deploy.

## Alteração de funcionamento (backend)

`app/Services/AgentTriageService.php`:

1. Pergunta aberta de **Demanda** não pode ser inferida como Sim/Não pelo verbo inicial "Tem". O texto integral passa a ser mantido quando passa pela validação de demanda.
2. Perguntas textuais que pedem explicação ("o que", "como", "alguma coisa que" etc.) não se tornam binárias pelo prefixo interrogativo.
3. Se o contato responder idade **aproximada** em contexto de agenda, o assistente pede um valor exato em anos sem repetir mecanicamente a pergunta inicial.
4. Respostas Sim/Não genuínas continuam funcionando; a coleta retoma o primeiro campo que permanece pendente.

## Correção de testes que envelheciam com o calendário

- `calendar-whatsapp-status-grid-20261016.php`, `appointment-new-booking-privacy-20261016.php` e `calendar-daily-grid-completeness-20261015.php` passam a gerar data de teste futura, sem exigir vaga real no banco.
- `calendar-availability-inquiry-v363628-smoke.php` testa as condições atuais de navegação da grade por **data**, em vez de um fragmento literal de versão antiga.
- `calendar-published-lookup-v36391-smoke.php` mantém a regra atual: dias permitidos pela empresa valem também para vagas publicadas. Não volta a permitir horário publicado fora desses dias.
- `calendar-client-automation-v36410-smoke.php` executa a checagem de lembrete elegível com data futura e preserva um teste separado que comprova o bloqueio de envio vencido. O teste ainda reprova por metadados históricos de versão/migration, não por esta regra comportamental.
- `agent-age-opening-identity-v36367r2-smoke.php` não exige a pergunta de modalidade quando a própria configuração operacional a desativa.
- `agent-runtime-response-contract-v363626-smoke.php` e `calendar-notice-and-demand-integrity-v36422-smoke.php` passaram após a correção sem mudança em suas expectativas.

## Segurança: situação confirmada e limites

- Novos testes verificam que login de navegador possui CSRF, que exceções mobile usam controller de autenticação Bearer, que os tokens passam por checagem de expiração/revogação/tenant, que a configuração HTTPS/cookie seguro é preservada e que o verificador de token do webhook rejeita credencial incorreta.
- O teste histórico `security-session-webhook-hardening-smoke.php` **continua falhando** por exigir CSRF em chamadas de API Bearer e literal `SESSION_COOKIE_SECURE=true` no lugar de `auto`. Ele **não foi silenciado**.
- Esta checagem não prova que não existam vulnerabilidades; o próximo passo é testar login mobile, sessão via proxy, CSRF, webhooks e upload sob HTTP real em homologação, inclusive respostas 401/403 e proteção contra repetição.

## Resultados (PHP CLI no ambiente de edição)

| Suíte | Aprovados | Reprovados |
|---|---:|---:|
| Base original `all` | 222 | 68 |
| Após Fase 1 `all` | 232 | 60 |
| Após Fase 1 `critical` | 26 | 0 |
| Após Fase 1 `lint` | 635 | 0 |

A suíte `all` passou de 290 para 292 testes porque há 2 testes novos. **O pacote não está homologado integralmente**: 60 testes históricos/funcionais ainda reprovam. Essas falhas estão preservadas nos relatórios da execução. Não houve execução integrada com MySQL, Evolution ou WhatsApp.

## Comandos após instalação em homologação

```bash
php bin/qa.php --suite=critical
php bin/qa.php --suite=all
php bin/qa.php --suite=lint
```

O primeiro bloqueia a publicação quando algum dos 26 cenários críticos falha. O `all` ainda deve retornar exit code `1` por causa das 60 pendências: isso é esperado nesta fase, **não significa que ele tenha sido aprovado**.

## Cenários reais a validar

1. Ordem do atendimento: Demanda aberta → Idade → Primeira consulta (Sim/Não), com respostas separadas e mensagens em sequência rápida.
2. Recuperação após o expediente: responder "Sim, estou ansiosa desde..." não pode gravar apenas "Sim" em Demanda nem duplicar perguntas.
3. Modalidade única não exige pergunta de escolha; modalidade obrigatória na Ordem do Atendimento continua exigida quando realmente há duas opções.
4. Datas futuras na Agenda: grade mostra apenas horários liberados após antecedência, capacidade, modalidade e conflitos; nunca divulga dados de pacientes.
5. Lembretes: pré-agendamento pendente sem aprovação não envia; aprovado confirmado com data futura pode enviar; job vencido não envia.
6. Segurança: conferir tokens, status HTTP, cookie seguro atrás do proxy, assinatura de webhooks e isolamento entre empresas.

## Próximos lotes sugeridos

- **Fase 2:** validar persistência da triagem por agendamento (snapshot imutável), confirmação dos dados no painel e bloqueio de conflitos/capacidade sob concorrência real.
- **Fase 3:** corrigir contratos operacionais e testes históricos restantes de forma individual; nunca mudar a aplicação para satisfazer um número de versão antigo.
