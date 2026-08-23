# RS Connect - v36.18.0

A versão 36.18.0 conclui parte das pendências técnicas da etapa anterior e adiciona a segunda camada de economia de IA.

## Principais implementações

- respostas locais configuráveis para saudações, agradecimentos, despedidas e solicitação de menu;
- respostas locais executadas antes da reserva de franquia e antes da chamada ao provedor de IA;
- cache exato opcional para perguntas curtas e repetidas;
- invalidação automática do cache quando prompt, base de conhecimento, modelo ou temperatura mudam;
- filtros conservadores que não armazenam perguntas personalizadas, agendamentos, cancelamentos, protocolos, links ou números extensos;
- telemetria de chamadas ao provedor evitadas por empresa e assistente;
- novo indicador **Chamadas evitadas** nas telas de consumo;
- correção da identidade, cores e compatibilidade dos relatórios PDF;
- geração de PDF compatível mesmo quando a extensão `mbstring` não está disponível no PHP CLI;
- correção dos testes de relatórios PDF e relatórios agendados;
- remoção dos arquivos temporários `app/Controllers.tmp` e `app/Controllers.tmp.php`.

## Migration obrigatória

Para atualizar a partir da v36.17.2, execute:

```sql
database/migrations/079_ai_efficiency_phase2_and_report_cleanup.sql
```

Depois valide:

```sql
database/diagnostics/ai_efficiency_phase2_v36.18.0.sql
```

Os três primeiros resultados devem retornar `OK`.

## Configuração recomendada

Acesse **Assistentes**, edite o assistente e abra **Respostas sem consumir tokens**.

1. Mantenha **Respostas locais** ativado.
2. Preencha somente as respostas que deseja tratar sem IA.
3. Inicialmente, mantenha o **Cache exato** desativado.
4. Depois de homologar as respostas do assistente, ative o cache com TTL de 168 horas.

As respostas locais só são utilizadas quando a mensagem recebida corresponde exatamente a um padrão curto e inequívoco. Campos vazios não alteram o fluxo atual.

Consulte `INSTRUCOES-v36.18.0.md` para o roteiro completo de implantação e testes.
