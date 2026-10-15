<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Regra determinística e independente de LLM para ações sobre agendamentos.
 * Não persiste dados. O executor deve revalidar estado/capacidade no banco.
 */
final class AppointmentOperationPolicyService
{
    private const ACTIVE = ['pre_scheduled', 'awaiting_approval', 'scheduled', 'confirmed'];
    private const FINAL = ['cancelled', 'rejected', 'completed', 'no_show'];

    /** @param array<string,mixed> $appointment @param array<string,mixed> $settings
     *  @return array{allowed:bool,code:string,message:string} */
    public function evaluate(string $operation, int $tenantId, array $appointment, array $settings = [], bool $reschedulingEnabled = true): array
    {
        if ($tenantId < 1 || (int) ($appointment['id'] ?? 0) < 1
            || (int) ($appointment['tenant_id'] ?? 0) !== $tenantId) {
            return $this->deny('appointment_scope_invalid', 'Não foi possível identificar o agendamento desta empresa.');
        }
        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if (!in_array($status, self::ACTIVE, true)) {
            return $this->deny('appointment_not_active', 'Esse compromisso não está ativo para a operação solicitada.');
        }
        if ($operation === 'consult') {
            return $this->allow();
        }
        if ($operation === 'request_cancel') {
            return $this->allow(); // solicitação, NUNCA cancelamento efetivo
        }
        if ($operation === 'request_reschedule') {
            return $reschedulingEnabled
                ? $this->allow()
                : $this->deny('rescheduling_disabled', 'A remarcação automática não está habilitada. Nossa equipe pode orientar você.');
        }
        if ($operation === 'confirm_ai') {
            if ($status !== 'awaiting_approval' || (int) ($appointment['is_pre_schedule'] ?? 0) !== 1) {
                return $this->deny('invalid_confirmation_state', 'O pedido não está aguardando confirmação.');
            }
            if (empty($settings['enabled'])) {
                return $this->deny('pre_schedule_disabled', 'A confirmação automática não está disponível.');
            }
            if (!empty($settings['require_human_approval'])) {
                return $this->deny('human_approval_required', 'A confirmação depende da validação da equipe.');
            }
            if (empty($settings['ai_can_confirm'])) {
                return $this->deny('automatic_confirmation_disabled', 'A confirmação depende da validação da equipe.');
            }
            if (trim((string) ($appointment['starts_at'] ?? '')) === ''
                || trim((string) ($appointment['ends_at'] ?? '')) === '') {
                return $this->deny('appointment_interval_missing', 'O horário selecionado não possui início e fim válidos.');
            }
            return $this->allow();
        }
        // Não conceder operações de escrita diretamente a partir de conversa/IA.
        return $this->deny('operation_not_automated', 'Essa operação precisa ser validada pela equipe.');
    }

    /**
     * Valida transições iniciadas por um usuário autorizado no painel.
     * O controller continua responsável por permissão, disponibilidade e sincronização.
     * @return array{allowed:bool,code:string,message:string}
     */
    public function validateManualTransition(string $current, string $target): array
    {
        $current = strtolower(trim($current));
        $target = strtolower(trim($target));
        if ($current === $target) {
            return $this->deny('no_state_change', 'O compromisso já está nesse estado.');
        }
        if (in_array($current, self::FINAL, true)) {
            return $this->deny('terminal_state', 'Um compromisso encerrado não pode ser reativado por uma troca simples de status.');
        }
        $allowed = [
            'pre_scheduled' => ['awaiting_approval', 'scheduled', 'confirmed', 'rejected', 'cancelled'],
            'awaiting_approval' => ['pre_scheduled', 'scheduled', 'confirmed', 'rejected', 'cancelled'],
            'scheduled' => ['confirmed', 'completed', 'cancelled', 'rescheduled', 'no_show', 'rejected'],
            'confirmed' => ['completed', 'cancelled', 'rescheduled', 'no_show'],
            'rescheduled' => [],
        ];
        return in_array($target, $allowed[$current] ?? [], true)
            ? $this->allow()
            : $this->deny('invalid_state_transition', 'Essa mudança de status não é permitida para o estado atual.');
    }

    /** @return array{allowed:bool,code:string,message:string} */
    private function allow(): array
    {
        return ['allowed' => true, 'code' => 'allowed', 'message' => ''];
    }

    /** @return array{allowed:bool,code:string,message:string} */
    private function deny(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message];
    }
}
