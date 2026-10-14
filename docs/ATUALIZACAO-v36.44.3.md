# Atualização RS Connect 36.44.3

## Objetivo

Refinar a Ativação Assistida sem criar novas telas técnicas: corrigir o layout do cadastro, tornar o SLA explicitamente opcional e permitir preparar o assistente dentro do próprio onboarding.

## O que validar

1. **Cadastro da empresa**
   - o card `Configuração recomendada` termina logo após o conteúdo;
   - não existe mais um grande espaço vazio antes de `Ajustes opcionais`;
   - abrir e fechar `Ajustes opcionais` não desloca ou sobrepõe os cards.

2. **Atendimento / SLA**
   - em `Ajustes avançados`, `Medir SLA` começa desativado para empresa que nunca salvou política de SLA;
   - ao ativar, aparecem meta da primeira resposta, alerta preventivo e regra fora do expediente;
   - salvar e reabrir mantém o estado escolhido.

3. **Assistente**
   - a etapa não redireciona obrigatoriamente para `/agents`;
   - o usuário escolhe apenas nome, tom e WhatsApp conectado;
   - blueprint, triagem, workflow, policies e capabilities continuam sendo a fonte de verdade;
   - configurações técnicas avançadas continuam disponíveis depois da ativação, mas não bloqueiam o onboarding.

## Banco de dados

Não há migration nova. Permanece obrigatória:

`125_native_financial_lifecycle_notifications.sql`

## Teste automatizado

```bash
php tests/Feature/assisted-onboarding-refinement-v36443-smoke.php
```
