<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $s): int { return strlen($s); } }
if (!function_exists('mb_substr')) { function mb_substr(string $s, int $from, ?int $len = null): string { return $len === null ? substr($s, $from) : substr($s, $from, $len); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s): string { return strtolower($s); } }
require_once __DIR__ . '/../../app/Services/AgentConversationBehaviorService.php';

use App\Services\AgentConversationBehaviorService as Behavior;

$service = new Behavior();
$profile = [
    'config' => [
        'express' => [
            'commercial' => [
                'pricing_strategy' => 'fixed_price',
                'price_value' => 'R$ 180,00',
                'payment_methods' => ['pix', 'credit_card', 'debit_card', 'cash'],
            ],
        ],
        'conversation_behavior' => [
            'payment' => [
                'enabled' => true, 'value_text' => 'R$ 200,00',
                'methods' => ['Boleto'], 'message' => 'O preço é R$ 200,00.',
            ],
        ],
    ],
];
$settings = $service->settingsFromProfile($profile);
assert(($settings['payment']['value_text'] ?? null) === 'R$ 180,00', 'Preços da Expressa prevalecem sobre legado');
assert(($settings['payment']['methods'] ?? []) === ['Pix', 'Cartão de crédito', 'Cartão de débito', 'Dinheiro']);
assert(($settings['payment']['message'] ?? '') === '', 'Texto legado não deve introduzir preço concorrente');
$prompt = $service->promptBlock($profile, 'Quanto custa e como posso pagar?');
assert(str_contains($prompt, 'R$ 180,00') && !str_contains($prompt, 'R$ 200,00'));

$legacy = $service->settingsFromProfile(['config' => ['conversation_behavior' => [
    'payment' => ['enabled'=>true,'value_text'=>'R$ 75,00','methods'=>['Pix']],
]]]);
assert(($legacy['payment']['value_text'] ?? '') === 'R$ 75,00', 'Legados sem express continuam válidos');

$variable = Behavior::paymentFromExpressCommercial([
    'pricing_strategy'=>'variable_price', 'price_value'=>'R$ 10,00', 'payment_methods'=>['pix'],
]);
assert($variable !== null && $variable['value_text'] === '', 'Preço não fixo nunca é tratado como fixo');
$bad = Behavior::paymentFromExpressCommercial([
    'pricing_strategy'=>'fixed_price','price_value'=>'preço a combinar','payment_methods'=>[],
]);
assert($bad !== null && $bad['value_text'] === '' && $bad['enabled'] === false, 'Preço inválido não é afirmado');
assert(Behavior::paymentFromExpressCommercial([]) === null);

$view = file_get_contents(__DIR__ . '/../../app/Views/agents/index.php');
$writer = file_get_contents(__DIR__ . '/../../app/Services/AgentBlueprintService.php');
assert(str_contains($view, 'Valores controlados por Meu atendimento'), 'UI não duplica editor comercial');
assert(str_contains($writer, "\$postedBehavior['payment'] = is_array"), 'Salvamento avançado mantém dados legados ao invés de escrever valor concorrente');
echo "[OK] Pagamento com fonte canônica, compatibilidade e proteção a preços inconsistentes.\n";
