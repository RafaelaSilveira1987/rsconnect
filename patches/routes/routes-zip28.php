<?php
// PATCH DE REFERÊNCIA — não substitua seu arquivo de rotas inteiro.
// Mescle estas rotas com as rotas do ZIP 27, adaptando a assinatura do Router do projeto.

// Configuração por empresa
$router->post('/agenda-inteligente/google-settings', [SmartCalendarController::class, 'saveGoogleSettings']);

// Ações do modo VAGO
$router->post('/agenda-disponibilidade/marked/search', [SmartCalendarController::class, 'searchMarkedSlots']);
$router->post('/agenda-disponibilidade/marked/hold', [SmartCalendarController::class, 'holdMarkedSlot']);
$router->post('/agenda-disponibilidade/marked/confirm', [SmartCalendarController::class, 'confirmMarkedSlot']);
$router->post('/agenda-disponibilidade/marked/release', [SmartCalendarController::class, 'releaseMarkedSlot']);

// A rota abaixo já existe no ZIP 27. Amplie o método atual para aceitar também
// event=calendar.marked_slot.updated e source=google_free_slots/google_marked_slots.
// $router->post('/webhooks/calendar/availability', [WebhookController::class, 'calendarAvailability']);
