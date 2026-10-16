# RS Connect — Hotfix de divergência entre Compromissos e Disponibilidades

Data: 15/10/2026. Base: `rs-connect-hotfix-agenda-remover-horarios-completo.zip`.

## Ocorrência

A tela de compromissos mostra um atendimento confirmado às 10:00–10:50, mas Disponibilidades mostra o horário publicado como `0/4 ocupadas`. O calendário lê `calendar_appointments`, enquanto o resumo de vagas lê exclusivamente `calendar_internal_slot_allocations`. Compromissos registrados pelo painel ou fluxos legados podem não ter alocação para o horário publicado.

## Alteração conservadora

- `PublishedSlotAppointmentConflictService` cruza compromissos `scheduled`/`confirmed` por empresa, intervalo de tempo e profissional. Não conta novamente os compromissos já alocados *no mesmo slot*. Em caso de falha de leitura retorna `null` e **não pressupõe disponibilidade**.
- O controller de Disponibilidades anexa o resultado à projeção visual.
- A projeção passa a apresentar o estado `Conflito com agendamento`, com o compromisso sobreposto contabilizado na ocupação indicada **apenas para exibição**, e zero vagas ofertáveis enquanto existir vínculo faltante. Exemplo: `1/4 ocupada(s) · 0 ofertáveis`.
- A ação **Remover** e a reserva por capacidade rejeitam esse horário enquanto o conflito persistir (e rejeitam quando não conseguem validar).
- Não modifica compromissos, reservas, capacidade, tabelas, `tenant_id`, status do WhatsApp ou Google Calendar.

### Por que a grade não oferece automaticamente as outras três vagas?

É necessário saber se o compromisso manual pertence à turma publicada ou a outro atendimento sobreposto. Vincular automaticamente só pelo horário pode causar dupla ocupação ou colocar pessoas em turmas erradas. Portanto, até uma **reconciliação autorizada**, o horário com vínculo ambíguo é sinalizado e não ofertado. Compromissos corretamente vinculados continuam em `1/4`, `2/4` com vagas restantes disponíveis.

## Homologação recomendada

1. Fazer backup do banco e do arquivo `app/Services/InternalCalendarSlotService.php`.
2. Instalar o patch sobre a última versão que já recebeu o hotfix do botão **Remover**.
3. Conferir `php -l` nos arquivos PHP atualizados.
4. Empresa Services Ltda: abrir 17/10/2026, verificar compromisso de 10:00–10:50 na aba Compromissos, abrir Disponibilidades e confirmar que o mesmo horário está marcado como **Conflito com agendamento**, não `0/4 livres`.
5. Testar que 08:00 sem compromissos mantém vagas; pré-reserva corretamente alocada continua consumindo uma única vaga.
6. Confirmar que remoção e seleção da vaga em conflito são recusadas; cancelar compromisso apenas por sua operação autorizada, nunca para corrigir a contagem.
7. Conferir logs de aplicação se todas as vagas forem mostradas como `Verificar situação` (falha ao consultar a tabela).

**Limitação:** não foi executado teste transacional real com MySQL; os testes locais de projeção e integração estrutural não equivalem à homologação em banco. A próxima etapa deve incluir reconciliação explícita de vínculos de compromissos manuais e testes com dois clientes simultâneos.
