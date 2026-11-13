<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Aviso extends Model
{
    protected $fillable = [
        'user_id',
        'tipo',
        'work_date',
        'responded_at',
        'cuenta',
        'exclusion',
        'correo_enviado_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'responded_at' => 'datetime',
            'correo_enviado_at' => 'datetime',
            'cuenta' => 'boolean',
        ];
    }
}
