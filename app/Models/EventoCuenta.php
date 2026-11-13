<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Traza de la cuenta laboral: baja, reincorporación y edición del alta.
 * Cada evento guarda el estado anterior y el posterior, el motivo y quién lo hizo.
 */
class EventoCuenta extends Model
{
    public const BAJA = 'baja';

    public const REINCORPORACION = 'reincorporacion';

    public const EDICION = 'edicion';

    public $timestamps = false;

    protected $table = 'eventos_cuenta';

    protected $fillable = ['user_id', 'actor_id', 'tipo', 'detalles', 'motivo', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de la cuenta no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de la cuenta no se elimina.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function titulo(): string
    {
        return match ($this->tipo) {
            self::BAJA => ($this->detalles['antes']['active'] ?? true) ? 'Baja' : 'Fecha de baja registrada',
            self::REINCORPORACION => match (true) {
                ! ($this->detalles['anula_baja_programada'] ?? false) => 'Reincorporación',
                $this->detalles['antes']['active'] ?? true => 'Baja prevista anulada',
                default => 'Baja anulada',
            },
            self::EDICION => 'Edición del alta',
            default => 'Cambio en la cuenta',
        };
    }

    /** @return list<string> */
    public function cambios(): array
    {
        $antes = $this->detalles['antes'] ?? [];
        $despues = $this->detalles['despues'] ?? [];

        if ($this->tipo === self::BAJA) {
            $lineas = ['Último día en la empresa: '.self::fecha($despues['baja_on'] ?? null).'.'];
            if (! ($antes['active'] ?? true)) {
                $lineas[] = 'La cuenta ya estaba cerrada sin fecha: los días siguientes dejan de contar como previstos.';
            }

            return $lineas;
        }

        if ($this->tipo === self::REINCORPORACION) {
            if ($this->detalles['anula_baja_programada'] ?? false) {
                return ($antes['active'] ?? true)
                    ? ['Se anula la baja prevista para el '.self::fecha($antes['baja_on'] ?? null).'.']
                    : ['Se anula la baja del '.self::fecha($antes['baja_on'] ?? null).': vuelve a tener acceso.'];
            }
            $lineas = ['Vuelve el '.self::fecha($this->detalles['reincorporacion_on'] ?? null).'.'];
            $hueco = $this->detalles['interrupcion'] ?? null;
            if (is_array($hueco)) {
                $lineas[] = 'Sin contrato del '.self::fecha($hueco['desde'] ?? null).' al '.self::fecha($hueco['hasta'] ?? null).': esos días no cuentan como previstos.';
            } elseif (($antes['baja_on'] ?? null) === null) {
                $lineas[] = 'La baja no tenía fecha registrada: los días anteriores se quedan como estaban.';
            }

            return $lineas;
        }

        $lineas = [];
        if (($antes['name'] ?? null) !== ($despues['name'] ?? null)) {
            $lineas[] = 'Nombre: '.($antes['name'] ?? '').' → '.($despues['name'] ?? '').'.';
        }
        if (($antes['ends_on'] ?? null) !== ($despues['ends_on'] ?? null)) {
            $lineas[] = 'Fin de contrato: '.self::fecha($antes['ends_on'] ?? null, 'sin fecha').' → '.self::fecha($despues['ends_on'] ?? null, 'sin fecha').'.';
        }
        $hueco = $this->detalles['interrupcion'] ?? null;
        if (is_array($hueco)) {
            $lineas[] = 'Sin contrato del '.self::fecha($hueco['desde'] ?? null).' al '.self::fecha($hueco['hasta'] ?? null).': esos días no cuentan como previstos.';
        }

        return $lineas;
    }

    private static function fecha(?string $iso, string $vacio = 'sin fecha'): string
    {
        return $iso ? Carbon::parse($iso)->format('d/m/Y') : $vacio;
    }

    protected function casts(): array
    {
        return ['detalles' => 'array', 'created_at' => 'datetime'];
    }
}
