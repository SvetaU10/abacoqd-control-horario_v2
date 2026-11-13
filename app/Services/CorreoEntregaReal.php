<?php

namespace App\Services;

class CorreoEntregaReal
{
    public function disponible(string $mailer, array $visitados = []): bool
    {
        if ($mailer === '' || in_array($mailer, $visitados, true)) {
            return false;
        }
        $configuracion = config('mail.mailers.'.$mailer);
        if (! is_array($configuracion)) {
            return false;
        }
        $transporte = $configuracion['transport'] ?? null;
        if (! is_string($transporte) || in_array($transporte, ['log', 'array'], true)) {
            return false;
        }
        if (in_array($transporte, ['failover', 'roundrobin'], true)) {
            $hijos = $configuracion['mailers'] ?? [];
            if (! is_array($hijos) || $hijos === []) {
                return false;
            }
            foreach ($hijos as $hijo) {
                if (! is_string($hijo) || ! $this->disponible($hijo, [...$visitados, $mailer])) {
                    return false;
                }
            }
        }

        return true;
    }
}
