# RS Connect 36.27.26 — Agenda assertiva

Esta versão fecha o fluxo de agenda para que nenhuma resposta textual da IA seja considerada agendamento sem existir estado técnico correspondente no RS Connect.

## Fluxo canônico

1. O RS Connect identifica a intenção de agendamento.
2. Coleta somente os dados que faltarem: modalidade (online/presencial), dia/data e horário/período.
3. A preferência fica como `pre_scheduled`, sem ocupar o calendário.
4. A agenda selecionada nas configurações valida a preferência real.
5. Na Agenda interna, o horário exato solicitado é validado primeiro, mesmo que não coincida com a grade usada para sugestões.
6. Se estiver livre e respeitar dias/horários, duração, antecedência, buffers, profissional e conflitos, o RS Connect seleciona um slot real e cria a pré-reserva.
7. A partir daí, o comportamento depende do modo de confirmação configurado.

## Modos de confirmação

### Pré-agendar e aguardar aprovação da equipe (`human`)

O slot real fica reservado e o cliente recebe a mensagem de pré-reserva. O status definitivo só muda após aprovação humana.

### Perguntar ao cliente e confirmar automaticamente (`automatic`)

Depois de reservar um slot real, o RS Connect pergunta se o cliente deseja confirmar. Uma resposta afirmativa é interceptada antes da IA, o horário é revalidado e somente depois de persistir `status = confirmed` é enviada a mensagem de confirmação.

### Somente pré-agendar (`pre_schedule`)

O horário real é pré-reservado, mas a confirmação definitiva é feita posteriormente pela equipe.

A aprovação humana tem precedência sobre confirmação automática; as duas opções não podem ficar efetivamente ativas ao mesmo tempo.

## Horário "livre"

"Livre" significa: não há compromisso real ocupando aquele horário. O agendamento ainda precisa respeitar as regras configuradas, incluindo:

- dias habilitados;
- início/fim do expediente;
- duração do atendimento;
- intervalo/buffer;
- antecedência mínima;
- agenda individual do profissional, quando habilitada;
- conflitos com compromissos confirmados/agendados;
- pré-reservas reais ainda não expiradas;
- regra de conflito do mesmo contato.

Uma preferência sem slot selecionado não bloqueia a agenda e não aparece como compromisso real no calendário.

## Sugestões de horários

A opção **Sugerir horários alternativos** controla somente a apresentação de alternativas. Mesmo desativada, o horário exato pedido pelo cliente continua sendo validado. Se estiver livre, pode ser pré-reservado/confirmado conforme o modo escolhido. Se estiver indisponível e alternativas estiverem desativadas, o sistema pede outra preferência sem inventar opções.

## Proteção contra confirmação falsa

Respostas livres do Prompt Studio, cache ou IA são verificadas antes do envio. Frases que afirmem disponibilidade, pré-reserva ou confirmação só podem sair quando o estado da agenda no banco comprovar a afirmação.

Em particular, a frase de confirmação definitiva só é permitida após a persistência de um compromisso `confirmed`.

## Hold de pré-reserva

Quando um slot é selecionado, ele recebe validade conforme `hold_minutes`. Enquanto válido, bloqueia aquele horário para outras reservas. Após expirar, deixa de bloquear. Caso o cliente tente confirmar depois do vencimento, o horário é revalidado antes de qualquer confirmação.

## Deploy

1. Faça backup da aplicação e do banco.
2. Substitua os arquivos pelo pacote 36.27.26.
3. Execute as migrations pendentes:

```bash
php bin/migrate.php up
```

A versão 36.27.26 não adiciona migration nova além da já exigida pela v9 (`102_agenda_prompt_or_form_messages.sql`).

4. Reinicie PHP-FPM/container para limpar OPcache.
5. Em **Configurações da empresa → Agenda → Pré-agendamento e mensagens**, revise:
   - fonte da agenda;
   - modo de confirmação;
   - sugerir horários alternativos;
   - duração, antecedência e buffers;
   - Prompt Studio ou Formulário.

## Teste de homologação recomendado

Use um horário que esteja dentro do expediente e sem conflitos:

1. `Quero agendar.`
2. Informe modalidade, caso solicitado.
3. `Quinta às 16h.`
4. Verifique que um slot real foi pré-reservado.
5. No modo automático, responda `Sim, pode confirmar.`
6. Só aprove o teste se a mensagem de confirmação corresponder a um registro `confirmed` no calendário na mesma data/hora.

Teste também um horário ocupado: o RS Connect deve recusar aquele horário e, se **Sugerir horários alternativos** estiver ativo, oferecer apenas slots reais retornados pela agenda.
