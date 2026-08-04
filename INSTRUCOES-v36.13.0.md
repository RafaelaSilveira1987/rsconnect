# RS Connect v36.13.0 — Áudios, imagens e documentos nas conversas

Esta versão adiciona mídias às conversas sem alterar a release protegida `v36.12.1`.

## Entregas

- reprodução de áudios recebidos e enviados, com velocidades 1x, 1,5x e 2x;
- imagens exibidas dentro da conversa e abertura ampliada;
- PDFs com visualização protegida e download;
- envio de imagem, PDF ou áudio pelo botão de anexo ou por arrastar e soltar;
- legenda opcional com a assinatura humana já existente;
- recebimento de mídias pelo webhook da Evolution;
- armazenamento privado separado por empresa;
- download autenticado e revalidação do acesso à conversa;
- validação de MIME, tamanho, hash SHA-256 e nome interno aleatório.

## Instalação

1. Crie uma branch a partir da `main` homologada:

```powershell
git switch main
git pull origin main
git switch -c feature/conversas-midia-anexos
```

2. Substitua o projeto pelo conteúdo deste ZIP completo.
3. Preserve o `.env` real; ele não faz parte do pacote.
4. Execute a migration:

```text
database/migrations/074_conversation_message_attachments.sql
```

5. Configure no `.env` do EasyPanel:

```dotenv
CONVERSATION_ATTACHMENTS_ENABLED=true
CONVERSATION_ATTACHMENT_MAX_MB=20
CONVERSATION_ATTACHMENTS_PATH=/var/www/html/storage/conversation-attachments
```

6. No EasyPanel, monte um volume persistente exatamente em:

```text
/var/www/html/storage/conversation-attachments
```

Sem volume persistente, os anexos locais podem ser perdidos em um rebuild.

7. Faça o Deploy/Rebuild. O Dockerfile já configura o PHP para uploads de até 25 MB, enquanto a aplicação limita cada arquivo a 20 MB por padrão.

## Formatos liberados

- Imagens: JPEG, PNG e WEBP;
- Documentos: PDF;
- Áudios: MP3, OGG, OPUS e M4A.

O tipo do arquivo é verificado pelo conteúdo real, e não apenas pela extensão.

## Homologação

Faça os testes em uma empresa de homologação:

1. Receba um áudio real pelo WhatsApp e reproduza em 1x, 1,5x e 2x.
2. Receba uma imagem e abra a visualização ampliada.
3. Receba um PDF e teste Visualizar e Baixar.
4. Envie uma imagem com legenda.
5. Envie um PDF sem legenda.
6. Envie um áudio já gravado.
7. Tente enviar um executável renomeado para `.pdf`; o sistema deve bloquear.
8. Tente acessar o UUID do anexo com usuário de outra empresa; deve retornar 404.
9. Faça um rebuild e confirme que os arquivos continuam disponíveis no volume persistente.

## Diagnóstico

Execute:

```text
database/diagnostics/conversation_attachments_v36.13.0.sql
```

A consulta de inconsistências entre empresas deve retornar zero linhas.

## Observação sobre compatibilidade da Evolution

A implementação utiliza os endpoints de envio de mídia e obtenção do conteúdo em base64 da Evolution API v2. A homologação real é necessária porque instalações ou versões diferentes da Evolution podem retornar pequenas variações no payload.
