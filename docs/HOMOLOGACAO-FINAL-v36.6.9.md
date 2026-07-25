# Homologação final — RS Connect 36.6.9

## A. Pré-requisitos
- [ ] Aplicação mostra pacote 36.6.9.
- [ ] Migration 052 já está aplicada.
- [ ] `AI_REPROCESS_CRON_TOKEN` está configurado no ambiente.
- [ ] Template n8n **Fila rápida da IA** foi baixado novamente pelo RS Connect.
- [ ] Workflow está ativo e executa a cada 1 minuto.

## B. Localização dos recursos da 36.6.8
- [ ] Super Admin vê consumo em **Planos e cobrança → Ver uso e histórico**.
- [ ] Cliente vê **Assinatura e uso** no menu e o bloco **Uso do plano**.
- [ ] **IA e credenciais** mostra Custeio RS × Cliente.
- [ ] **Central de operação → Fila da IA** mostra **Recuperação pós-horário**.
- [ ] Central da fila exibe atalhos para franquia, credenciais, pós-horário e alertas.

## C. Intervalo mínimo
Configure um assistente com **60 segundos**.

1. Deixe a conversa em IA.
2. Faça a IA enviar uma resposta e anote o horário.
3. Cerca de 10–20 segundos depois, envie outra mensagem pelo WhatsApp.

Esperado:
- [ ] mensagem recebida aparece imediatamente no RS Connect;
- [ ] IA não responde imediatamente;
- [ ] registro fica elegível como `ai.cooldown`;
- [ ] nenhuma resposta automática sai antes de completar os 60 segundos desde a última resposta da IA;
- [ ] após completar o intervalo e a próxima passagem da fila de 1 minuto, a IA responde sem ação manual;
- [ ] apenas uma resposta é enviada.

## D. Várias mensagens durante o intervalo
Durante os 60 segundos, envie três mensagens consecutivas.

- [ ] todas ficam armazenadas;
- [ ] não há três respostas automáticas;
- [ ] após o intervalo, a IA considera o conjunto das mensagens e gera uma única continuidade coerente;
- [ ] mensagens antigas deixam de aparecer como pendentes depois da resposta.

## E. Takeover durante a espera
- [ ] gere uma pendência de cooldown;
- [ ] antes do intervalo terminar, assuma a conversa como **Humano**;
- [ ] depois do prazo, a fila rápida não envia IA;
- [ ] devolvendo posteriormente para IA, o reprocessamento segue as regras atuais sem duplicidade.

## F. Conversas — envio humano sem salto de tela
Abra uma conversa longa e role até o campo de digitação.

- [ ] digite e envie uma mensagem;
- [ ] a página não volta ao topo;
- [ ] o textarea é limpo;
- [ ] o cursor permanece no campo de digitação;
- [ ] o histórico continua na mensagem mais recente;
- [ ] o badge da conversa muda para **Humano** sem precisar recarregar a página;
- [ ] é possível enviar uma segunda mensagem em sequência sem reposicionar a tela.

## G. Franquia de IA
Com uma empresa de teste:
- [ ] resposta humana não altera consumo;
- [ ] resposta automática com Custeio RS aumenta exatamente 1;
- [ ] resposta automática com Custeio cliente não reduz franquia RS;
- [ ] avisos 80/95/100 continuam deduplicados.

## H. Pós-horário
- [ ] mensagem fora do expediente cria uma pendência única por conversa;
- [ ] várias mensagens são agrupadas;
- [ ] na abertura, a fila rápida/monitor recupera a demanda;
- [ ] Humano/Pausado continua impedindo recuperação automática;
- [ ] franquia esgotada mantém a pendência em **Aguardando franquia**.
