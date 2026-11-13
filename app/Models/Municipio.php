<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Municipio extends Model
{
    protected $primaryKey = 'ine';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'ine', 'nombre', 'provincia', 'provincia_codigo', 'comunidad',
        'comunidad_codigo', 'nombre_normalizado', 'busqueda',
    ];
}
