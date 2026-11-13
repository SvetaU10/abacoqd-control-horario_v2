<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CargaAdministrativaEvento extends Model
{
    public $timestamps = false;

    protected $table = 'carga_administrativa_eventos';

    protected $fillable = ['dia_id', 'actor_id', 'tipo', 'motivo', 'detalles', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los eventos de carga no se modifican.'));
        static::deleting(fn () => throw new LogicException('Los eventos de carga no se eliminan.'));
    }

    protected function casts(): array
    {
        return ['detalles' => 'array', 'created_at' => 'datetime'];
    }
}
