# Guia de linguagem simples do RS Connect

## Para quem escrevemos

As telas devem ser compreendidas por uma pessoa adolescente, por um novo funcionário ou por alguém que nunca utilizou uma plataforma de automação.

## Regra das três respostas

Todo aviso ou indicador precisa responder:

1. **O que aconteceu?**
2. **O que isso pode causar?**
3. **O que devo fazer agora?**

## Como nomear funções

Use a ação ou o resultado esperado, não o nome técnico da tecnologia.

| Evitar na tela principal | Preferir |
|---|---|
| Tenant | Empresa |
| Webhook | Atualizações automáticas |
| Telemetria | Dados de uso |
| Snapshot | Registro mensal |
| Prompt | Instruções do assistente |
| Token | Unidade de uso da IA |
| Provider | Serviço de IA |
| Gateway | Meio de pagamento |
| Runtime | Durante o funcionamento |
| Worker | Rotina automática |
| Queue | Fila |
| Threshold | Limite de alerta |
| Takeover | Assumir atendimento |
| MRR | Receita mensal |
| Margem de contribuição | Valor que sobra após os custos informados |

## Quando manter um termo técnico

O termo pode ser mantido quando for necessário para configurar um serviço externo ou falar com o suporte. Nesse caso:

- mostre primeiro a explicação simples;
- coloque o termo técnico entre parênteses ou em **Detalhes avançados**;
- inclua um exemplo de preenchimento;
- nunca exponha chaves ou senhas.

Exemplo:

> **Endereço que recebe as atualizações**  
> Usado para avisar o RS Connect quando uma mensagem chega.  
> Detalhes avançados: URL do webhook.

## Mensagens de erro

Evite mostrar apenas códigos como `SQLSTATE`, `HTTP 400` ou `Invalid parameter number`.

Estrutura recomendada:

> **Não foi possível salvar a conexão.**  
> Alguns dados enviados não foram aceitos. Revise os campos e tente novamente.  
> Detalhes técnicos disponíveis para o suporte.

## Revisão de cada nova tela

Antes de publicar, confirme:

- O título diz claramente para que a tela serve?
- Os números possuem unidade e explicação?
- O botão descreve a ação?
- O usuário sabe o que acontecerá depois do clique?
- Existe alguma sigla sem explicação?
- Uma pessoa iniciante consegue concluir a tarefa sem treinamento?
