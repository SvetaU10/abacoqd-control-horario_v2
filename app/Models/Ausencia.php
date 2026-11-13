<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ausencia extends Model
{
    protected $fillable = [
        'user_id',
        'absence_date',
        'tipo',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tipoTexto(): string
    {
        return $this->tipo === 'permiso' ? 'Permiso' : 'Vacaciones';
    }

    protected function casts(): array
    {
        return [
            'absence_date' => 'date',
        ];
    }
}
