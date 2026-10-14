# Atualização RS Connect 36.44.1 — Cadastro orientado por segmento

## Objetivo
A escolha do segmento passa a ser responsabilidade da própria empresa durante o onboarding. O segmento é obrigatório e determina o blueprint inicial que prepara o atendimento assistido.

## O que muda
- RS Admin cadastra apenas dados oficiais, plano e administrador inicial;
- cliente escolhe o segmento na etapa 1 do onboarding;
- o RS Connect filtra/sugere o blueprint compatível;
- ao salvar, perguntas, permissões, políticas e workflow do blueprint são aplicados;
- sem segmento/modelo não é possível concluir o cadastro;
- razão social e documento continuam protegidos;
- exige pelo menos e-mail comercial ou telefone.

## Banco de dados
Nenhuma migration nova. Permanece obrigatória:

`125_native_financial_lifecycle_notifications.sql`

## Homologação
1. Criar um tenant novo no painel RS.
2. Confirmar `segment`, `business_niche_id` e `agent_blueprint_id` vazios no nascimento do tenant.
3. Entrar como `client_admin`.
4. Abrir **Primeiros passos → Cadastro da empresa**.
5. Confirmar que o segmento é obrigatório.
6. Selecionar cada segmento disponível e verificar o filtro de modelos.
7. Salvar e confirmar que `business_niche_id`, `agent_blueprint_id` e `segment` foram preenchidos.
8. Confirmar existência/ativação do `tenant_agent_profiles` correspondente ao modelo escolhido.
9. Recarregar o onboarding e confirmar que a próxima etapa foi liberada.
10. Salvar novamente sem mudar o blueprint e verificar que o perfil não foi recriado.

## Rollback
O rollback de arquivos para 36.44.0 não exige rollback de banco, pois esta versão não cria nem altera estrutura SQL. Tenants que já tiverem escolhido segmento/modelo permanecem compatíveis com 36.44.0.
