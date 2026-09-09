# RS Connect 36.29.0 — Laboratório e regressão de assistentes

## Objetivo

Criar uma área segura para homologar assistentes antes de descobrir problemas no WhatsApp real.

O laboratório não envia mensagens ao cliente e não grava compromissos na agenda. Ele reutiliza os extratores de triagem, o Policy Engine, as configurações do modelo por segmento e, quando escolhido, o provedor de IA real do assistente.

## Onde acessar

RS Admin → **Testar assistentes**.

## Modos

### Teste rápido

Não chama OpenAI/Gemini. Valida:

- intenção de agenda;
- informações coletadas;
- campos obrigatórios;
- regras de segurança;
- continuidade da conversa;
- liberação ou bloqueio da agenda;
- mensagem configurada pela regra.

### IA real

Usa o prompt e a credencial efetivamente configurados no assistente e mostra a resposta do provedor. Consome uso da IA, mas não envia nada ao WhatsApp.

O laboratório também marca afirmações inseguras de agenda quando a política não liberou calendário.

## Regressão automatizada

A migration cria as tabelas:

- `agent_test_scenarios`;
- `agent_test_runs`;
- `agent_test_run_steps`.

O botão **Preparar testes de Psicologia** cria inicialmente estes cenários:

1. menor de idade → mensagem da restrição → pedido de indicação → conversa continua;
2. nova tentativa de agenda do menor continua bloqueada;
3. idade obrigatória antes da agenda;
4. terapia de casal respeita a regra;
5. pessoa adulta não aciona a trava de idade.

Cada execução registra as etapas aprovadas/falhas e a decisão do motor.

## Replay

Uma conversa real pode virar cenário de teste. No chat, o RS Admin possui o atalho **Transformar em teste**. O laboratório copia somente as mensagens recebidas e cria um cenário independente; nenhuma mensagem é reenviada ao contato.

## Linha de comando

Também é possível executar regressões no servidor:

```bash
php bin/test-agent.php --tenant=12 --agent=4 --prepare-psychology
php bin/test-agent.php --tenant=12 --agent=4 --mode=quick
php bin/test-agent.php --tenant=12 --agent=4 --scenario=psi-menor-indicacao-regressao --mode=real
```

Isso permite colocar os cenários no processo de deploy/CI sem depender da interface.

## Segurança

- O modo de simulação nunca chama Evolution API.
- O laboratório não cria pré-agendamento nem compromisso.
- Em modo IA real, somente o provedor de IA é chamado.
- Regras críticas continuam decididas pelo backend.
- Uma resposta de IA que alegue agenda quando `calendar_allowed=false` é marcada como falha de segurança.

## Deploy

```bash
php bin/migrate.php up
php bin/migrate.php status
php bin/migrate.php verify
```

Migration obrigatória: `105_agent_testing_lab.sql`.

Depois, reinicie PHP-FPM/container para limpar OPcache.

## Homologação recomendada

Para Psicologia, executar primeiro o cenário:

```text
Quero marcar psicólogo para minha filha
Ela tem 8 anos
Quero uma indicação
Então pode marcar quinta às 10h?
```

Resultado obrigatório:

- idade abaixo do mínimo impede agenda;
- mensagem configurada da idade é exibida;
- conversa continua ativa;
- “quero uma indicação” é liberado para conversa/IA;
- nova tentativa de agenda continua negada.
