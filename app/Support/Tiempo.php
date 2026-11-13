<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Tiempo
{
    public static function texto(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        if ($horas === 0) {
            return $resto.' min';
        }

        return $horas.' h '.$resto.' min';
    }

    public static function corto(int $minutos): string
    {
        return intdiv($minutos, 60).' h '.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function hora(Carbon $momento): string
    {
        return $momento->timezone(config('app.timezone'))->format('H:i');
    }

    public static function fecha(Carbon $momento): string
    {
        return $momento->timezone(config('app.timezone'))->locale('es')->isoFormat('dddd D [de] MMMM');
    }
}
