# RS Connect 36.6.28 — Polimento visual da Central de comunicação

## Objetivo

Refinar exclusivamente a experiência visual do Super Admin em `Operação RS > Comunicados`, preservando a lógica funcional já estabilizada na 36.6.27.

## O que mudou

- formulário reorganizado visualmente em três etapas: **Conteúdo**, **Destino e interação** e **Entrega**;
- campos de texto, selects e textarea passam a ter estados de foco, espaçamento e acabamento alinhados ao restante do RS Connect;
- empresas destinatárias passam a ser apresentadas como cards selecionáveis dentro de uma área com scroll;
- canais de entrega passam a ser exibidos como cards com estado visual;
- resumo superior, abas e cabeçalho ganharam hierarquia e acabamento mais claros;
- preview lateral ganhou aparência mais próxima da experiência real do cliente e indicação de atualização em tempo real;
- bloco de boas práticas foi reduzido e convertido em apoio visual de pré-envio;
- barra de envio permanece visível no rodapé do formulário durante a composição;
- histórico e respostas recebem pequenos refinamentos de hover, borda e hierarquia;
- nenhum emoji foi introduzido nas interfaces novas; os estados continuam usando ícones vetoriais.

## O que NÃO mudou

- regras de público e destinatários;
- criação de comunicados;
- leitura e contador de não lidos;
- confirmação de leitura;
- resposta da empresa e resposta da RS;
- validade do comunicado;
- floating inbox e drawer no cliente;
- banco de dados.

## Banco de dados

Não há migration nova.

A base consolidada permanece em:

`059_contact_identity_confidence.sql`

A Central de comunicação continua dependendo da migration `058_client_communication_center.sql` já aplicada.

## Deploy

1. Publicar os arquivos da 36.6.28.
2. Fazer redeploy/restart da aplicação.
3. Fazer `Ctrl + F5` no navegador.
4. Confirmar que o CSS foi carregado como `app.css?v=36.6.28`.
5. Não executar SQL novo.

## Homologação visual mínima

1. Abrir `Operação RS > Comunicados > Novo comunicado`.
2. Confirmar os três blocos visuais do formulário.
3. Validar foco dos inputs/selects e textarea.
4. Selecionar uma ou mais empresas e verificar os cards de destinatários.
5. Alternar Tipo, Prioridade e Resposta do cliente e verificar o preview.
6. Confirmar responsividade em resolução menor.
7. Enviar um comunicado de teste e verificar que a lógica funcional continua igual à 36.6.27.
