# RS Connect 36.31.0 — Atendimento conversacional estruturado

## Objetivo

Transformar orientações que antes dependiam de prompt livre em regras operacionais configuráveis do agente, mantendo o atendimento natural e previsível.

## Novas configurações do Agente

### 1. Entender a demanda

O administrador pode habilitar a coleta da demanda e decidir se ela é obrigatória antes de consultar a agenda. A pergunta sugerida também é configurável.

Clientes e pacientes já conhecidos não são forçados a repetir uma demanda já existente apenas para satisfazer a etapa de triagem.

### 2. Respostas em mensagens curtas

É possível escolher entre:

- **Automático:** preserva uma resposta única, mas respeita blocos naturais gerados pela IA;
- **Preferir mensagens separadas:** divide respostas longas em até 2, 3 ou 4 balões semanticamente completos;
- **Uma única mensagem:** mantém o comportamento tradicional.

A assinatura do agente aparece somente no primeiro bloco para não ser repetida em cada balão.

### 3. Modalidades

As modalidades Online e Presencial passam a ter configuração estruturada.

Online pode informar, por exemplo, `Google Meet`.

Presencial pode informar local, mensagem operacional e dias permitidos. Quando dias permitidos forem definidos, a própria seleção de horários da agenda elimina opções presenciais fora desses dias. Portanto, uma regra como **presencial somente às segundas-feiras** não depende apenas do prompt.

### 4. Valor e pagamento

O agente pode receber valor/regra de preço, formas aceitas e um complemento. O contexto orienta a IA a explicar primeiro a modalidade e depois valor/pagamento quando ambos fizerem parte da resposta.

### 5. Sem disponibilidade

A mensagem de ausência de vagas é configurável e sincronizada com a configuração de pré-agendamento. Depois de informar a indisponibilidade, a empresa pode optar por:

- continuar a conversa normalmente;
- criar aviso interno na RS Connect;
- encaminhar a conversa para atendimento humano, opcionalmente para um responsável específico.

### 6. Encaminhamentos especiais

Assuntos que não devem seguir o pré-agendamento podem ser cadastrados por palavras/frases, por exemplo:

- convite para palestra;
- convite para aula;
- pedido de supervisão;
- parceria profissional.

Quando detectado, o RS Connect pode responder uma mensagem curta ao cliente, pausar a IA, colocar a conversa em atendimento humano e avisar/atribuir ao responsável configurado.

## Integrações preservadas

A implementação utiliza `tenant_agent_profiles.config_json`, portanto **não há migration nova**. A migration obrigatória permanece:

`110_conversation_lifecycle_e2e_consistency.sql`

A configuração continua integrada ao Prompt Studio/Blueprint, triagem, Agenda, Conversation Flow, notificações e envio pela Evolution.

## Homologação sugerida

1. Ative **Entender a demanda** e marque obrigatória antes da agenda.
2. Configure Online = Google Meet.
3. Configure Presencial = Bronze e marque apenas Segunda-feira.
4. Configure valor e Pix/Cartão/Transferência.
5. Selecione **Preferir mensagens separadas** com máximo 3.
6. Configure uma mensagem sem vaga e escolha `Avisar a equipe` ou `Encaminhar`.
7. Crie uma regra `Convite para palestra` com palavras `palestra, convite para palestra, evento`.
8. Teste um novo lead, um paciente atual, uma consulta presencial em dia inválido, agenda sem vagas e um áudio/texto de convite profissional.

## Validação da release

- PHP: 454 arquivos sem erro de sintaxe;
- JavaScript: 4 arquivos validados;
- CSS compartilhado: estrutura balanceada;
- migrations: 117 arquivos / 2187 instruções reconhecidas;
- smoke específico 36.31.0: 35/35 verificações;
- suíte completa: 157 aprovados, 26 falhas históricas, 183 testes;
- novas regressões em relação à 36.30.9: **0**.
