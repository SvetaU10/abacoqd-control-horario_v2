<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Centro extends Model
{
    protected $fillable = [
        'name',
        'municipality',
        'community',
        'municipio_ine',
        'province',
        'province_code',
        'comunidad_codigo',
        'minimo_presencia',
    ];

    protected function casts(): array
    {
        return [
            'minimo_presencia' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function festivos(): HasMany
    {
        return $this->hasMany(Festivo::class);
    }

    public function municipio(): string
    {
        return $this->municipality;
    }

    public function autonomia(): string
    {
        return $this->community;
    }

    public function comunidad(): string
    {
        return $this->autonomia();
    }

    public function etiqueta(): string
    {
        return $this->name.' · '.$this->municipality;
    }
}
