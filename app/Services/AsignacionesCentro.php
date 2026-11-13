<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\AsignacionCentro;
use App\Models\Centro;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AsignacionesCentro
{
    public function __construct(private VigenciasCalendario $vigencias) {}

    public function cambiar(User $persona, Centro $centro, User $autor, Carbon $desde): void
    {
        $desde = $desde->copy()->startOfDay();
        if (! $desde->isToday()) {
            throw new ReglaJornada('El cambio de sede se aplica desde hoy. Los días anteriores se conservan.');
        }
        DB::transaction(function () use ($persona, $centro, $autor, $desde): void {
            $actual = User::query()->whereKey($persona->id)->lockForUpdate()->firstOrFail();
            if ($actual->centro_id === $centro->id) {
                return;
            }
            if ($actual->sede) {
                $this->vigencias->conservarManuales($actual->sede);
            }
            if (! $actual->asignacionesCentro()->exists() && $actual->centro_id !== null) {
                AsignacionCentro::query()->create(['user_id' => $actual->id, 'centro_id' => $actual->centro_id]);
            }
            $afectadas = $actual->asignacionesCentro()->whereNull('cancelado_at')
                ->where(fn ($consulta) => $consulta->whereNull('hasta')->orWhereDate('hasta', '>=', $desde->toDateString()))
                ->lockForUpdate()->get();
            foreach ($afectadas as $asignacion) {
                $asignacion->update(['cancelado_at' => now()]);
                if ($asignacion->desde === null || $asignacion->desde->lt($desde)) {
                    AsignacionCentro::query()->create([
                        'user_id' => $actual->id, 'centro_id' => $asignacion->centro_id,
                        'desde' => $asignacion->desde?->toDateString(), 'hasta' => $desde->copy()->subDay()->toDateString(),
                        'creado_por' => $autor->id,
                    ]);
                }
            }
            AsignacionCentro::query()->create([
                'user_id' => $actual->id, 'centro_id' => $centro->id, 'desde' => $desde->toDateString(), 'creado_por' => $autor->id,
            ]);
            $actual->update(['centro_id' => $centro->id, 'municipality' => $centro->municipality]);
        }, 3);
        $persona->refresh()->unsetRelation('sede')->unsetRelation('asignacionesCentro');
    }
}
