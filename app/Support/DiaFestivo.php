<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class DiaFestivo
{
    public function __construct(
        public readonly Carbon $holiday_date,
        public readonly string $name,
        public readonly string $ambito,
    ) {}

    public function etiqueta(): string
    {
        return match ($this->ambito) {
            'estado' => 'Festivo Nacional',
            'comunidad' => 'Festivo Autonómico',
            'provincia' => 'Festivo Provincial',
            'insular' => 'Festivo Insular',
            default => 'Festivo Local',
        };
    }
}
