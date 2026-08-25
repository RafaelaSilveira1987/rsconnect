# Roteiro de homologação

## Evolution

- Criar instância.
- Gerar e ler QR Code.
- Confirmar conexão, reinício e sincronização.
- Receber e enviar texto, imagem, áudio e documento.
- Validar grupos, status, listas, newsletters e chamadas.

## Assistente

- Confirmar canal vinculado.
- Testar resposta dentro do horário.
- Testar fora do horário.
- Testar transferência humana.
- Confirmar que a IA não responde durante takeover.

## Atendimento humano

- Atendente A assume.
- Atendente B tenta responder e deve ser bloqueado.
- A transfere para B.
- A perde capacidade de envio e B passa a responder.
- B libera; conversa fica disponível para a equipe.
- Devolução para IA exige ação explícita.

## Fila fora do horário

- Enviar mensagens em modo IA, humano e pausado.
- Confirmar contador e banner.
- Assumir e verificar retirada imediata da fila.
- Confirmar que o worker não reabre a pendência.

## OpenAI e relatórios

- Conferir consumo oficial e interno.
- Validar respostas locais e cache.
- Gerar PDF e executar relatório agendado.

## OpenAI 2.0

- Configurar Admin API Key e atualizar o painel oficial.
- Confirmar custo, tokens, chamadas e modelos no mesmo período do painel da OpenAI.
- Aplicar filtros por empresa e assistente na telemetria interna.
- Conferir respostas locais, cache, chamadas evitadas e tokens evitados.
- Validar projeção e alerta usando um orçamento de teste.
- Conferir se a comparação oficial x RS Connect respeita o escopo de `OPENAI_USAGE_PROJECT_IDS`.

## Memória progressiva

- Ativar a memória em um assistente de teste.
- Enviar mensagens suficientes para ultrapassar o intervalo de atualização.
- Confirmar que a resposta ao cliente ocorre antes da atualização da memória.
- Conferir `Memória da IA` na conversa.
- Verificar que resumo e fatos não inventam informações ausentes.
- Corrigir um fato na conversa e confirmar que a próxima atualização substitui o fato superado.
- Confirmar que a telemetria registra `usage_type = summary` sem reduzir uma nova interação comercial da franquia.
- Desativar a memória no assistente e confirmar fallback para contexto recente.


## Teste de linguagem simples — v36.20.1

Convide uma pessoa que não participou do desenvolvimento e peça que execute tarefas comuns sem explicação prévia. Registre qualquer palavra que ela não entenda.

Critérios:

- entende o título e a finalidade da página;
- sabe qual empresa e período estão selecionados;
- entende os valores e percentuais;
- identifica o botão correto;
- compreende a mensagem de erro e a próxima ação;
- não precisa conhecer termos como webhook, tenant, telemetria, snapshot ou MRR.
