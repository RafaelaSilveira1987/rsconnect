# Guia de exclusão assistida de instâncias

## Objetivo

Remover uma conexão WhatsApp sem perder vínculos operacionais nem precisar excluir registros diretamente no banco.

## O que o RS Connect verifica

Antes da exclusão, o sistema apresenta:

- assistentes vinculados no cadastro legado;
- vínculos de assistentes por canal;
- contatos;
- conversas;
- campanhas;
- relatórios agendados;
- eventos técnicos da conexão.

## Quando uma substituta é obrigatória

Se existir qualquer vínculo operacional, selecione outra conexão da mesma empresa. O RS Connect transfere os dados antes de apagar a conexão antiga.

## Como excluir

1. Acesse **Canais WhatsApp**.
2. Na conexão antiga, clique em **Excluir**.
3. Aguarde a consulta dos vínculos.
4. Selecione a **instância substituta**.
5. Revise os avisos de consolidação.
6. Defina se a instância também será removida da Evolution API.
7. Marque a confirmação de revisão dos vínculos.
8. Digite exatamente `EXCLUIR nome-da-instancia`.
9. Confirme a operação.

## Consolidação de dados

Quando o mesmo contato já possui conversa na conexão substituta, o sistema consolida o histórico em uma única conversa. Mensagens, eventos, anexos, notas, agenda, histórico de atribuição, ciclos de atendimento e pendências pós-horário são preservados.

Pendências pós-horário migradas são canceladas para evitar que o worker responda novamente após a troca de canal.

## Instância remota

- **Excluir também na Evolution:** remove a instância local e remota.
- **Manter na Evolution:** remove apenas o cadastro do RS Connect. Se a conexão ainda estiver ativa, uma confirmação adicional é obrigatória.
- **Conexão externa já ausente:** o modal passa para exclusão local. Se houver vínculos, a transferência para outra conexão continua obrigatória; sem vínculos, a etapa de destino é ocultada.

## Auditoria

A operação registra:

- dados básicos da conexão removida;
- quantidade de vínculos encontrados;
- instância substituta;
- quantidade de registros transferidos ou consolidados;
- resultado da exclusão remota.

## Cuidados

- Não apague instâncias diretamente no banco.
- Não escolha uma substituta de outra empresa.
- Confirme que a conexão de destino está funcional antes da transferência.
- Faça backup antes de remover canais com grande volume de conversas.
