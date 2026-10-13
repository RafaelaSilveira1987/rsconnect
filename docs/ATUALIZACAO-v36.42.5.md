# Atualização RS Connect 36.42.5

## Objetivo

Eliminar falsos alertas quando o n8n foi desativado intencionalmente e diferenciar essa situação de uma configuração ainda ativa que continua dependendo da automação externa.

## O que foi corrigido

- o monitor de n8n não consulta nem exige workflows externos quando não existe nenhum fluxo ativo cadastrado no RS Connect;
- sem fluxo n8n ativo e sem dependência configurada, o estado passa a ser **Tudo normal / integração externa não utilizada**;
- se a régua de cobrança ainda possui regras ativas com canal `n8n`, `WhatsApp via n8n` ou `E-mail via n8n`, o alerta permanece, mas agora informa a causa real: existem regras ativas dependentes de uma automação externa que foi desligada;
- o monitor dos lembretes de cobrança passa a encerrar o incidente automaticamente quando não existe nenhuma regra ativa;
- o playbook operacional deixa de exigir n8n por padrão e orienta primeiro a conferir se as regras de cobrança realmente devem permanecer ativas;
- incidentes antigos de `operations.alert.n8n` e `operations.alert.billing_cron` são resolvidos automaticamente na próxima verificação quando a configuração estiver coerente.

## Como homologar

### Cenário A — n8n desativado e régua também desativada

1. Deixe todos os fluxos n8n inativos.
2. Marque todas as regras da régua de cobrança como **Inativas**.
3. Execute **Verificar sistema agora**.
4. `Automações` deve ficar normal, informando que a integração externa não está em uso.
5. `Lembretes de cobrança` deve ficar normal, informando que não existem regras ativas.
6. Os incidentes abertos correspondentes devem ser encerrados automaticamente.

### Cenário B — n8n desativado, mas regras externas continuam ativas

1. Deixe todos os fluxos n8n inativos.
2. Mantenha ao menos uma regra ativa com canal n8n/externo.
3. Execute a verificação.
4. O sistema deve exibir atenção com mensagem explícita de incompatibilidade de configuração, e não a mensagem genérica de “automação não concluída”.

## Banco de dados

Nenhuma migration nova. Permanece obrigatória:

`124_calendar_slot_capacity_mode.sql`
