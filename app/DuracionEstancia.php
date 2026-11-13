<?php

namespace App;

use Illuminate\Support\Carbon;

enum DuracionEstancia: string
{
    case Indefinido = 'indefinido';
    case TresMeses = 'tres_meses';
    case SeisMeses = 'seis_meses';
    case UnAnio = 'un_anio';
    case HastaElDia = 'hasta_el_dia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Indefinido => 'Indefinido, sin fecha de fin',
            self::TresMeses => '3 meses',
            self::SeisMeses => '6 meses',
            self::UnAnio => '1 año',
            self::HastaElDia => 'Hasta un día concreto',
        };
    }

    public function fechaFin(Carbon $inicio, ?string $dia): ?string
    {
        return match ($this) {
            self::Indefinido => null,
            self::TresMeses => $inicio->copy()->addMonths(3)->toDateString(),
            self::SeisMeses => $inicio->copy()->addMonths(6)->toDateString(),
            self::UnAnio => $inicio->copy()->addYear()->toDateString(),
            self::HastaElDia => $dia,
        };
    }
}
