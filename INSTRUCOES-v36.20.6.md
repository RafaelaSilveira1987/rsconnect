# RS Connect v36.20.6

Corrige o caso em que a conexão era removida diretamente no serviço externo antes da exclusão no RS Connect. A exclusão local passa a reconhecer a ausência remota e continua de forma idempotente. Não há migration nova.
