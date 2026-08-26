# Excluir uma conexão que já não existe fora do RS Connect

Às vezes um número é removido diretamente no serviço externo do WhatsApp, mas o cadastro continua aparecendo no RS Connect.

A exclusão assistida verifica essa situação antes de concluir a operação.

## Quando a conexão externa já não existe

A tela informa:

> A conexão externa não foi encontrada. Será removido somente o cadastro do RS Connect.

A partir desse momento, o modal passa para o modo de exclusão local:

- a opção de excluir no serviço externo é ocultada;
- o título e a confirmação deixam claro que somente o cadastro local será removido;
- a Evolution não é chamada novamente para excluir a conexão;
- a operação continua protegida pela confirmação digitada.

## Quando existem dados vinculados

A ausência da conexão externa não elimina os dados que ainda pertencem ao cadastro local.

Se houver assistentes, vínculos de canal, contatos, conversas, campanhas ou relatórios, o sistema exige outra conexão da mesma empresa para receber esses dados. O botão será:

> Transferir dados e excluir cadastro

O cadastro antigo somente é removido depois da transferência validada.

## Quando não existem vínculos operacionais

A etapa de destino é ocultada e o botão será:

> Excluir cadastro do RS Connect

Nesse cenário, somente o cadastro local e os registros técnicos vinculados à própria conexão são removidos.

## Quando a consulta externa falhar

Se o serviço estiver temporariamente indisponível, o RS Connect não assume que a conexão foi apagada. A tela mantém as confirmações de segurança para evitar uma exclusão incorreta.

## Dados preservados

Quando uma conexão substituta é escolhida, o sistema preserva:

- assistentes;
- regras de atendimento;
- contatos;
- conversas e mensagens;
- campanhas;
- relatórios programados.
