# RS Connect v36.7.0 — Atendimento opcional por profissional

Esta versão inicia a primeira etapa do atendimento individual por profissional sem alterar o comportamento das empresas que não usam o recurso.

## Regra principal

O recurso nasce desativado por empresa.

Quando ativado, uma conversa ativa pode ter um responsável. Se o bloqueio estiver ligado, somente esse responsável pode responder e alterar o atendimento. O administrador pode transferir ou liberar a conversa.

Ao encerrar a conversa, o responsável atual é removido e o contato volta a ficar disponível para um novo atendimento.

## Atribuição automática

A atribuição automática é uma opção separada e permanece desativada por padrão.

Com ela desligada:

1. o contato pode continuar tendo um profissional preferido cadastrado;
2. a preferência aparece na tela apenas como referência;
3. a conversa recebida fica sem responsável;
4. alguém precisa clicar em **Assumir atendimento**, ser definido manualmente por um administrador ou enviar a primeira resposta;
5. o primeiro profissional que assumir passa a ter exclusividade enquanto a conversa estiver ativa.

Com ela ligada, uma nova mensagem pode atribuir a conversa ao profissional preferido do contato, desde que ele esteja ativo e a conversa ainda não tenha responsável.

## O que foi incluído nesta etapa

- ativação opcional por empresa;
- bloqueio opcional contra interferência de outro usuário;
- atribuição automática independente e desligada por padrão;
- profissional preferido no cadastro do contato;
- responsável atual da conversa;
- ações de assumir, atribuir, transferir e liberar;
- bloqueio real no backend, não apenas na interface;
- liberação automática ao encerrar a conversa;
- proteção contra dois usuários assumirem ao mesmo tempo com transação e bloqueio de linha;
- identificação do responsável na lista e nos dados da conversa;
- auditoria da origem da atribuição.

## O que ainda não está nesta etapa

A agenda ainda não foi dividida por profissional. O vínculo atual controla contato e conversa. A próxima etapa poderá adicionar disponibilidade, agenda e agendamentos individuais por usuário.

## Migration obrigatória

Importe no Adminer antes do novo deploy:

```text
database/migrations/064_professional_conversation_assignment_compat.sql
```

A migration é compatível com versões do MySQL/MariaDB que não aceitam `ADD COLUMN IF NOT EXISTS` e pode ser executada novamente sem recriar as colunas.

Resultado esperado:

```text
Migration 064 aplicada: atendimento por profissional opcional; atribuição automática permanece desativada por padrão.
```

## Ativação na empresa

Abra:

```text
Minha empresa → Equipe e responsabilidade → Atendimento por profissional
```

Configuração recomendada para o primeiro teste:

```text
Usar atendimento por profissional: ativado
Bloquear interferência durante a conversa ativa: ativado
Atribuir automaticamente ao profissional preferido: desativado
```

## Vincular o cliente ao profissional

Abra a Base de contatos, edite o cliente e escolha:

```text
Profissional preferido: João
```

Com a atribuição automática desligada, esse vínculo não entrega a conversa sozinho ao João. Ele apenas registra a preferência.

## Homologação sugerida

1. Caco envia uma mensagem.
2. A conversa aparece disponível e sem responsável.
3. João clica em **Assumir atendimento** ou envia a primeira resposta.
4. Outro barbeiro abre a mesma conversa e consegue acompanhar, mas não responder.
5. Um administrador transfere a conversa para outro profissional e confirma que o novo responsável consegue responder.
6. O responsável encerra a conversa.
7. Confirme que a conversa fica sem responsável.
8. Caco envia uma nova mensagem e confirme que ela volta a ficar disponível, pois a atribuição automática está desligada.
9. Ative temporariamente a atribuição automática e repita o recebimento para validar o comportamento opcional.

## Diagnóstico

O pacote inclui:

```text
database/diagnostics/professional_assignment_v36.7.0.sql
```

Ele mostra a configuração de cada empresa, contatos com preferência e conversas com responsável atual.
