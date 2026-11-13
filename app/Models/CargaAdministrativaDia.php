<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CargaAdministrativaDia extends Model
{
    protected $table = 'carga_administrativa_dias';

    protected $fillable = ['lote_id', 'user_id', 'fecha', 'clase', 'estado', 'clave_activa',
        'morning_start', 'morning_end', 'afternoon_start', 'afternoon_end', 'minutos', 'jornada_sustituta_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'minutos' => 'integer'];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(CargaAdministrativaLote::class, 'lote_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(CargaAdministrativaEvento::class, 'dia_id');
    }
}
