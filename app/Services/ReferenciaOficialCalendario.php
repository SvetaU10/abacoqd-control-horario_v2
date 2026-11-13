<?php

namespace App\Services;

class ReferenciaOficialCalendario
{
    public const DIRECTORIO = 'https://www.boe.es/legislacion/otros_diarios_oficiales.php';

    private ?array $hosts = null;

    public function esOficial(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ($this->hosts() as $oficial) {
            if ($host === $oficial || str_ends_with($host, '.'.$oficial)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }
        $ruta = database_path('data/boletines-oficiales-2026.json');
        $datos = is_file($ruta) ? json_decode(file_get_contents($ruta), true) : null;
        if (! is_array($datos) || ($datos['fuente'] ?? null) !== self::DIRECTORIO || ! is_array($datos['enlaces'] ?? null)) {
            return $this->hosts = [];
        }
        $hosts = [];
        foreach ($datos['enlaces'] as $enlace) {
            if (! is_array($enlace) || ! is_string($enlace['url'] ?? null) || ! is_string($enlace['host'] ?? null)
                || parse_url($enlace['url'], PHP_URL_HOST) !== $enlace['host']) {
                return $this->hosts = [];
            }
            $hosts[] = preg_replace('/^www\./', '', strtolower($enlace['host']));
        }

        return $this->hosts = array_values(array_unique($hosts));
    }
}
