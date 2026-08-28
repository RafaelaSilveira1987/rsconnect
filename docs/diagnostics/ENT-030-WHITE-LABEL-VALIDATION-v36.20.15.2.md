# Validação — RS Connect v36.20.15.2

## Sintaxe

- 316 arquivos PHP: sem erro de sintaxe;
- `app.js`, `reports.js` e `company-settings.js`: sintaxe válida;
- rotas White Label: registradas e preservadas;
- proteção CSP da ENT-030: preservada.

## Testes específicos

- hotfix de upload e layout: 13 verificações aprovadas;
- rota White Label: 8 verificações aprovadas;
- XSS/SVG/CSP: 24 verificações aprovadas;
- health checks: 11 verificações aprovadas.

## Teste HTTP do arquivo persistente

A rota `/white-label/asset` foi executada com uma imagem PNG válida armazenada em `storage/app/white-label`.

Resultado:

```text
HTTP 200
Content-Type: image/png
Content-Length: 68
Content-Disposition: inline
Cache-Control: public, max-age=86400, immutable
X-Content-Type-Options: nosniff
```

O corpo foi identificado como imagem PNG válida.

## Suíte completa

```text
84 aprovados
9 reprovados
93 total
```

As nove falhas são as mesmas pendências históricas relacionadas a documentação antiga, temporários legados e fila humana fora do horário.

## Banco

- nenhuma migration criada;
- nenhuma tabela alterada;
- nenhum dado real manipulado.

## Risco residual

Em volumes antigos, o proprietário de `storage` pode não ser o usuário `www-data`. O build prepara a pasta automaticamente; caso o volume preserve permissões antigas, deve-se executar `chown`/`chmod` conforme as instruções do pacote.
