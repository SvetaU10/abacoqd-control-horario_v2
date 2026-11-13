<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\AjusteSaldoVacaciones;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AjustarSaldoVacaciones
{
    public function __construct(private CupoVacaciones $cupo) {}

    public function ajustar(User $actor, User $persona, int $anio, int $saldo, int $aprobadosEsperados, int $ultimoEsperado, string $motivo, string $operacion): AjusteSaldoVacaciones
    {
        $motivo = trim($motivo);
        if ($anio < 2000 || $anio > 2100 || $saldo < 0 || $saldo > 366 || $aprobadosEsperados < 0 || $ultimoEsperado < 0 || $motivo === '' || mb_strlen($motivo) > 500 || ! Str::isUuid($operacion)) {
            throw new ReglaJornada('Revisa el año, saldo y motivo del ajuste.');
        }

        return DB::transaction(function () use ($actor, $persona, $anio, $saldo, $aprobadosEsperados, $ultimoEsperado, $motivo, $operacion): AjusteSaldoVacaciones {
            $responsable = User::query()->findOrFail($actor->id);
            $actual = User::query()->lockForUpdate()->findOrFail($persona->id);
            if (! $responsable->active || ! $responsable->esJefa() || ! $actual->registraJornada()) {
                throw new ReglaJornada('Solo un responsable activo puede ajustar el saldo laboral.');
            }
            if (AjusteSaldoVacaciones::query()->where('operacion', $operacion)->exists()) {
                throw new ReglaJornada('Esta operación ya se ha registrado.');
            }
            $ultimo = (int) (AjusteSaldoVacaciones::query()->where('user_id', $actual->id)->where('anio', $anio)->max('id') ?? 0);
            if ($ultimo !== $ultimoEsperado) {
                throw new ReglaJornada('El saldo ha cambiado. Revisa la información antes de guardar.');
            }
            $this->cupo->asegurarCoberturaParaSaldo($actual, $anio);
            $aprobados = $this->cupo->aprobados($actual, $anio);
            if ($aprobados !== $aprobadosEsperados) {
                throw new ReglaJornada('Las vacaciones aprobadas han cambiado. Revisa el saldo antes de guardar.');
            }

            return AjusteSaldoVacaciones::create(['user_id' => $actual->id, 'actor_id' => $responsable->id, 'anio' => $anio,
                'saldo_deseado' => $saldo, 'aprobados_al_ajuste' => $aprobados, 'motivo' => $motivo,
                'operacion' => $operacion, 'created_at' => now()]);
        }, 3);
    }
}
