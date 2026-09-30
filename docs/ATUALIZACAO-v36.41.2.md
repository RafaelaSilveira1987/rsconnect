# Atualização RS Connect 36.41.2

## Objetivo

Fechar a validação do pré-agendamento diretamente na aba **Pré-agendamentos**.

## Correção

Após escolher um horário, a tela mostrava o estado **Horário escolhido**, mas não oferecia a ação para transformar aquele pedido em um agendamento confirmado. O backend já possuía o fluxo de aprovação; faltava expor essa ação na tela em que a equipe faz a validação.

Agora a aba exibe **Confirmar agendamento** quando o pré-agendamento possui os dados necessários. A ação usa `/calendar/status` com `status=confirmed`, portanto reaproveita todas as validações existentes:

- revalidação da vaga escolhida;
- confirmação da vaga publicada da Agenda interna ou do evento Google quando aplicável;
- alteração de `approval_status` para `approved`;
- conversão de `is_pre_schedule` para compromisso normal;
- disparo da comunicação de confirmação configurada para o cliente;
- programação dos lembretes automáticos.

Se ainda faltar horário válido, preferência ou profissional obrigatório, o botão permanece desabilitado com a orientação correspondente.

## Banco de dados

Não há migration nova. Permanece obrigatória:

`123_published_slots_min_notice_policy.sql`

## Homologação

1. Crie um pré-agendamento e faça a busca de disponibilidade.
2. Clique em **Usar este horário**.
3. Confirme que o card passa a exibir **Confirmar agendamento** habilitado.
4. Clique em **Confirmar agendamento**.
5. Verifique que o item deixa a lista de pré-agendamentos pendentes e aparece na Agenda como confirmado.
6. Se a confirmação automática ao cliente estiver habilitada, valide o envio ao contato vinculado.
