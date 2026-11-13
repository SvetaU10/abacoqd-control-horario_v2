<?php

namespace App\Models;

use App\TipoJornada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Horario extends Model
{
    protected $fillable = [
        'user_id',
        'tipo',
        'morning_start',
        'morning_end',
        'afternoon_start',
        'afternoon_end',
        'effective_from',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function texto(): string
    {
        if ($this->sinTarde()) {
            return $this->corta($this->morning_start).'–'.$this->corta($this->morning_end);
        }

        return $this->corta($this->morning_start).'–'.$this->corta($this->morning_end)
            .' y '.$this->corta($this->afternoon_start).'–'.$this->corta($this->afternoon_end);
    }

    public function comidaTexto(): string
    {
        return $this->corta($this->morning_end).' a '.$this->corta($this->afternoon_start);
    }

    public function sinTarde(): bool
    {
        return $this->corta($this->afternoon_start) === $this->corta($this->afternoon_end);
    }

    public function tipoJornada(): ?TipoJornada
    {
        return TipoJornada::tryFrom((string) $this->tipo);
    }

    public function corta(string $hora): string
    {
        return substr($hora, 0, 5);
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }
}
