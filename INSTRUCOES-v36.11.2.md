# RS Connect v36.11.2 — Métricas históricas e operacionais

## Base necessária

Aplicar sobre a v36.11.1, na branch `hardening/beta-1.1`.

## Banco de dados

Não existe migration nova. A última migration continua sendo:

`database/migrations/072_security_session_webhook_hardening.sql`

## Instalação

1. Faça backup ou confirme o ponto de restauração atual.
2. Extraia o patch na raiz do projeto, preservando as pastas.
3. Execute:

```powershell
git status
git add .
git commit -m "feat: separar metricas historicas e operacionais"
git push origin hardening/beta-1.1
```

4. Faça o rebuild no EasyPanel.
5. Atualize o navegador com `Ctrl + F5`.

## Homologação

Abra **Relatórios → Equipe e profissionais**.

### Sem o filtro

O relatório mantém histórico e operação juntos, mas mostra claramente:

- quantidade operacional;
- quantidade histórica recuperada;
- início da coleta confiável;
- qualidade e origem de cada ciclo.

### Com o filtro

Marque **Somente métricas operacionais**. O sistema exclui dos cálculos de primeira resposta e encerramentos:

- `migration_snapshot`;
- `migration_069_recovery`;
- qualquer ciclo anterior ao `cutover_at_utc`.

Os dados não são apagados e voltam a aparecer quando o filtro é desmarcado.

### Cenário Rafaela

No período de 30/07/2026 a 31/07/2026:

- sem filtro: 2 respostas, média 22s, menor 16s, maior 28s;
- com filtro: 1 resposta, média 16s, menor 16s, maior 16s.

## Diagnóstico

Execute:

`database/diagnostics/team_metrics_provenance_v36.11.2.sql`

A consulta final deve retornar `inconsistencias_classificacao = 0`.
