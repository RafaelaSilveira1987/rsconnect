# Homologação final — RS Connect 36.6.8

## A. Migração e planos
- [ ] Migration 052 executada sem erro.
- [ ] Starter mostra **1.500 Interações de IA/mês** quando antes possuía 1.500 mensagens/mês.
- [ ] Profissional/Business preservam os valores comerciais anteriores de `messages_month` como `ai_interactions_month`.
- [ ] Plano customizado preserva seu limite anterior; `null` continua ilimitado.

## B. Origem/custeio da credencial
- [ ] Em **IA e credenciais**, existe o seletor **Custeio cliente / Custeio RS Connect**.
- [ ] Credencial própria aparece como **Custeio cliente**.
- [ ] Credencial paga pela RS aparece como **Custeio RS**.
- [ ] Assistente sem credencial específica, usando chave global do ambiente, é contabilizado como RS Connect.

## C. O que consome a franquia
Anote o valor inicial em **Assinatura → Uso do plano** antes de cada teste.

- [ ] Contato envia mensagem e a equipe assume sem IA: consumo não aumenta.
- [ ] Atendente humano envia resposta: consumo não aumenta.
- [ ] Mensagem fixa de fora do horário: consumo não aumenta.
- [ ] Mensagem fixa de transferência para humano: consumo não aumenta.
- [ ] IA envia uma resposta automática usando credencial RS: consumo aumenta exatamente em 1.
- [ ] IA envia resposta automática usando chave própria: **Uso com chave do cliente** aumenta em 1 e **Franquia RS** não aumenta.
- [ ] “Gerar sugestão” pode ser auditado internamente, mas não reduz a franquia comercial.

## D. Limites e alertas
Para facilitar, crie temporariamente um plano de teste com limite baixo, por exemplo 5 interações.

- [ ] Ao cruzar 80%, cliente recebe aviso uma única vez no mês.
- [ ] Ao cruzar 95%, cliente recebe aviso uma única vez no mês.
- [ ] Ao atingir 100%, cliente e Super Admin recebem aviso.
- [ ] A última resposta que completa o limite é enviada normalmente.
- [ ] A tentativa seguinte com **Custeio RS** não chama a IA e fica preservada para reprocessamento.
- [ ] Mesmo com franquia esgotada, o contato continua conseguindo enviar mensagens.
- [ ] Mesmo com franquia esgotada, o atendente humano continua conseguindo responder.
- [ ] Com franquia RS esgotada, assistente configurado com **Custeio cliente** continua respondendo.
- [ ] Ao aumentar/renovar o limite, uma pendência `ai.quota.blocked` volta a ser elegível para reprocessamento.

## E. Pós-horário — cenário básico
Configure temporariamente um assistente para encerrar o expediente alguns minutos antes do teste.

- [ ] Fora do horário, envie a mensagem: “Quero remarcar meu horário”.
- [ ] A mensagem fica salva na conversa.
- [ ] A mensagem de ausência é enviada, se configurada.
- [ ] Essa mensagem de ausência não consome franquia.
- [ ] Em **Central de operação → Fila da IA**, aparece 1 pendência pós-horário.
- [ ] Ao entrar no próximo horário válido e executar o Monitor/“Reprocessar pendências agora”, a IA responde à demanda original.
- [ ] A resposta recuperada consome exatamente 1 interação quando usar Custeio RS.

## F. Pós-horário — várias mensagens
Fora do expediente envie, na mesma conversa:
1. “Boa noite”
2. “Preciso remarcar minha consulta”
3. “Segunda não consigo”
4. “Pode ser terça depois das 15h?”

- [ ] Existe apenas **uma pendência** para a conversa.
- [ ] A mensagem de ausência não é repetida quatro vezes.
- [ ] Na abertura, a IA usa o histórico e responde considerando a demanda completa.
- [ ] O ciclo gera no máximo uma resposta automática inicial para a retomada, não quatro respostas por mensagem acumulada.

## G. Proteção do atendimento humano
- [ ] Fora do horário, crie uma pendência.
- [ ] Antes da abertura, coloque a conversa em **Humano**.
- [ ] A recuperação não envia nenhuma resposta da IA.
- [ ] Se um atendente humano responder, a pendência é encerrada como tratada manualmente.
- [ ] Se apenas assumir sem responder, ela permanece preservada e não recebe automação enquanto estiver em Humano/Pausado.

## H. Pós-horário + franquia esgotada
- [ ] Crie uma pendência pós-horário com assistente Custeio RS.
- [ ] Esgote o limite antes da abertura.
- [ ] Na abertura, a pendência muda para **Aguardando franquia**, sem perder a mensagem.
- [ ] Aumente/renove a franquia.
- [ ] Na próxima execução do monitor, a demanda é recuperada e respondida uma única vez.

## I. Monitor operacional
- [ ] Workflow **Monitor operacional RS Connect** está ativo e roda a cada 15 minutos.
- [ ] O card **Recuperação pós-horário** aparece entre as rotinas operacionais.
- [ ] Pendências normais, por franquia ou atendimento humano não são tratadas como indisponibilidade técnica.
- [ ] Erro real de recuperação gera **Atenção** e playbook com atalhos para fila, conversas e assinatura.
