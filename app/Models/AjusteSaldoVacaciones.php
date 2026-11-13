<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AjusteSaldoVacaciones extends Model
{
    protected $table = 'ajustes_saldo_vacaciones';

    public $timestamps = false;

    protected $fillable = ['user_id', 'actor_id', 'anio', 'saldo_deseado', 'aprobados_al_ajuste', 'motivo', 'operacion', 'created_at'];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'saldo_deseado' => 'integer', 'aprobados_al_ajuste' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los ajustes históricos no se modifican.'));
        static::deleting(fn () => throw new LogicException('Los ajustes históricos no se eliminan.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
