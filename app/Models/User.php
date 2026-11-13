<?php

namespace App\Models;

use App\Mail\RecuperarClave;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'registra_jornada', 'starts_on', 'ends_on', 'baja_on', 'municipality', 'centro_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES_JEFE = ['jefe', 'jefa'];

    protected $attributes = ['role' => 'trabajador', 'registra_jornada' => true];

    protected function role(): Attribute
    {
        return Attribute::make(set: fn (string $role): string => match ($role) {
            'jefa' => 'jefe',
            'trabajadora' => 'trabajador',
            default => $role,
        });
    }

    public function scopeConJornada(Builder $query): Builder
    {
        return $query->where('registra_jornada', true);
    }

    public function scopeJefes(Builder $query): Builder
    {
        return $query->whereIn('role', self::ROLES_JEFE);
    }

    /** Personas con acceso ese día: activas y sin baja ya cumplida. */
    public function scopeConAccesoEl(Builder $query, Carbon $fecha): Builder
    {
        return $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('baja_on')->orWhereDate('baja_on', '>=', $fecha->toDateString()));
    }

    /** Personas dadas de baja ese día: acceso cerrado o baja ya cumplida. */
    public function scopeDeBajaEl(Builder $query, Carbon $fecha): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('active', false)
            ->orWhere(fn (Builder $b) => $b->whereNotNull('baja_on')->whereDate('baja_on', '<', $fecha->toDateString())));
    }

    /**
     * Una baja con fecha futura deja el acceso abierto hasta el final de ese día.
     * A partir del día siguiente la cuenta queda cerrada como cualquier otra baja.
     */
    public static function cerrarAccesosVencidos(Carbon $hoy): int
    {
        $vencidas = self::query()->where('active', true)->whereNotNull('baja_on')
            ->whereDate('baja_on', '<', $hoy->toDateString());

        return $vencidas->exists() ? $vencidas->update(['active' => false]) : 0;
    }

    public function tieneAccesoEl(Carbon $fecha): bool
    {
        if (! $this->active) {
            return false;
        }

        return ! $this->baja_on || $fecha->toDateString() <= $this->baja_on->toDateString();
    }

    public function estaDeBajaEl(Carbon $fecha): bool
    {
        return ! $this->tieneAccesoEl($fecha);
    }

    public function tieneBajaProgramadaEl(Carbon $fecha): bool
    {
        return $this->active && $this->baja_on && $fecha->toDateString() <= $this->baja_on->toDateString();
    }

    /**
     * Hasta el final del último día la baja se anula sin dejar hueco: tanto una
     * baja prevista como una baja con fecha de hoy que ya cerró el acceso.
     */
    public function bajaAnulableEl(Carbon $fecha): bool
    {
        return $this->baja_on !== null && $fecha->toDateString() <= $this->baja_on->toDateString();
    }

    /** Cuenta cerrada antes de que la baja tuviera fecha: sus días siguen contando como previstos. */
    public function bajaSinFecha(): bool
    {
        return ! $this->active && $this->baja_on === null;
    }

    public function registraJornada(): bool
    {
        return $this->registra_jornada;
    }

    public function paginaInicio(): string
    {
        return $this->esTecnico() && ! $this->registraJornada() ? 'tecnico.index' : 'jornada';
    }

    /**
     * Gestiona el equipo y, además, hace las funciones de dirección: estado de
     * todas las personas, resumen del mes y consulta de registros con motivo.
     */
    public function esJefa(): bool
    {
        return in_array($this->role, self::ROLES_JEFE, true);
    }

    /**
     * El administrador técnico ficha como cualquier persona del equipo y, además,
     * gestiona las actualizaciones y comprueba la base de datos activa.
     */
    public function esTecnico(): bool
    {
        return $this->role === 'tecnico';
    }

    public function sendPasswordResetNotification($token): void
    {
        if ($this->correo_habilitado) {
            Mail::to($this->email)->send(new RecuperarClave($this, $token));
        }
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(Horario::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Centro::class, 'centro_id');
    }

    public function asignacionesCentro(): HasMany
    {
        return $this->hasMany(AsignacionCentro::class)->orderBy('desde')->orderBy('id');
    }

    public function sedeEn(Carbon $fecha): ?Centro
    {
        $this->loadMissing('asignacionesCentro.centro');
        if ($this->asignacionesCentro->isEmpty()) {
            return $this->sede;
        }
        $dia = $fecha->copy()->startOfDay();

        return $this->asignacionesCentro->first(fn (AsignacionCentro $asignacion): bool => $asignacion->cancelado_at === null
            && ($asignacion->desde === null || $asignacion->desde->lte($dia))
            && ($asignacion->hasta === null || $asignacion->hasta->gte($dia)))?->centro;
    }

    public function jornadas(): HasMany
    {
        return $this->hasMany(Jornada::class);
    }

    public function ausencias(): HasMany
    {
        return $this->hasMany(Ausencia::class);
    }

    public function solicitudesVacaciones(): HasMany
    {
        return $this->hasMany(SolicitudVacaciones::class);
    }

    public function explicaciones(): HasMany
    {
        return $this->hasMany(Explicacion::class);
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class);
    }

    public function interrupcionesPlantilla(): HasMany
    {
        return $this->hasMany(InterrupcionPlantilla::class)->orderBy('desde')->orderBy('id');
    }

    public function horarioEn(Carbon $fecha): ?Horario
    {
        return $this->horarios()
            ->whereDate('effective_from', '<=', $fecha->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    public function estaEnPlantillaEl(Carbon $fecha): bool
    {
        if (! $this->registraJornada()) {
            return false;
        }

        $dia = $fecha->toDateString();
        if (! $this->starts_on || $dia < $this->starts_on->toDateString()) {
            return false;
        }

        if ($this->ends_on && $dia > $this->ends_on->toDateString()) {
            return false;
        }
        if ($this->baja_on && $dia > $this->baja_on->toDateString()) {
            return false;
        }

        $this->loadMissing('interrupcionesPlantilla');

        return ! $this->interrupcionesPlantilla->contains(fn (InterrupcionPlantilla $hueco): bool => $hueco->cubre($dia));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'registra_jornada' => 'boolean',
            'correo_habilitado' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'baja_on' => 'date',
        ];
    }
}
