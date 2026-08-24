# RS Connect - v36.18.4

Esta versão melhora a visualização das mensagens recebidas fora do horário de atendimento. A fila deixa de parecer uma conversa comum ou uma falha e passa a informar claramente o que está aguardando, quantas mensagens foram preservadas, quando a automação deve retomar e quando é necessária uma ação humana.

## Principais mudanças

- Destaque visual das conversas que estão na fila fora do horário.
- Contador de mensagens preservadas por conversa.
- Filtro **Aguardando horário** na Caixa de Entrada.
- Indicador rápido da quantidade de conversas nessa fila.
- Painel detalhado ao abrir a conversa, com:
  - situação atual;
  - primeira e última mensagem da janela;
  - confirmação do aviso de ausência;
  - previsão do próximo expediente;
  - motivo de bloqueio ou última falha;
  - ação **Assumir agora**.
- Atualização em tempo real do estado na lista de conversas.
- Central de Operação reformulada com cards responsivos para cada pendência fora do horário.
- Link da Central de Operação abre a conversa já no escopo correto da empresa.

## Atualização

- Não exige migration nova.
- As migrations anteriores até `079_ai_efficiency_phase2_and_report_cleanup.sql` continuam obrigatórias.
- Preserve o `.env` atual.
- Faça rebuild/redeploy completo.
- Depois use `Ctrl + F5` ou abra em janela anônima.

## Validação sugerida

1. Configure um assistente para responder apenas dentro do horário.
2. Envie duas mensagens fora do expediente.
3. Abra **Conversas** e confirme o card **Aguardando horário**.
4. Abra a conversa e confira quantidade, aviso de ausência e previsão de retomada.
5. Teste **Assumir agora** para atendimento manual antecipado.
6. Acesse **Central de Operação → Fila da IA → Mensagens fora do horário**.

## Ajuste v36.18.4 — fila fora do horário em atendimento humano

- Mensagens recebidas fora do expediente agora entram na fila visual mesmo quando a conversa está em modo humano ou com a IA pausada.
- O card da conversa, o filtro da Caixa de Entrada e a Central de Operação mostram o estado **Aguardando equipe**.
- A fila humana não dispara resposta automática; ela preserva, conta e destaca as mensagens até a equipe responder.
- Não há migration nova.
