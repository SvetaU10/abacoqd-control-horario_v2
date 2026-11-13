<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un aviso entre el responsable y una persona del equipo. No declara horas.
 * Si destinatario_id es null, va dirigida a el responsable.
 */
class Incidencia extends Model
{
    public const PENDIENTE = 'pendiente';

    public const EN_CURSO = 'en_curso';

    public const RESUELTA = 'resuelta';

    public const ABIERTA = 'abierta';

    public const CUERPO_MINIMO = 10;

    public const CUERPO_MAXIMO = 500;

    /**
     * @var array<string, string>
     */
    public const ESTADOS = [
        self::PENDIENTE => 'Pendiente',
        self::EN_CURSO => 'En curso',
        self::RESUELTA => 'Resuelta',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    protected $fillable = [
        'user_id',
        'destinatario_id',
        'cuerpo',
        'estado',
        'resuelta_at',
        'vista_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resuelta_at' => 'datetime',
            'vista_at' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(IncidenciaMensaje::class)->oldest('id');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE || $this->estado === self::ABIERTA;
    }

    public function estaEnCurso(): bool
    {
        return $this->estado === self::EN_CURSO;
    }

    public function estaResuelta(): bool
    {
        return $this->estado === self::RESUELTA;
    }

    public function estadoTexto(): string
    {
        if ($this->estaPendiente()) {
            return self::ESTADOS[self::PENDIENTE];
        }

        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function estadoChip(): string
    {
        return $this->estaPendiente() ? self::PENDIENTE : $this->estado;
    }

    public function estadoLista(): string
    {
        return $this->estaPendiente() ? self::PENDIENTE : $this->estado;
    }

    public function pasarAEnCurso(): void
    {
        if (! $this->estaPendiente()) {
            return;
        }

        $this->update(['estado' => self::EN_CURSO]);
    }

    /**
     * @return array<int, string>
     */
    public static function reglasDeMotivo(): array
    {
        return ['required', 'string', 'min:'.self::CUERPO_MINIMO, 'max:'.self::CUERPO_MAXIMO];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajesDeMotivo(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo de la incidencia.',
            'motivo.min' => 'El motivo tiene que tener al menos '.self::CUERPO_MINIMO.' caracteres.',
            'motivo.max' => 'El motivo puede tener hasta '.self::CUERPO_MAXIMO.' caracteres.',
        ];
    }
}
