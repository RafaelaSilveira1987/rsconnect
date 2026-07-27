# Homologação final — RS Connect 36.6.26

## 1. Banco

Aplicar:

```sql
SOURCE database/migrations/059_contact_identity_confidence.sql;
```

Confirmar no Status/Homologação que a migration base exibida é `059`.

## 2. Recuperação da disponibilidade

Pré-condição: workflow `RS Connect - Fila rápida da IA` ativo.

1. Iniciar um agendamento real.
2. Informar modalidade Online ou Presencial.
3. Informar/preencher uma preferência de data e horário.
4. Confirmar a mensagem "Vou verificar a disponibilidade...".
5. Aguardar o retorno do n8n e a próxima passagem da Fila rápida.
6. O contato deve receber opções reais ou mensagem de indisponibilidade; não deve permanecer indefinidamente na mensagem de espera.
7. Reexecutar a fila não pode duplicar a mesma resposta.

### Retry
Se o callback do n8n não retornar, após aproximadamente 2 minutos o mesmo request deve voltar a ser elegível para retry, sem criar outro pré-agendamento.

## 3. Identidade do contato — sem nome

1. Usar um número ainda não cadastrado.
2. Enviar a primeira mensagem.
3. Se não houver nome confiável, a lista e o cabeçalho devem exibir o telefone, e não o nome do proprietário do WhatsApp conectado.
4. Uma mensagem enviada pela própria conta (`fromMe`) não pode alterar o nome do contato.

## 4. Nome automático confiável

1. Para um contato sem nome manual, receber de forma consistente o mesmo nome real em mensagens de entrada.
2. O nome só pode ser promovido após a confirmação definida pelo motor de identidade.
3. Se o mesmo nome automático surgir para números diferentes, ele deve ser tratado como suspeito e o telefone volta a ser a identificação segura.

## 5. Nome manual

1. Editar o contato e informar um nome manual.
2. Salvar.
3. Novos webhooks não podem sobrescrever esse nome.
4. Limpar manualmente o nome de um contato antigo contaminado e salvar; a interface deve voltar a mostrar somente o telefone.

## 6. Regressões

Validar também:

- atendimento Humano continua bloqueando automações;
- horário e tempo de espera continuam sendo respeitados;
- modalidade Online/Presencial continua obrigatória antes da disponibilidade;
- Google Calendar só recebe compromisso confirmado;
- Fila rápida não duplica mensagens já comunicadas.
