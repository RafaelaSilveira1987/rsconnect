<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\ExpressOnboardingData;
use App\Enums\ExpressSegment;
use App\Enums\ExpressTone;
use App\Validation\ExpressOnboardingRequest;
use App\Validation\ExpressValidationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adaptador para uma futura migração para Laravel 10/11/12.
 * NÃO é registrado no roteador nativo do RS Connect.
 */
final class ExpressAgentFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (int) ($this->user()->tenant_id ?? 0) > 0;
    }

    public function rules(): array
    {
        return [
            'business_info' => ['required','array'],
            'business_info.agent_name' => ['required','string','max:120'],
            'business_info.segment' => ['required', Rule::enum(ExpressSegment::class)],
            'business_info.tone_of_voice' => ['required', Rule::enum(ExpressTone::class)],
            'commercial_info' => ['required','array'],
            'commercial_info.pricing_strategy' => ['required',Rule::in(['fixed_price','variable_price','on_request'])],
            'commercial_info.price_value' => ['nullable','string','max:40'],
            'commercial_info.payment_methods' => ['required','array','max:5'],
            'commercial_info.payment_methods.*' => ['required','string', Rule::in(['pix','credit_card','debit_card','bank_transfer','cash'])],
            'triage_config' => ['required','array'],
            'triage_config.collect_name' => ['required'],
            'triage_config.collect_phone' => ['required'],
            'triage_config.custom_questions' => ['required','array','max:12'],
            'triage_config.custom_questions.*' => ['required','string','max:300'],
            'routing_and_schedule' => ['required','array'],
            'routing_and_schedule.timezone' => ['required','timezone'],
            'routing_and_schedule.business_hours' => ['required','array','min:1','max:21'],
            'routing_and_schedule.business_hours.*.day' => ['required','string',Rule::in(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'])],
            'routing_and_schedule.business_hours.*.start' => ['required','date_format:H:i'],
            'routing_and_schedule.business_hours.*.end' => ['required','date_format:H:i'],
            'routing_and_schedule.out_of_hours_action' => ['required',Rule::in(['capture_lead_and_promise_contact','inform_business_hours','handoff_to_human'])],
            'routing_and_schedule.default_meeting_link' => ['nullable','url','max:500'],
            'knowledge_base' => ['required','array'],
            'knowledge_base.faq' => ['required','array','max:40'],
            'knowledge_base.faq.*.question' => ['required','string','max:250'],
            'knowledge_base.faq.*.answer' => ['required','string','max:1500'],
            'capabilities' => ['required','array'],
            'capabilities.allow_rescheduling' => ['required'],
            'capabilities.escalate_to_human_on_error' => ['required'],
            'capabilities.send_meeting_link_on_confirmation' => ['required'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Laravel Rule::boolean aceitaria strings/1/0; nosso contrato permite somente JSON boolean.
        $validator->after(function (Validator $validator): void {
            try {
                ExpressOnboardingRequest::validate($this->all());
            } catch (ExpressValidationException $e) {
                foreach ($e->errors as $path => $message) $validator->errors()->add($path, $message);
            }
        });
    }

    public function toDto(): ExpressOnboardingData
    {
        return ExpressOnboardingRequest::validate($this->all());
    }
}
