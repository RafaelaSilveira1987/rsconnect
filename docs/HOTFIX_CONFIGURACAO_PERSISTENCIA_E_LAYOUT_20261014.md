# RS Connect — correção de persistência e conferência visual (14/10/2026)

## Motivo
A configuração Expressa gravava `routing.business_hours` como mapa de dias curtos: `{"mon":[["08:00","18:00"]]}`. A interface `/agents` e o wizard `/onboarding/express` esperavam `[{"day":"monday","start":"08:00","end":"18:00"}]`. Depois do recarregamento, o JavaScript executava `.filter()` num objeto, interrompendo a renderização das perguntas e FAQ, **mesmo quando o banco continha esses registros**.

## O que foi corrigido
- `ExpressBusinessConfigurationReader`: converte horários de qualquer versão do formato para a lista exigida pela API, sem modificar a origem. Busca triagem na tabela operacional `tenant_triage_fields`, respeitando campos ativos. FAQ é reexibido do perfil `tenant_agent_profiles.config_json.express.faq`.
- O GET de leitura e o HTML usam o mesmo serviço de hidratação (sem consultas DDL). O endpoint `GET /api/v1/agents/business-config` está protegido por sessão e por permissões no controller, com `Cache-Control: no-store`.
- Depois do POST, o painel consulta esse GET e compara os grupos de dados enviados com os persistidos. **Só anuncia sucesso depois da conferência.** Se divergir, mantém o formulário e os dados em edição para suporte, não executa salvamentos automáticos repetidos.
- O wizard também verifica após salvar as perguntas personalizadas e FAQ, antes de declarar concluída a configuração.
- A auditoria exibe **WhatsApp conectado na empresa** independentemente do **vínculo ativo e preparado para o agente**; só o segundo permite considerar essa verificação como aprovada. Não faz vinculação automática e não altera o score artificialmente.
- O status de “Tudo salvo” fica separado da nota de **prontidão operacional** (0–100%). Uma nota de 86% indica pendência técnica, não 14% dos campos perdidos.
- CSS compartilhado nas duas telas de configuração: cartões claros, espaçamento, botões, campos e responsividade no mesmo padrão visual de “Meu atendimento”. **Não altera automaticamente todas as demais telas da aplicação**.
- Não adiciona tabelas nem requer migrations.

## Implantação segura
1. Faça backup de banco + diretório atual do RS Connect.
2. Aplique **somente o patch** se a instalação já recebeu o pacote `rs-connect-painel-negocios-agents-completo.zip` anterior. Caso contrário, use o ZIP completo em homologação.
3. Não apague `.env`, `storage/` de produção, uploads ou integrações da instância.
4. Reinicie/limpe o cache OPcache conforme a configuração do PHP-FPM. Atualize a página com Ctrl+F5 (os assets receberam identificador de versão).
5. Em empresa de teste, abra `/agents`, confirme os dados anteriormente cadastrados, inclua duas perguntas personalizadas e três FAQ; clique **Salvar configurações**. Espere a mensagem **gravadas e conferidas**. Recarregue e valide que todas continuam exibidas.
6. Abra `/onboarding/express` e confira as mesmas perguntas e horários.
7. Abra **Canais** e verifique as duas condições: canal conectado e associação operacional ao assistente. Se a pontuação for 86%, leia os itens pendentes no checklist; não marque a conexão como aprovada sem webhook, recebimento e vínculo.
8. Teste fora do expediente, triagem obrigatória, reagendamento e aprovação humana no Laboratório isolado. A atualização não ativa agentes automaticamente.

## Limitações e cuidados
- O comportamento exato em produção depende de migrations já aplicadas, vínculo `ai_agent_instance_bindings`, credenciais de IA, fuso e horário. Não foi possível testar com o MySQL de produção nem com o WhatsApp real neste ambiente.
- Um GET autenticado comprovou no teste simulado a hidratação de dois campos de triagem e três respostas frequentes; os demais testes rodaram no CLI. A captura no Chromium falhou por indisponibilidade do ambiente gráfico.
- Se não existe um agente principal, o diagnóstico do agente específico não fica disponível; isso não significa que o WhatsApp esteja desconectado da empresa.
- O POST da Fase 1 permanece substituindo algumas regras tenant-wide, conforme o contrato anterior. Para empresas com regras personalizadas avançadas, faça cópia e revisão antes de salvar.
