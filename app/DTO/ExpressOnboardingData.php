<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\ExpressSegment;
use App\Enums\ExpressTone;

/** Entrada já validada pelo ExpressOnboardingRequest. */
final readonly class ExpressOnboardingData
{
    /** @param list<string> $paymentMethods
     *  @param list<string> $customQuestions
     *  @param list<array{day:string,start:string,end:string}> $businessHours
     *  @param list<array{question:string,answer:string}> $faq
     */
    public function __construct(
        public string $agentName,
        public ExpressSegment $segment,
        public ExpressTone $tone,
        public string $pricingStrategy,
        public ?string $priceValue,
        public array $paymentMethods,
        public bool $collectName,
        public bool $collectPhone,
        public array $customQuestions,
        public string $timezone,
        public array $businessHours,
        public string $outOfHoursAction,
        public ?string $defaultMeetingLink,
        public array $faq,
        public bool $allowRescheduling,
        public bool $escalateToHumanOnError,
        public bool $sendMeetingLinkOnConfirmation,
    ) {}
}
