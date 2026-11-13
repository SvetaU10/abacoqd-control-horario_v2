<?php

namespace App\Models;

use Database\Factories\AccionTecnicaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que hace el administrador técnico sobre el sistema (copias, actualizaciones).
 * No guarda datos de las personas del equipo.
 */
class AccionTecnica extends Model
{
    /** @use HasFactory<AccionTecnicaFactory> */
    use HasFactory;

    public const COPIA = 'copia';

    public const ACTUALIZACION = 'actualizacion';

    public const RESTAURACION = 'restauracion';

    protected $table = 'acciones_tecnicas';

    protected $fillable = [
        'user_id',
        'accion',
        'detalle',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
