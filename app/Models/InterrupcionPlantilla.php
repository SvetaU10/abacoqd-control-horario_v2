<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Días entre una baja y la reincorporación: la persona no estaba en plantilla
 * y esos días no cuentan como previstos. Los días anteriores conservan su registro.
 */
class InterrupcionPlantilla extends Model
{
    protected $table = 'interrupciones_plantilla';

    protected $fillable = ['user_id', 'desde', 'hasta', 'evento_cuenta_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cubre(string $dia): bool
    {
        return $dia >= $this->desde->toDateString() && $dia <= $this->hasta->toDateString();
    }

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }
}
