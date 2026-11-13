<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudVacaciones extends Model
{
    public const PENDIENTE = 'pendiente';

    public const APROBADA = 'aprobada';

    public const RECHAZADA = 'rechazada';

    protected $table = 'solicitudes_vacaciones';

    protected $fillable = [
        'user_id',
        'starts_on',
        'ends_on',
        'nota',
        'estado',
        'decidida_por',
        'decidida_at',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }

    public function estadoTexto(): string
    {
        return match ($this->estado) {
            self::APROBADA => 'Aprobada',
            self::RECHAZADA => 'Rechazada',
            default => 'Pendiente',
        };
    }

    public function periodoTexto(): string
    {
        $desde = $this->starts_on->format('d/m/Y');

        if ($this->starts_on->toDateString() === $this->ends_on->toDateString()) {
            return 'El '.$desde;
        }

        return 'Del '.$desde.' al '.$this->ends_on->format('d/m/Y');
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decidida_at' => 'datetime',
        ];
    }
}
