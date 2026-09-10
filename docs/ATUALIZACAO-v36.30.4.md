# RS Connect v36.30.4 — Instâncias resilientes

## Objetivo

Fechar a etapa de robustez das conexões WhatsApp sem aumentar a dependência do cliente em relação ao RS Admin. A conexão passa a possuir uma identidade operacional explícita e mecanismos de diagnóstico/recuperação seguros.

## Número autorizado

Cada instância pode informar o número que deve estar conectado. Quando o campo estiver vazio, o primeiro número confirmado pela Evolution é adotado automaticamente. A comparação considera a variação brasileira do nono dígito usada em alguns JIDs do WhatsApp.

Quando há divergência objetiva entre `authorized_phone` e `profile_phone`, o RS Connect marca a identidade como `mismatch`, bloqueia novas mensagens recebidas e bloqueia texto/mídia de saída antes da chamada à Evolution. O bloqueio fica centralizado no `EvolutionService`, reduzindo o risco de um fluxo secundário contornar a proteção.

## Diagnóstico

A ação **Diagnosticar** consulta o estado remoto da sessão, tenta confirmar o número conectado e verifica o acesso ao webhook e às configurações da instância. O resultado é apresentado sem revelar URL privada, hash ou API Key ao usuário do cliente.

## Recuperação

A ação **Recuperar conexão** trata dois cenários:

- queda técnica: solicita reinicialização da instância e coloca o canal em estado `recovering`;
- sessão encerrada/QR Code: não tenta reconectar silenciosamente; prepara ou solicita nova autenticação.

A recuperação automática é configurável por instância e executada pelo Monitor Operacional apenas em conexões gerenciadas. Estados de logout, QR Code e `identity_mismatch` são excluídos da recuperação automática.

## Migration

Aplicar:

```bash
php bin/migrate.php up
php bin/migrate.php status
```

Migration adicionada: `108_evolution_instance_resilience.sql`.

## Pós-deploy

1. Reinicie PHP-FPM/container para limpar OPcache.
2. Abra **Canais WhatsApp** e use **Diagnosticar** em cada instância importante.
3. Confirme o número autorizado exibido antes de habilitar automações críticas.
4. Teste envio e recebimento com uma instância conectada corretamente.
5. Em homologação, conecte propositalmente outro número e confirme que o RS Connect bloqueia entrada e saída.
