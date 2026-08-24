# Homologação RS Connect 36.6.34

## A. Teste gratuito

- [ ] Selecionar situação `Teste` exibe o bloco de configuração.
- [ ] Informar início e 7 dias calcula corretamente o último dia gratuito.
- [ ] Próxima cobrança fica no dia seguinte ao fim do teste.
- [ ] O cliente vê o banner com dias restantes.
- [ ] A página da assinatura explica último dia, primeira cobrança e regra pós-teste.
- [ ] Não é possível criar cobrança manual durante o período gratuito.
- [ ] Recursos e limites do plano permanecem disponíveis durante o teste.
- [ ] Faturas antigas não interrompem um teste ativo.
- [ ] `Aguardar pagamento` respeita a tolerância configurada.
- [ ] `Converter para ativa` inicia o ciclo pago após o teste.
- [ ] `Suspender` bloqueia o acesso após o teste.

## B. Preservação das empresas existentes

- [ ] Empresa que já possuía agente, instância ou conversa não é enviada novamente ao onboarding.
- [ ] Empresa nova e sem operação entra no primeiro acesso guiado.

## C. Primeiro acesso guiado

- [ ] Login inicial abre `Primeiros passos`.
- [ ] Etapa 1 permite revisar os dados operacionais da empresa.
- [ ] LGPD só é liberada após o cadastro.
- [ ] Aceite da LGPD retorna ao onboarding.
- [ ] Regras de atendimento podem ser salvas antes de existir agente.
- [ ] Agenda pode ser configurada ou dispensada.
- [ ] WhatsApp fica entre as últimas etapas.
- [ ] Agente é criado após a conexão WhatsApp.
- [ ] Regras de atendimento salvas anteriormente são aplicadas ao agente.
- [ ] Teste final libera o restante do painel.
- [ ] Ao sair e entrar, o usuário retorna à etapa pendente.
- [ ] Etapas futuras mostram bloqueio e orientação.

## D. Regressão

- [ ] Super Admin continua acessando todas as telas.
- [ ] Empresas com onboarding concluído continuam com navegação normal.
- [ ] IA, Agenda, Conversas, CRM e Central de comunicação permanecem operacionais.
- [ ] Migração 060 aparece no painel de homologação.
