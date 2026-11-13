<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FalloComun extends Model
{
    protected $table = 'fallos_comunes';

    protected $fillable = [
        'falla_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'falla_date' => 'date',
        ];
    }
}
