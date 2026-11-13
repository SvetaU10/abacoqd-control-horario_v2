<?php

namespace App;

enum TipoJornada: string
{
    case Partida = 'partida';
    case Continua = 'continua';
    case Intensiva = 'intensiva';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Partida => 'Jornada partida',
            self::Continua => 'Jornada continua',
            self::Intensiva => 'Jornada intensiva',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Partida => 'Mañana y tarde, con pausa de comida.',
            self::Continua => 'Un solo tramo, sin pausa de comida.',
            self::Intensiva => 'Un tramo más corto, habitual en verano o viernes.',
        };
    }

    /**
     * @return array{morning_start: string, morning_end: string, afternoon_start: string, afternoon_end: string}
     */
    public function horas(): array
    {
        return match ($this) {
            self::Partida => [
                'morning_start' => '09:00',
                'morning_end' => '14:00',
                'afternoon_start' => '15:00',
                'afternoon_end' => '18:00',
            ],
            self::Continua => [
                'morning_start' => '08:00',
                'morning_end' => '15:00',
                'afternoon_start' => '15:00',
                'afternoon_end' => '15:00',
            ],
            self::Intensiva => [
                'morning_start' => '08:00',
                'morning_end' => '14:00',
                'afternoon_start' => '14:00',
                'afternoon_end' => '14:00',
            ],
        };
    }

    public function textoHorario(): string
    {
        $horas = $this->horas();

        if ($horas['afternoon_start'] === $horas['afternoon_end']) {
            return $horas['morning_start'].'–'.$horas['morning_end'];
        }

        return $horas['morning_start'].'–'.$horas['morning_end']
            .' y '.$horas['afternoon_start'].'–'.$horas['afternoon_end'];
    }
}
