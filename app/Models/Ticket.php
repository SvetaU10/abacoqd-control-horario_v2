<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un problema que una persona del equipo cuenta al administrador técnico.
 * Solo lo ven quien lo abrió y el técnico.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const ABIERTO = 'abierto';

    public const EN_CURSO = 'en_curso';

    public const RESUELTO = 'resuelto';

    /**
     * @var array<string, string>
     */
    public const ESTADOS = [
        self::ABIERTO => 'Abierto',
        self::EN_CURSO => 'En curso',
        self::RESUELTO => 'Resuelto',
    ];

    protected $fillable = [
        'user_id',
        'asunto',
        'descripcion',
        'pagina',
        'estado',
        'resuelto_at',
        'respuesta_vista_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resuelto_at' => 'datetime',
            'respuesta_vista_at' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(TicketMensaje::class)->oldest('id');
    }

    public function etiquetaDeEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function estaResuelto(): bool
    {
        return $this->estado === self::RESUELTO;
    }

    /**
     * Tickets del trabajador con una respuesta del técnico que todavía no ha abierto.
     */
    public function scopeConRespuestaNueva(Builder $query, User $usuario): void
    {
        $query->whereBelongsTo($usuario, 'autor')
            ->whereNull('respuesta_vista_at')
            ->whereHas('mensajes', fn (Builder $mensajes) => $mensajes->where('user_id', '!=', $usuario->id));
    }
}
