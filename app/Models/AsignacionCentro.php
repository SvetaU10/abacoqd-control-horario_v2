<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionCentro extends Model
{
    protected $table = 'asignaciones_centro';

    protected $fillable = ['user_id', 'centro_id', 'desde', 'hasta', 'cancelado_at', 'creado_por'];

    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class);
    }

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'cancelado_at' => 'datetime'];
    }
}
