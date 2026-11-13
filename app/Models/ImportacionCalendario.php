<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacionCalendario extends Model
{
    protected $table = 'importaciones_calendario';

    protected $fillable = [
        'centro_id', 'anio', 'municipio_ine', 'municipio', 'provincia',
        'comunidad', 'comunidad_codigo', 'estado', 'mensaje', 'festivos',
        'fuentes', 'hash', 'clave_activa', 'consultado_at', 'activado_at',
        'consultado_por', 'activado_por', 'vigente_desde',
    ];

    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class);
    }

    public function estaActivo(): bool
    {
        return $this->clave_activa !== null;
    }

    public function puedeConfirmarse(): bool
    {
        return $this->estado === 'pendiente_revision';
    }

    public function etiqueta(): string
    {
        return match ($this->estado) {
            'cargado' => 'Cargado',
            'actualizado' => 'Actualizado',
            'sin_cambios' => 'Sin cambios',
            'parcial' => 'Parcial',
            'no_disponible' => 'Sin datos disponibles',
            'pendiente_revision' => 'Pendiente de revisión',
            'conflicto' => 'Datos discrepantes',
            'configuracion_modificada' => 'Pendiente de cargar',
            'contexto_manual' => 'Contexto de festivos manuales',
            default => 'Error de consulta',
        };
    }

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'vigente_desde' => 'date',
            'festivos' => 'array',
            'fuentes' => 'array',
            'consultado_at' => 'datetime',
            'activado_at' => 'datetime',
        ];
    }
}
