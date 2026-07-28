# RS Connect 36.6.30 — Busca inicial, horários por dia e telemetria clara

## Objetivo

Esta versão refina quatro pontos sem alterar os fluxos já estabilizados de IA, Agenda, Comunicação, Backup ou roteamento de agentes.

### 1. Nova conversa com busca preventiva

A caixa `+ Nova` agora possui uma busca por nome ou telefone antes do primeiro envio.

- pesquisa somente contatos da empresa;
- respeita a instância/canal selecionado;
- preenche telefone e nome ao selecionar um contato;
- identifica quando já existe conversa naquele WhatsApp;
- permite abrir a conversa existente;
- o envio continua reaproveitando contato/conversa existentes em vez de duplicar registros.

### 2. Horário diferente por dia

O assistente deixa de usar uma única faixa para todos os dias selecionados.

Exemplo suportado:

- Segunda a sexta: 08:00–17:00
- Sábado: 08:00–12:00
- Domingo: fechado

O formato salvo continua em `business_hours_json`, portanto não há migration de banco.

### 3. Refinamentos de interface

- aceite LGPD com checkbox e texto alinhados;
- botão de fechar do drawer de Contatos com ícone vetorial e alinhamento correto;
- formulário de nova conversa com hierarquia e busca integrada;
- cards de consumo com leitura mais clara de usado, limite e restante.

### 4. Franquia e telemetria de IA

O limite comercial `Franquia de IA RS/mês` passa a contar somente eventos que atendam simultaneamente:

- `usage_type = auto_reply`;
- `plan_billable = 1`;
- `status = success`;
- `delivery_status = delivered`.

Assim uma chamada bem-sucedida ao provedor que não chegou ao cliente não reduz franquia.

A área de assinatura passa a exibir, em um detalhamento separado:

- interações entregues;
- chamadas ao provedor;
- tokens de entrada;
- tokens de saída;
- total de tokens;
- falhas técnicas.

Requests do provedor e interações comerciais continuam propositalmente separados.

## Banco

Não há migration nova nesta versão.

A base atual permanece na migration `059_contact_identity_confidence.sql`.

## Pós-deploy

1. Faça o deploy da 36.6.30.
2. Execute `Ctrl + F5` no navegador.
3. Abra `Conversas → + Nova` e valide a busca de contato.
4. Abra `Assistentes de IA` e configure faixas diferentes por dia.
5. Abra `Assinatura e uso` e compare a franquia com o detalhamento técnico.

