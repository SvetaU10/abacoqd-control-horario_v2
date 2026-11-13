<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jornada extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'closed_late',
        'sigue',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tramos(): HasMany
    {
        return $this->hasMany(Tramo::class)->orderBy('started_at')->orderBy('id');
    }

    public function tramoAbierto(): ?Tramo
    {
        return $this->tramos->first(fn (Tramo $tramo) => $tramo->ended_at === null);
    }

    public function estaCerrada(): bool
    {
        return $this->tramoAbierto() === null && $this->tramos->isNotEmpty();
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'closed_late' => 'boolean',
            'sigue' => 'boolean',
        ];
    }
}
