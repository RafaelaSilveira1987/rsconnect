# Manual do administrador do cliente

## Objetivo

Permitir que a empresa opere o RS Connect sem depender do Super Admin da RS nas tarefas rotineiras.

## Responsabilidades principais

1. Cadastrar usuários da própria empresa e revisar permissões.
2. Criar e conectar canais WhatsApp.
3. Vincular os canais aos assistentes corretos.
4. Configurar horários, filas, mensagens automáticas e transferência humana.
5. Acompanhar conversas, relatórios, consumo e alertas da própria empresa.

## Fluxo inicial recomendado

1. Acesse **Canais WhatsApp → Nova conexão**.
2. Crie a instância e leia o QR Code.
3. Confirme o estado **Conectado**.
4. Acesse **Assistentes de IA** e vincule o canal.
5. Defina o assistente principal do canal.
6. Configure horário, mensagem fora do expediente e regras de transferência.
7. Faça um teste real de entrada, IA, atendimento humano, liberação e retomada.

## Atendimento humano

- **Assumir e retirar da fila:** torna o usuário responsável, muda a conversa para modo humano e cancela a retomada automática pós-horário.
- **Transferir atendimento:** troca o responsável e preserva histórico, contato e mensagens.
- **Liberar para a equipe:** remove o responsável atual, mantém a conversa em atendimento humano e não devolve automaticamente para a IA.
- **Devolver para IA:** deve ser uma ação explícita e somente após verificar se não há atendimento humano em andamento.

## Cuidados

- Não exclua instâncias diretamente no banco. Use **Canais WhatsApp → Excluir** e selecione uma conexão substituta quando houver vínculos.
- Não compartilhe chaves da Evolution ou OpenAI.
- Confirme o vínculo entre canal e assistente após criar uma nova conexão.
- Use o Super Admin apenas para suporte técnico ou ações globais.
