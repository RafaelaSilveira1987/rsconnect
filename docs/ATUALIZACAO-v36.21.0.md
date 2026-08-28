# Atualização v36.21.0 — demonstração e automação comercial

## Entregas

### Demonstração na tela de login

- Botão **Testar a IA em uma demonstração**.
- Celular interativo com respostas rápidas e animação de digitação.
- Mini fluxo de qualificação, proposta, negociação, transferência e fechamento.
- Card comercial visual que acompanha a evolução da conversa.
- Fluxo local sem custo de tokens e sem acesso a dados reais.

### Automação opcional do Comercial

- Ativação independente por empresa.
- Modos **Apenas sugerir** e **Movimentar automaticamente**.
- Análise econômica por regras ou IA contextual com a credencial do assistente.
- Confiança mínima configurável entre 60% e 99%.
- Restrição de movimentações regressivas.
- Notificações, justificativa e trecho da conversa.
- Aprovação ou rejeição de sugestões dentro do negócio.
- Histórico auditável das decisões.
- Bloqueio da automação por card.
- Pausa automática de 6 horas após movimentação manual.

## Atualização do banco

Execute:

```bash
php bin/migrate.php up
```

Migration obrigatória:

```text
090_crm_conversation_automation.sql
```

A automação nasce desativada para todas as empresas. O CRM manual continua funcionando normalmente.
