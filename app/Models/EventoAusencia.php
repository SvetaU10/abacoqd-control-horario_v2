<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EventoAusencia extends Model
{
    public $timestamps = false;

    protected $table = 'eventos_ausencias';

    protected $fillable = ['user_id', 'actor_id', 'ausencia_original_id', 'absence_date', 'tipo',
        'ausencia_creada_at', 'motivo', 'carga_dia_id', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de ausencias no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de ausencias no se elimina.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['absence_date' => 'date', 'ausencia_creada_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
