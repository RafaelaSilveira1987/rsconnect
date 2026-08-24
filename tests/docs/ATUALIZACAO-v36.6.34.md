# Atualização RS Connect 36.6.34

## Teste gratuito e primeiro acesso guiado

Esta versão transforma o campo de teste em uma regra efetiva de acesso e organiza o primeiro acesso do cliente em etapas sequenciais.

## 1. Implantar o pacote

Suba o código da versão 36.6.34 e conclua o deploy normalmente.

## 2. Aplicar a migration obrigatória

No MySQL do RS Connect:

```sql
SOURCE database/migrations/060_free_trial_guided_first_access.sql;
```

A migration:

- adiciona duração, comportamento pós-teste e tolerância à assinatura;
- cria `tenant_onboarding_settings` para guardar regras operacionais antes do agente existir;
- corrige a primeira cobrança de testes já cadastrados;
- preserva o acesso de empresas que já possuíam agente, WhatsApp ou conversa antes da atualização.

## 3. Criar um teste gratuito

Acesse **Planos e cobrança**, escolha a empresa e clique em **Editar vigência**.

Configure:

- Situação: `Teste`;
- Plano utilizado durante a avaliação;
- Início do período;
- Duração do teste em dias;
- Comportamento após o teste;
- Tolerância, quando aplicável;
- Valor negociado para o período pago.

Para um teste iniciado em 28/07/2026 com 7 dias:

- último dia gratuito: 03/08/2026;
- primeira cobrança prevista: 04/08/2026.

Durante o teste, o sistema não permite criar cobrança manual antes do término.

## 4. Comportamentos pós-teste

### Aguardar contratação/pagamento

Mantém o acesso pelo número de dias de tolerância definido. Depois disso, bloqueia até que a assinatura seja atualizada.

### Converter para assinatura ativa

No primeiro processamento após o término, a assinatura muda para ativa e inicia o período pago no dia seguinte ao teste.

### Suspender acesso

Ao terminar o teste, a assinatura e a empresa são suspensas, preservando os dados.

## 5. Primeiro acesso guiado

Empresas novas entram nesta sequência:

1. Cadastro da empresa;
2. LGPD e termos;
3. Regras de atendimento;
4. Agenda ou dispensa da agenda;
5. Conexão WhatsApp;
6. Criação do agente de IA;
7. Teste final.

As telas futuras ficam bloqueadas até a conclusão da etapa anterior. Ao sair e entrar novamente, o usuário retorna para a etapa pendente.

As regras de horário, tempo de espera e atendimento humano são salvas antes da criação do agente e aplicadas automaticamente quando ele for criado.

## 6. Validação rápida

- Crie uma empresa nova sem agente, instância ou conversa.
- Cadastre um usuário administrador dessa empresa.
- Faça o primeiro login.
- Confirme que a primeira tela é **Primeiros passos**, e não o aceite LGPD isolado.
- Salve o cadastro e confirme que a LGPD é liberada em seguida.
- Avance até WhatsApp e agente.
- Confirme que outras telas permanecem indisponíveis antes da etapa correspondente.

## Banco de dados

Migration nova obrigatória:

```text
060_free_trial_guided_first_access.sql
```
