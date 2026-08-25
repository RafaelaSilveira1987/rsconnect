# Relatório da revisão de linguagem — v36.20.1

## Escopo revisado

A revisão percorreu as telas e mensagens dos principais módulos:

- navegação e busca de páginas;
- empresas, usuários e permissões;
- conexões do WhatsApp;
- assistentes de IA;
- conversas, filas e atendimento humano;
- uso e custo da IA;
- limite de gasto por empresa;
- resultados e histórico por cliente;
- planos, cobranças e meios de pagamento;
- agenda e automações n8n;
- relatórios;
- saúde do sistema, avisos e cópias de segurança;
- privacidade e configurações da empresa.

## Alterações aplicadas

1. **Nomes de páginas e menus** foram trocados por ações e resultados fáceis de reconhecer.
2. **Estados em inglês** foram traduzidos, incluindo `healthy`, `attention`, `critical`, `loss` e `unconfigured`.
3. **Indicadores financeiros** receberam explicações como “valor que sobra” e “valor mínimo sugerido”.
4. **Termos de IA** passaram a explicar uso, custo, respostas e informações processadas.
5. **Mensagens de erro** passam por uma camada que troca códigos técnicos por uma orientação compreensível.
6. **Textos carregados depois da abertura da página** também são revisados no navegador.
7. **Placeholders, títulos e rótulos de acessibilidade** entram na mesma revisão.

## Termos mantidos somente para suporte

Alguns nomes precisam continuar disponíveis para implantação ou diagnóstico, como:

- nomes de variáveis do `.env`;
- nomes de tabelas do banco;
- rotas e endereços técnicos;
- nomes oficiais de produtos, como OpenAI, Evolution e n8n;
- códigos retornados por serviços externos.

Sempre que aparecem, devem estar em blocos de código, logs, diagnósticos ou seções de **Detalhes avançados**, acompanhados de uma explicação simples.

## Critério de aceite

A tela é considerada clara quando uma pessoa iniciante consegue:

- explicar para que serve a página;
- entender o significado dos principais números;
- identificar quando algo precisa de atenção;
- escolher a próxima ação sem conhecer programação;
- concluir a tarefa sem orientação oral do desenvolvedor.
