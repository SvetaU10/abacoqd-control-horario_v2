<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CargaAdministrativaLote extends Model
{
    protected $table = 'carga_administrativa_lotes';

    protected $fillable = ['autor_id', 'desde', 'hasta', 'fecha_corte', 'participantes', 'horario', 'referencias', 'exclusiones', 'vacaciones_pendientes_incluidas', 'plan_hash', 'motivo'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'fecha_corte' => 'date', 'participantes' => 'array', 'horario' => 'array', 'referencias' => 'array', 'exclusiones' => 'array', 'vacaciones_pendientes_incluidas' => 'integer'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function dias(): HasMany
    {
        return $this->hasMany(CargaAdministrativaDia::class, 'lote_id');
    }
}
