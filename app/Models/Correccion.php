<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Correccion extends Model
{
    protected $table = 'correcciones';

    public const MOTIVOS = [
        'hora' => 'Registrar las horas',
        'olvido' => 'Olvidé fichar',
        'pausa' => 'La pausa fue otra',
        'fichar_pausa' => 'Fichar pausa',
        'antes' => 'Ya estaba trabajando',
    ];

    protected $fillable = [
        'tramo_id',
        'user_id',
        'field',
        'previous_value',
        'new_value',
        'reason',
        'note',
    ];

    public function tramo(): BelongsTo
    {
        return $this->belongsTo(Tramo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function motivoTexto(): string
    {
        return self::MOTIVOS[$this->reason] ?? $this->reason;
    }

    protected function casts(): array
    {
        return [
            'previous_value' => 'datetime',
            'new_value' => 'datetime',
        ];
    }
}
