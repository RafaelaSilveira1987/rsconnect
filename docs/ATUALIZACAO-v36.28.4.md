# RS Connect 36.28.4 — diagnóstico correto da chave global de IA

## Problema corrigido

A tela de saúde da empresa considerava apenas registros ativos de `ai_provider_credentials` ao avaliar cada assistente. Quando o assistente usava a chave global do ambiente (`OPENAI_API_KEY` ou a chave global Gemini), o processamento real podia funcionar por fallback, mas o painel exibia **sem credencial de IA ativa**.

## Correção

O `TenantHealthService` agora usa a mesma regra operacional do mecanismo de IA:

1. usa uma chave cadastrada para o assistente/empresa, quando existir;
2. caso contrário, reconhece a chave global da RS Connect correspondente ao provedor;
3. só marca o assistente como sem acesso à IA quando nenhuma dessas fontes estiver disponível.

O diagnóstico também mostra a origem em linguagem simples:

- `Chave cadastrada para a empresa ou assistente`;
- `Chave principal da RS Connect`;
- `Nenhuma chave disponível`.

Não há migration nova nesta versão.
