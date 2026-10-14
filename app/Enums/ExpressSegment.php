<?php

declare(strict_types=1);

namespace App\Enums;

enum ExpressSegment: string
{
    case PsychologyClinic = 'psychology_clinic';
    case HealthClinic = 'health_clinic';
    case BeautySalon = 'beauty_salon';
    case GeneralServices = 'general_services';
    case RealEstate = 'real_estate';

    /** Legacy RS Connect business_niches.code: does not introduce a second catalog. */
    public function nicheCode(): string
    {
        return match ($this) {
            self::PsychologyClinic => 'psychology',
            self::HealthClinic => 'health_clinic',
            self::BeautySalon => 'beauty',
            self::GeneralServices, self::RealEstate => 'services',
        };
    }
}
