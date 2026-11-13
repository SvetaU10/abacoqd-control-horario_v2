<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VigenciaCalendario extends Model
{
    protected $table = 'vigencias_calendario';

    protected $fillable = ['centro_id', 'anio', 'importacion_id', 'desde', 'hasta', 'cancelado_at'];

    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class);
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ImportacionCalendario::class, 'importacion_id');
    }

    protected function casts(): array
    {
        return ['anio' => 'integer', 'desde' => 'date', 'hasta' => 'date', 'cancelado_at' => 'datetime'];
    }
}
