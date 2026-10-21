# RS Connect — Central de Testes Automatizados (21/10/2026)

## Objetivo e estado real

O RS Connect usa PHP 8.2+, PDO, roteamento próprio e testes PHP executáveis individualmente. Não é Laravel/PHPUnit. Este pacote **não altera a lógica de produção**; entrega testes, um executor CLI, relatórios e uma rotina opcional de CI.

Executados sobre `rs-connect-hotfix-triagem-respostas-campos-20261021-completo.zip`, com PHP CLI 8.4.24 neste ambiente:

| Suíte | Aprovados | Falhas | Observação |
|---|---:|---:|---|
| lint | 632 | 0 | Checagem de sintaxe de app, rotas, bin e tests |
| critical | 16 | 0 | Cenários priorizados para cada mudança |
| investigate | 0 | 4 | Falhas investigáveis, já presentes nesta base |
| all | 222 | 68 | Inclui testes antigos; várias expectativas históricas requerem revisão |

A suíte `all` **não está verde**. As 68 falhas não foram corrigidas nem reclassificadas automaticamente. Algumas parecem verificações de versões antigas; outras representam diferenças de comportamento reais ou diferenças do ambiente. Devem ser avaliadas individualmente. **Não interpretar 16/16 em `critical` como homologação ponta a ponta.**

## Como executar localmente

A partir da raiz do projeto:

```bash
php bin/qa.php --suite=lint
php bin/qa.php --suite=critical
php bin/qa.php --suite=investigate
php bin/qa.php --suite=all
```

O executor habilita `zend.assertions=1` e `assert.exception=1` para cada subprocesso. Isso é necessário porque testes legados utilizam `assert()` e poderiam passar sem executar as asserções quando a configuração PHP as desativa. Cada teste roda em processo isolado, com timeout padrão de 45 segundos.

**Proteção contra falso positivo:** o bootstrap atual captura exceções e pode imprimir `Ocorreu um erro interno` com código de saída `0`. O executor trata essa saída como falha.

Saída não zero bloqueia uma etapa automatizada; cada execução gera `storage/qa-reports/qa-<suite>.json` e `.xml` (JUnit), com arquivos, tempos e resultados. Os relatórios não devem ser publicados com credenciais; o executor mascara padrões simples de token, mas ainda é preciso restringir o acesso.

Opcionalmente, informe `--report-dir=/diretorio/seguro`, `--timeout=60`, `--list` ou `--verbose`.

## Comparar a versão atual com a próxima

Guarde uma cópia do JSON gerado **fora da raiz pública** e execute:

```bash
php bin/qa-diff.php /caminho/versao-anterior.json /caminho/versao-nova.json
```

A comparação informa falhas novas, anteriores, corrigidas e testes removidos. Retorna erro se surgirem novas falhas ou um teste desaparecer. Falhas antigas continuam visíveis, **não equivalem a aprovação**. Comparar relatórios exige a mesma versão PHP e os mesmos recursos de ambiente para reduzir ruído.

## Organização das suítes

- `critical`: 16 testes de triagem, hora/agenda, conciliação, capacidade, idempotência, aprovação, lembrete e prontidão. Os dois testes novos ficam em `tests/Regression`.
- `investigate`: quatro regressões da grade, privacidade, janela diária e consulta, reprovaram na base analisada.
- `historical`: testes legados com nomes `*-smoke.php` não selecionados acima; ajudam a detectar mudanças em contratos históricos.
- `all`: união dessas suítes. Falhas mantidas, sem exceções silenciosas.
- `lint`: syntax check de arquivos PHP; não testa comportamento nem SQL.
- `integration`: somente leitura do banco em homologação. Verifica conectividade e existência das tabelas. Não escreve dados, não testa colisões.

## Teste de integração (restrito a homologação)

Use usuário MySQL dedicado **somente-leitura**, dados fictícios e valores de ambiente próprios. Não execute em produção.

```bash
QA_TARGET=homologation QA_ALLOW_DB=1 php bin/qa.php --suite=integration
```

O teste rejeita `APP_ENV=production`/`prod` e nomes óbvios de DB de produção, mas esses bloqueios não substituem isolamento por credenciais e rede. Execução com MySQL real dependerá de `.env` de homologação. Não foi executada neste ambiente.

## GitHub Actions

O arquivo `.github/workflows/rs-connect-quality.yml` executa `lint` e `critical` em PHP 8.2 e 8.3 quando o código é enviado ou é aberto um PR. Os relatórios ficam disponíveis como artefatos. **O workflow foi criado, mas só funcionará após publicar o projeto no GitHub e habilitar as ações no repositório.** Não usa credenciais de produção. Recomendamos exigir o status do workflow nas regras de proteção da branch.

A suíte ampliada deve ser executada em paralelo, ter suas falhas classificadas e, progressivamente, migrar cenários validados para `critical`.

## Próximos testes necessários para a homologação verdadeira

1. MySQL descartável: duas conexões tentando a última vaga; idempotência com a mesma chave; capacidade de quatro pessoas; tentativa de quinta vaga; cancelamento e remarcação com rollback.
2. Worker: lembretes pendentes descartados mesmo se o job foi criado antes da aprovação; aprovado recebe exatamente uma comunicação.
3. Webhook simulado: recebimento duplicado da mesma mensagem, idempotência, roteamento correto de tenant/instância.
4. Conversa completa: fila após expediente, pergunta por pergunta, validação da triagem, grade diária, pré-reserva e confirmação humana.
5. Google Calendar (sandbox): falha temporária, sync pendente, tentativas de reconciliação, sem afirmar confirmação antes da sincronização.

Esses cenários **não estão implementados como testes integrados nesta entrega** e exigem ambiente isolado/fixtures e serviços controlados.

## Implantação

Faça backup; aplique apenas os arquivos novos/alterados. Esta fase não altera tabelas ou regras de produção. Execute `php bin/qa.php --suite=critical` no diretório raiz do pacote no terminal; para relatório completo use `--suite=all`. Só libere atualizações após as validações críticas e homologação de operações reais.
