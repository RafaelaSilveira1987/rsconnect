# RS Connect — Homologação final v36.6.3

## Objetivo desta versão

Validar uma nova leitura operacional paralela à Central de operação existente, sem substituir a ferramenta técnica atual.

## Princípio de confiança

O Painel operacional não considera ausência de erro como sucesso. Um item somente recebe estado **Operando** quando existe evidência positiva ainda dentro da janela de validade definida para aquele serviço.

Estados utilizados:

- **Operando** — evidência positiva recente.
- **Atenção** — degradação ou atraso confirmado.
- **Crítico** — indisponibilidade confirmada com impacto atual.
- **Bloqueio externo** — RS Connect preserva a operação, mas depende de reconexão/serviço externo.
- **Sem evidência** — não é possível comprovar o funcionamento atual.
- **Não configurado** — recurso não utilizado pela empresa.

## O que validar após o deploy

1. Abrir **Operação RS → Painel operacional** antes de executar uma nova verificação. O sistema não deve exibir falso “tudo certo” se as evidências estiverem ausentes ou antigas.
2. Clicar em **Verificar sistema agora**. A tela deve voltar indicando verificação completa quando todos os checks forem registrados no mesmo ciclo.
3. Confirmar que a Mariana aparece como **Bloqueio externo / Aguardando conexão** enquanto a Evolution estiver desconectada e com mensagens preservadas.
4. Confirmar que a Fila da IA não aparece como falha interna quando todas as pendências estiverem apenas bloqueadas por Evolution desconectada.
5. Conferir **Saúde dos serviços**: estado, evidência e horário devem ser coerentes com a Central técnica.
6. Conferir **Rotinas automáticas**: cobrança, Fila da IA, backup e relatórios devem mostrar última execução e próxima expectativa.
7. Conferir **Saúde por empresa**: configuração sem uso recente deve aparecer como “Sem evidência”, e não como “Operando”.
8. Manter a **Central de operação** antiga disponível para comparação e investigação técnica durante toda a homologação.

## Pendências anteriores preservadas

- Reprocessamento completo das mensagens da Mariana: aguarda reconexão externa.
- Cobrança: cron executado; o teste ponta a ponta de envio continua pendente.
- Revisão mobile final continua antes do RC1.

Nenhuma migration nova é necessária para a v36.6.3.
