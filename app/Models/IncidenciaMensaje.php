<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidenciaMensaje extends Model
{
    protected $table = 'incidencia_mensajes';

    protected $fillable = [
        'incidencia_id',
        'user_id',
        'cuerpo',
    ];

    public function incidencia(): BelongsTo
    {
        return $this->belongsTo(Incidencia::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
