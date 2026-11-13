<?php

namespace App\Services;

use App\Models\Centro;
use App\Models\ImportacionCalendario;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CalendarioMunicipal
{
    public function __construct(
        private FestivosBoe $boe,
        private FestivosCastillaLeon $castillaLeon,
        private FestivosValencia $valencia,
        private CatalogoMunicipios $catalogo,
        private FestivosEspana $espana,
        private VigenciasCalendario $vigencias,
        private ReferenciaOficialCalendario $referencias,
    ) {}

    public const CATALOGO = 'https://festivos.io/v1/ref/municipios.json';

    public const CAM_REGIONALES = 'https://datos.comunidad.madrid/catalogo/dataset/f160eb6c-6715-471e-9bc0-38497aae950f/resource/975f579d-92c2-42de-bfa9-aff5bd164586/download/festivos_regionales.json';

    public const CAM_LOCALES = 'https://datos.comunidad.madrid/catalogo/dataset/f160eb6c-6715-471e-9bc0-38497aae950f/resource/db6a3cb0-5504-4db8-9fe7-e42af1ae329b/download/festivos_locales.json';

    /** @return list<array<string,string>> */
    public function municipios(?string $buscar = null): array
    {
        return $this->catalogo->buscar($buscar, 26);
    }

    /** @return array<string,string> */
    public function municipio(string $ine): array
    {
        return $this->catalogo->resolver($ine);
    }

    public function ultima(Centro $centro, int $anio): ?ImportacionCalendario
    {
        return ImportacionCalendario::query()->where('centro_id', $centro->id)->where('anio', $anio)->latest('id')->first();
    }

    public function activo(Centro $centro, int $anio): ?ImportacionCalendario
    {
        return ImportacionCalendario::query()->where('clave_activa', $centro->id.':'.$anio)->first();
    }

    public function cargar(Centro $centro, int $anio, string $ine, User $autor, ?string $entidad = null, ?string $desde = null): ImportacionCalendario
    {
        if ($anio < 2000 || $anio > 2100 || ! preg_match('/^\d{5}$/D', $ine)) {
            throw new InvalidArgumentException('El año o el municipio no son válidos.');
        }
        $desde ??= sprintf('%04d-01-01', $anio);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $desde, $partes)
            || (int) $partes[1] !== $anio || ! checkdate((int) $partes[2], (int) $partes[3], $anio)) {
            throw new InvalidArgumentException('La fecha de efecto debe ser un día válido del año que quieres cargar.');
        }
        $base = [
            'centro_id' => $centro->id, 'anio' => $anio, 'municipio_ine' => $ine,
            'vigente_desde' => $desde,
            'consultado_at' => now(), 'consultado_por' => $autor->id,
            'festivos' => [], 'fuentes' => [],
            '_configuracion' => Centro::query()->whereKey($centro->id)->firstOrFail()->only(['municipality', 'community', 'municipio_ine']),
        ];

        try {
            $municipio = $this->municipio($ine);
            $base += [
                'municipio' => $municipio['nombre'], 'provincia' => $municipio['provincia'],
                'comunidad' => $municipio['comunidad'], 'comunidad_codigo' => $municipio['comunidad_codigo'],
            ];
            $url = 'https://festivos.io/v1/'.$anio.'/municipio/'.$ine.'.json';
            $datos = $this->descargar($url);
            if ($datos === []) {
                return $this->registrar($base, 'no_disponible', 'Todavía no hay datos para este municipio y año en el proveedor. Puedes volver a consultar más adelante.');
            }
            $festivos = $this->normalizar($datos, $municipio, $anio);
            $base['festivos'] = $festivos;
            $base['fuentes'] = [
                ['nombre' => 'festivos.io · CC BY 4.0', 'url' => $url],
                ...array_values(collect($festivos)->map(fn (array $dia): array => ['nombre' => $dia['fuente'], 'url' => $dia['url']])->unique('url')->all()),
            ];
            $base['hash'] = hash('sha256', json_encode([$ine, $anio, $festivos], JSON_THROW_ON_ERROR));
            if ($festivos === []) {
                return $this->registrar($base, 'no_disponible', 'El proveedor todavía no ofrece fechas para este municipio y año.');
            }
            $locales = array_filter($festivos, fn (array $dia): bool => $dia['ambito'] === 'pueblo');
            if (in_array($datos['local_status'] ?? null, ['pending', 'partial', 'provisional'], true) || $locales === []) {
                return $this->registrar($base, 'parcial', 'Hay datos del año, pero faltan fiestas municipales confirmadas en el proveedor. El calendario anterior se conserva.');
            }
            try {
                $confirmacion = match ($municipio['comunidad_codigo']) {
                    'ES-MD' => $this->contrastarMadrid($festivos, $ine, $anio, $entidad),
                    'ES-CL', 'ES-VC' => $this->contrastarComunidad($festivos, $municipio, $anio),
                    default => $this->espana->consultar($anio, $municipio, $festivos),
                };
            } catch (RuntimeException $error) {
                $confirmacion = ['estado' => 'pendiente_revision', 'mensaje' => $error->getMessage(), 'fuentes' => []];
            }
            if ($confirmacion['estado'] === 'pendiente_revision' && empty($confirmacion['requiere_revision'])
                && in_array($municipio['comunidad_codigo'], ['ES-MD', 'ES-CL', 'ES-VC'], true)) {
                $alternativa = $this->espana->consultar($anio, $municipio, $festivos);
                $confirmacion = [...$alternativa, 'fuentes' => [...($confirmacion['fuentes'] ?? []), ...$alternativa['fuentes']]];
            }
            $base['fuentes'] = collect([...$base['fuentes'], ...($confirmacion['fuentes'] ?? [])])->unique('url')->values()->all();
            if ($confirmacion['estado'] === 'confirmado') {
                if ($municipio['comunidad_codigo'] === 'ES-MD' && isset($confirmacion['oficial_madrid'])) {
                    $base['fuentes'][] = ['nombre' => 'Comunidad de Madrid · generales oficiales · CC BY 4.0', 'url' => self::CAM_REGIONALES];
                    $base['fuentes'][] = ['nombre' => 'Comunidad de Madrid · locales oficiales · CC BY 4.0', 'url' => self::CAM_LOCALES];
                }

                return $this->activar($centro, $anio, $base, $autor, isset($confirmacion['oficial_madrid'])
                    ? 'Fechas contrastadas con el calendario oficial de la Comunidad de Madrid.'
                    : $confirmacion['mensaje']);
            }

            return $this->registrar($base, $confirmacion['estado'], $confirmacion['mensaje']);
        } catch (InvalidArgumentException $error) {
            throw $error;
        } catch (RuntimeException $error) {
            return $this->registrar($base, 'error', $error->getMessage().' Se conserva el calendario válido que hubiera cargado.');
        }
    }

    public function confirmar(ImportacionCalendario $importacion, User $autor): ImportacionCalendario
    {
        return DB::transaction(function () use ($importacion, $autor): ImportacionCalendario {
            $centro = Centro::query()->whereKey($importacion->centro_id)->lockForUpdate()->firstOrFail();
            $importacion = ImportacionCalendario::query()->whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if (! $importacion->puedeConfirmarse() || $importacion->festivos === []
                || $this->ultima($centro, $importacion->anio)?->id !== $importacion->id) {
                throw new InvalidArgumentException('Esta consulta no se puede confirmar. Busca de nuevo el calendario y revisa sus fuentes.');
            }
            $base = $importacion->only([
                'centro_id', 'anio', 'municipio_ine', 'municipio', 'provincia', 'comunidad',
                'comunidad_codigo', 'festivos', 'fuentes', 'hash', 'vigente_desde',
            ]);
            $base['consultado_at'] = now();
            $base['consultado_por'] = $autor->id;

            return $this->activar($centro, $importacion->anio, $base, $autor, 'Calendario confirmado tras revisar las referencias oficiales.');
        }, 3);
    }

    public function invalidarCentro(Centro $centro, User $autor, ?Carbon $desde = null): void
    {
        $desde = ($desde ?? now())->copy()->startOfDay();
        DB::transaction(function () use ($centro, $autor, $desde): void {
            Centro::query()->whereKey($centro->id)->lockForUpdate()->firstOrFail();
            $this->vigencias->invalidar($centro, $desde);
            $consultas = ImportacionCalendario::query()->where('centro_id', $centro->id)->where('anio', '>=', $desde->year)->latest('id')->get();
            $activas = $consultas->whereNotNull('clave_activa')->keyBy('anio');
            ImportacionCalendario::query()->where('centro_id', $centro->id)
                ->where('anio', '>=', $desde->year)
                ->whereNotNull('clave_activa')->update(['clave_activa' => null]);
            foreach ($consultas->unique('anio') as $consulta) {
                $referencia = $activas->get($consulta->anio) ?? $consulta;
                $base = $referencia->only([
                    'centro_id', 'anio', 'municipio_ine', 'municipio', 'provincia', 'comunidad',
                    'comunidad_codigo', 'festivos', 'fuentes', 'hash',
                ]);
                $base['consultado_at'] = now();
                $base['consultado_por'] = $autor->id;
                $base['vigente_desde'] = $referencia->anio === $desde->year ? $desde->toDateString() : $referencia->anio.'-01-01';
                $this->registrar($base, 'configuracion_modificada', 'Ha cambiado el municipio o la comunidad del centro. Carga su calendario de nuevo; las consultas anteriores se conservan en el historial.');
            }
        }, 3);
    }

    /** @return array<string,mixed> */
    private function descargar(string $url): array
    {
        try {
            $respuesta = Http::acceptJson()->connectTimeout(3)->timeout(12)->get($url);
        } catch (ConnectionException $error) {
            throw new RuntimeException('No se puede conectar con la fuente de calendario.', previous: $error);
        }
        if ($respuesta->status() === 404) {
            return [];
        }
        if (! $respuesta->successful()) {
            throw new RuntimeException('La fuente de calendario no está disponible ahora (HTTP '.$respuesta->status().').');
        }
        $datos = $respuesta->json();
        if (! is_array($datos)) {
            throw new RuntimeException('La fuente ha devuelto datos con un formato no válido.');
        }

        return $datos;
    }

    /** @param array<string,mixed> $datos @param array<string,string> $municipio @return list<array{date:string,name:string,ambito:string,fuente:string,url:string}> */
    private function normalizar(array $datos, array $municipio, int $anio): array
    {
        if (($datos['year'] ?? null) !== $anio || ($datos['municipality']['ine'] ?? null) !== $municipio['ine']
            || ($datos['municipality']['ccaa']['code'] ?? null) !== $municipio['comunidad_codigo']
            || ! is_array($datos['holidays'] ?? null)) {
            throw new RuntimeException('La respuesta no corresponde al municipio y año solicitados.');
        }
        $resultado = [];
        foreach ($datos['holidays'] as $dia) {
            $fecha = $dia['date'] ?? null;
            $nombre = $dia['name']['es'] ?? null;
            $ambito = match ($dia['level'] ?? null) {
                'national' => 'estado', 'regional' => 'comunidad', 'local' => 'pueblo',
                'provincial', 'province' => 'provincia', 'insular' => 'insular', default => null,
            };
            $fuente = $dia['source']['ref'] ?? '';
            $url = $dia['source']['url'] ?? '';
            if (! is_string($fecha) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes)
                || (int) $partes[1] !== $anio || ! checkdate((int) $partes[2], (int) $partes[3], $anio)
                || ! is_string($nombre) || trim($nombre) === '' || mb_strlen($nombre) > 255
                || $ambito === null || ! is_string($fuente) || $fuente === ''
                || ! is_string($url) || ! $this->esFuenteOficial($url)) {
                throw new RuntimeException('El calendario contiene una fecha, un ámbito o una referencia oficial no válidos.');
            }
            $clave = $fecha.':'.$ambito;
            if (isset($resultado[$clave]) && $resultado[$clave]['name'] !== trim($nombre)) {
                throw new RuntimeException('El proveedor devuelve dos nombres distintos para la misma fecha y ámbito. Revisa las fuentes.');
            }
            $resultado[$clave] = ['date' => $fecha, 'name' => trim($nombre), 'ambito' => $ambito, 'fuente' => $fuente, 'url' => $url];
        }
        ksort($resultado);

        return array_values($resultado);
    }

    private function esFuenteOficial(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        foreach (['boe.es', 'comunidad.madrid', 'madrid.es', 'bocm.es', 'jcyl.es', 'dipsegovia.es', 'gva.es',
            'gencat.cat', 'transparenciacatalunya.cat', 'borm.es', 'jccm.es', 'xunta.gal', 'juntadeandalucia.es', 'aragon.es', 'asturias.es', 'cantabria.es',
            'larioja.org', 'navarra.es', 'euskadi.eus', 'caib.es', 'carm.es', 'castillalamancha.es',
            'juntaex.es', 'gobiernodecanarias.org', 'ceuta.es', 'melilla.es', 'valencia.es', 'segovia.es'] as $oficial) {
            if ($host === $oficial || str_ends_with($host, '.'.$oficial)) {
                return true;
            }
        }

        return $this->referencias->esOficial($url);
    }

    /** @param list<array<string,string>> $festivos @param array<string,string> $municipio */
    private function contrastarComunidad(array $festivos, array $municipio, int $anio): array
    {
        $generales = $this->boe->consultar($anio, $municipio['comunidad_codigo'], $festivos);
        if ($generales['estado'] !== 'confirmado') {
            return $generales;
        }
        $locales = $municipio['comunidad_codigo'] === 'ES-CL'
            ? $this->castillaLeon->consultar($anio, $municipio)
            : $this->valencia->consultar($anio, $municipio);
        $fuentes = [...$generales['fuentes'], ...$locales['fuentes']];
        if ($locales['estado'] !== 'confirmado') {
            return [...$locales, 'fuentes' => $fuentes];
        }
        $generalesRecibidos = array_values(array_map(fn (array $dia): string => $dia['date'].':'.$dia['ambito'], array_filter($festivos, fn (array $dia): bool => $dia['ambito'] !== 'pueblo')));
        $localesRecibidos = array_values(array_unique(array_column(array_filter($festivos, fn (array $dia): bool => $dia['ambito'] === 'pueblo'), 'date')));
        sort($generalesRecibidos);
        sort($localesRecibidos);
        $generalesEsperados = array_map(fn (array $dia): string => $dia['date'].':'.$dia['ambito'], $generales['dias']);
        $localesEsperados = $locales['fechas'];
        sort($generalesEsperados);
        sort($localesEsperados);
        if ($generalesRecibidos !== $generalesEsperados || $localesRecibidos !== $localesEsperados) {
            return ['estado' => 'conflicto', 'mensaje' => 'Las fechas del proveedor no coinciden con el BOE y la fuente oficial municipal. No se ha sustituido el calendario válido.', 'fuentes' => $fuentes];
        }

        return ['estado' => 'confirmado', 'mensaje' => 'Fechas nacionales y autonómicas contrastadas con el BOE y municipales con la fuente oficial de '.$municipio['comunidad'].'.', 'fuentes' => $fuentes];
    }

    /** @param list<array<string,string>> $festivos @return array{estado:string,mensaje:string} */
    private function contrastarMadrid(array $festivos, string $ine, int $anio, ?string $entidad): array
    {
        try {
            $generales = $this->descargar(self::CAM_REGIONALES)['data'] ?? [];
            $locales = $this->descargar(self::CAM_LOCALES)['data'] ?? [];
        } catch (RuntimeException) {
            return ['estado' => 'pendiente_revision', 'mensaje' => 'Se han recuperado las fechas, pero no se pudo contrastar la fuente oficial. Revisa las referencias antes de confirmar.'];
        }
        if (! is_array($generales) || ! is_array($locales)) {
            return ['estado' => 'pendiente_revision', 'mensaje' => 'La fuente oficial ha cambiado de formato. El calendario queda pendiente de revisión.'];
        }
        $generales = array_values(array_filter($generales, fn ($fila): bool => is_array($fila) && (string) ($fila['año'] ?? '') === (string) $anio));
        $locales = array_values(array_filter($locales, fn ($fila): bool => is_array($fila)
            && (string) ($fila['año'] ?? '') === (string) $anio
            && str_pad((string) ($fila['municipio_codigo'] ?? ''), 3, '0', STR_PAD_LEFT) === substr($ine, 2)));
        $entidades = array_unique(array_column($locales, 'entidad_codigo'));
        if ($entidad === null && count($entidades) > 1) {
            return ['estado' => 'pendiente_revision', 'requiere_revision' => true, 'mensaje' => 'El municipio tiene varias entidades con fiestas distintas. Comprueba cuál corresponde al centro antes de confirmar.'];
        }
        if ($entidad !== null) {
            $locales = array_values(array_filter($locales, fn (array $fila): bool => (string) ($fila['entidad_codigo'] ?? '') === $entidad));
        }
        if (count($generales) !== 12 || count($locales) !== 2) {
            return ['estado' => 'pendiente_revision', 'mensaje' => 'La descarga oficial no permite comprobar todo el calendario de este municipio y año. Revisa su publicación antes de confirmar.'];
        }
        $esperados = array_values(array_unique(array_column([...$generales, ...$locales], 'fecha_festivo')));
        $recibidos = array_values(array_unique(array_column($festivos, 'date')));
        sort($esperados);
        sort($recibidos);
        $localesEsperados = array_column($locales, 'fecha_festivo');
        $localesRecibidos = array_column(array_filter($festivos, fn (array $dia): bool => $dia['ambito'] === 'pueblo'), 'date');
        sort($localesEsperados);
        sort($localesRecibidos);
        if ($esperados !== $recibidos || $localesEsperados !== $localesRecibidos) {
            return ['estado' => 'conflicto', 'mensaje' => 'Las fechas del proveedor no coinciden con el calendario oficial. No se ha sustituido la versión activa.'];
        }

        return ['estado' => 'confirmado', 'oficial_madrid' => true, 'mensaje' => 'Calendario oficial comprobado.'];
    }

    /** @param array<string,mixed> $base */
    private function registrar(array $base, string $estado, string $mensaje): ImportacionCalendario
    {
        return DB::transaction(function () use ($base, $estado, $mensaje): ImportacionCalendario {
            if (isset($base['_configuracion'])) {
                $actual = Centro::query()->whereKey($base['centro_id'])->lockForUpdate()->firstOrFail();
                if ($actual->only(['municipality', 'community', 'municipio_ine']) !== $base['_configuracion']) {
                    $estado = 'configuracion_modificada';
                    $mensaje = 'El centro cambió de municipio o comunidad durante la consulta. Busca el calendario de nuevo con la configuración actual.';
                }
                unset($base['_configuracion']);
            }

            return ImportacionCalendario::create($base + ['estado' => $estado, 'mensaje' => $mensaje]);
        }, 3);
    }

    /** @param array<string,mixed> $base */
    private function activar(Centro $centro, int $anio, array $base, User $autor, string $mensaje): ImportacionCalendario
    {
        return DB::transaction(function () use ($centro, $anio, $base, $autor, $mensaje): ImportacionCalendario {
            $actual = Centro::query()->whereKey($centro->id)->lockForUpdate()->firstOrFail();
            if (isset($base['_configuracion']) && $actual->only(['municipality', 'community', 'municipio_ine']) !== $base['_configuracion']) {
                return $this->registrar($base, 'configuracion_modificada', 'La configuración del centro ha cambiado. Consulta de nuevo el calendario.');
            }
            $anterior = $this->activo($centro, $anio);
            $desde = Carbon::parse($base['vigente_desde'] ?? $anio.'-01-01')->toDateString();
            $oficialProtegido = $this->vigencias->delAnio($centro, $anio)->contains(
                fn ($tramo): bool => $tramo->hasta->toDateString() >= $desde
                    && CalendarioMadridOficial::esOficial($tramo->importacion),
            );
            if ($oficialProtegido) {
                $claves = static function (array $dias): array {
                    $claves = array_map(fn (array $dia): string => $dia['date'].':'.$dia['ambito'], $dias);
                    sort($claves);

                    return $claves;
                };
                if ($anterior !== null && CalendarioMadridOficial::esOficial($anterior)
                    && $claves($anterior->festivos) === $claves($base['festivos'])
                    && $this->mismoCalendarioDesde($centro, $anio, [...$base, 'hash' => $anterior->hash])) {
                    return $this->registrar($base, 'sin_cambios', 'La consulta coincide con las fechas y ámbitos oficiales. Se conserva la copia oficial local y su vigencia.');
                }

                return $this->registrar($base, 'conflicto', 'Existe un calendario oficial local revisado de Madrid capital. La consulta no lo sustituye: cualquier cambio de fechas, ámbitos o vigencia requiere revisión explícita.');
            }
            if ($anterior !== null && $anterior->hash === $base['hash']
                && $this->mismoCalendarioDesde($centro, $anio, $base)) {
                return $this->registrar($base, 'sin_cambios', 'El calendario ya está actualizado. La consulta y la comprobación han terminado correctamente.');
            }
            $this->vigencias->asegurarLegado($centro, $anio);
            if ($anterior !== null) {
                $anterior->update(['clave_activa' => null]);
            }

            $resultado = $this->registrar($base + [
                'clave_activa' => $centro->id.':'.$anio,
                'activado_at' => now(), 'activado_por' => $autor->id,
            ], $anterior === null ? 'cargado' : 'actualizado', $mensaje);
            $this->vigencias->activar($resultado);

            return $resultado;
        }, 3);
    }

    /** @param array<string,mixed> $base */
    private function mismoCalendarioDesde(Centro $centro, int $anio, array $base): bool
    {
        $cursor = Carbon::parse($base['vigente_desde'] ?? $anio.'-01-01')->startOfDay();
        $hasta = $cursor->copy()->endOfYear()->startOfDay();
        foreach ($this->vigencias->delAnio($centro, $anio) as $tramo) {
            if ($tramo->hasta->lt($cursor)) {
                continue;
            }
            if ($tramo->desde->gt($cursor) || $tramo->importacion->hash !== $base['hash']) {
                return false;
            }
            $cursor = $tramo->hasta->copy()->addDay();
            if ($cursor->gt($hasta)) {
                return true;
            }
        }

        return false;
    }
}
