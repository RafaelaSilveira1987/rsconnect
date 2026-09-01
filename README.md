## RS Connect 36.26.9

Correção do monitor financeiro para reconhecer como recuperação técnica um webhook autenticado e processado que retorna `ignored` apenas por não encontrar uma cobrança local correspondente.

O monitor agora compara a última falha com a última evidência válida de comunicação. Eventos `payment.webhook.<provider>` com status `ignored` encerram alertas antigos de autenticação quando são posteriores ao erro, sem transformar eventos negados em sucesso.

As melhorias da Central de Monitoramento 36.26.8 e da liberação segura da fila 36.26.7 foram preservadas.

Não há migration nova. Consulte `docs/ATUALIZACAO-v36.26.9.md`.
