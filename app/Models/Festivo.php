<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Festivo extends Model
{
    protected $fillable = [
        'holiday_date',
        'municipality',
        'name',
        'ambito',
        'community',
        'centro_id',
    ];

    public function centro(): BelongsTo
    {
        return $this->belongsTo(Centro::class);
    }

    public function etiqueta(): string
    {
        return match ($this->ambito) {
            'estado' => 'Festivo Nacional',
            'comunidad' => 'Festivo Autonómico',
            'pueblo' => 'Festivo Local',
            default => $this->municipality === '' ? 'Todos los centros' : 'Festivo Local',
        };
    }

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }
}
