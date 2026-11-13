<?php

namespace App\Services;

use App\Models\AccionTecnica;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Lo que necesita el administrador técnico: estado de la base de datos activa,
 * copias y actualizaciones. Solo trabaja con datos técnicos: nunca lee ni
 * devuelve el contenido de las jornadas ni los datos de las personas.
 */
class SistemaService
{
    public const COPIAS_QUE_SE_MUESTRAN = 5;

    /**
     * @return array{
     *     conexion: string,
     *     driver: string,
     *     nombre: string,
     *     responde: bool,
     *     error: ?string,
     *     milisegundos: float,
     *     bytes: ?int,
     *     integridad: ?array{correcta: bool, texto: string},
     *     tablas: list<array{nombre: string, filas: int}>,
     *     aplicadas: int,
     *     pendientes: list<string>
     * }
     */
    public function baseDeDatos(): array
    {
        $conexion = DB::connection();
        $driver = $conexion->getDriverName();
        $archivo = $this->archivoDeLaBase();

        $responde = true;
        $error = null;
        $inicio = hrtime(true);
        try {
            $conexion->select('select 1');
        } catch (Throwable $fallo) {
            $responde = false;
            $error = $fallo->getMessage();
        }
        $milisegundos = round((hrtime(true) - $inicio) / 1_000_000, 1);

        $migraciones = $responde ? $this->migraciones() : ['aplicadas' => 0, 'pendientes' => []];

        return [
            'conexion' => (string) $conexion->getName(),
            'driver' => $driver,
            'nombre' => $archivo !== null ? basename($archivo) : (string) $conexion->getDatabaseName(),
            'responde' => $responde,
            'error' => $error,
            'milisegundos' => $milisegundos,
            'bytes' => $archivo !== null ? (int) filesize($archivo) : null,
            'integridad' => $responde ? $this->integridad($driver) : null,
            'tablas' => $responde ? $this->tablas() : [],
            'aplicadas' => $migraciones['aplicadas'],
            'pendientes' => $migraciones['pendientes'],
        ];
    }

    /**
     * @return array{laravel: string, php: string, entorno: string, depuracion: bool, correo: string}
     */
    public function entorno(): array
    {
        return [
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'entorno' => app()->environment(),
            'depuracion' => (bool) config('app.debug'),
            'correo' => (string) config('mail.default'),
        ];
    }

    /**
     * @return array{aplicadas: int, pendientes: list<string>}
     */
    public function migraciones(): array
    {
        $migrador = app('migrator');
        $repositorio = $migrador->getRepository();
        $aplicadas = $repositorio->repositoryExists() ? $repositorio->getRan() : [];
        $archivos = $migrador->getMigrationFiles([database_path('migrations'), ...$migrador->paths()]);

        return [
            'aplicadas' => count(array_intersect(array_keys($archivos), $aplicadas)),
            'pendientes' => array_values(array_diff(array_keys($archivos), $aplicadas)),
        ];
    }

    /**
     * @return list<array{nombre: string, bytes: int, fecha: string}>
     */
    public function copias(bool $todas = false): array
    {
        if (! File::isDirectory($this->carpetaDeCopias())) {
            return [];
        }

        $archivos = collect(File::files($this->carpetaDeCopias()))
            ->sortByDesc(fn ($archivo) => $archivo->getMTime());

        if (! $todas) {
            $archivos = $archivos->take(self::COPIAS_QUE_SE_MUESTRAN);
        }

        return $archivos
            ->map(fn ($archivo) => [
                'nombre' => $archivo->getFilename(),
                'bytes' => $archivo->getSize(),
                'fecha' => date('Y-m-d H:i', $archivo->getMTime()),
            ])
            ->values()
            ->all();
    }

    /**
     * @throws RuntimeException si la base no admite copia automática
     */
    public function hacerCopia(User $tecnico): string
    {
        $nombre = $this->crearCopia();
        $this->anotar($tecnico, AccionTecnica::COPIA, $nombre);

        return $nombre;
    }

    /**
     * @throws RuntimeException si la copia no existe o el nombre no es válido
     */
    public function rutaDeCopia(string $nombre): string
    {
        if ($nombre === '' || basename($nombre) !== $nombre) {
            throw new RuntimeException('El nombre de la copia no es válido.');
        }

        $ruta = $this->carpetaDeCopias().DIRECTORY_SEPARATOR.$nombre;

        if (! File::isFile($ruta)) {
            throw new RuntimeException('La copia no está disponible para exportarla.');
        }

        return $ruta;
    }

    /**
     * Aplica los cambios pendientes de la base de datos. Antes hace una copia:
     * si no puede hacerla, no toca nada.
     *
     * @throws RuntimeException si no hay copia posible o la actualización falla
     */
    public function actualizar(User $tecnico): string
    {
        $pendientes = $this->migraciones()['pendientes'];

        if ($pendientes === []) {
            $this->anotar($tecnico, AccionTecnica::ACTUALIZACION, 'Sin cambios pendientes.');

            return 'Todo está al día. No había nada que aplicar.';
        }

        $copia = $this->crearCopia();

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $fallo) {
            $this->anotar($tecnico, AccionTecnica::ACTUALIZACION, 'Falló: '.$fallo->getMessage().' Copia previa: '.$copia);

            throw new RuntimeException('No se pudo actualizar: '.$fallo->getMessage().' La copia previa está en '.$copia.'.');
        }

        $this->anotar(
            $tecnico,
            AccionTecnica::ACTUALIZACION,
            'Aplicados: '.implode(', ', $pendientes).'. Copia previa: '.$copia,
        );

        return 'Guardado. Se aplicaron '.count($pendientes).' cambios. Copia previa: '.$copia.'.';
    }

    /**
     * Sustituye la base en uso por una copia guardada o por un archivo.
     * Comprueba la compatibilidad antes de guardar una copia y sustituir la base.
     *
     * @throws RuntimeException si no hay origen, no se puede copiar o no se puede sustituir
     */
    public function restaurar(User $tecnico, ?string $nombreCopia, ?string $rutaSubida): string
    {
        $hayCopia = is_string($nombreCopia) && $nombreCopia !== '';
        $hayArchivo = is_string($rutaSubida) && $rutaSubida !== '';

        if ($hayCopia && $hayArchivo) {
            throw new RuntimeException('Elige una copia guardada o un archivo, no las dos.');
        }

        if (! $hayCopia && ! $hayArchivo) {
            throw new RuntimeException('Elige la base que quieres usar.');
        }

        $origen = $hayCopia ? $this->rutaDeCopia($nombreCopia) : $rutaSubida;
        $autorRestauradoId = $this->comprobarSqlite($origen, $tecnico);

        $destino = $this->archivoDeLaBase();
        if ($destino === null) {
            throw new RuntimeException('Solo se puede sustituir una base SQLite en archivo.');
        }

        $copiaPrevia = $this->crearCopia();
        $conexion = DB::connection()->getName();

        DB::disconnect($conexion);
        DB::purge($conexion);

        foreach ([$destino.'-wal', $destino.'-shm'] as $auxiliar) {
            if (is_file($auxiliar)) {
                File::delete($auxiliar);
            }
        }

        $this->sustituirArchivo($origen, $destino);

        try {
            $autorRestaurado = User::query()->findOrFail($autorRestauradoId);
            $this->anotar(
                $autorRestaurado,
                AccionTecnica::RESTAURACION,
                'Base en uso: '.basename($origen).'. Copia previa: '.$copiaPrevia.'.',
            );
        } catch (Throwable $fallo) {
            DB::disconnect($conexion);
            DB::purge($conexion);

            try {
                $this->sustituirArchivo($this->rutaDeCopia($copiaPrevia), $destino);
            } catch (Throwable $reparacion) {
                throw new RuntimeException(
                    'La restauración falló y no se pudo recuperar la base automáticamente. Copia previa: '.$copiaPrevia.'.',
                    0,
                    $reparacion,
                );
            }

            throw new RuntimeException(
                'No se pudo completar la restauración. Se ha recuperado la base anterior. Copia previa: '.$copiaPrevia.'.',
                0,
                $fallo,
            );
        }

        return 'Guardado. La base en uso es '.basename($origen).'. Copia previa: '.$copiaPrevia.'. Entra otra vez.';
    }

    private function crearCopia(): string
    {
        $archivo = $this->archivoDeLaBase();

        if ($archivo === null || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('La copia automática solo existe para SQLite en archivo. Haz la copia desde el servidor.');
        }

        File::ensureDirectoryExists($this->carpetaDeCopias());

        $nombre = 'jornada-'.now()->format('Ymd-His').'.sqlite';
        if (File::exists($this->carpetaDeCopias().DIRECTORY_SEPARATOR.$nombre)) {
            $nombre = 'jornada-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.sqlite';
        }

        try {
            DB::connection()->statement('VACUUM INTO ?', [$this->carpetaDeCopias().DIRECTORY_SEPARATOR.$nombre]);
        } catch (Throwable $fallo) {
            throw new RuntimeException('No se pudo hacer la copia: '.$fallo->getMessage());
        }

        return $nombre;
    }

    private function carpetaDeCopias(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'copias');
    }

    /**
     * Ruta del archivo de la base cuando es SQLite en disco; null en otro caso.
     */
    private function archivoDeLaBase(): ?string
    {
        $conexion = DB::connection();
        $nombre = (string) $conexion->getDatabaseName();

        if ($conexion->getDriverName() !== 'sqlite' || $nombre === '' || $nombre === ':memory:' || ! is_file($nombre)) {
            return null;
        }

        return $nombre;
    }

    /**
     * @return ?array{correcta: bool, texto: string}
     */
    private function integridad(string $driver): ?array
    {
        if ($driver !== 'sqlite') {
            return null;
        }

        $fallos = collect(DB::select('PRAGMA integrity_check'))
            ->pluck('integrity_check')
            ->reject(fn ($texto) => $texto === 'ok')
            ->values();
        $relaciones = DB::select('PRAGMA foreign_key_check');

        if ($fallos->isEmpty() && $relaciones === []) {
            return ['correcta' => true, 'texto' => 'La base está íntegra y las relaciones entre tablas cuadran.'];
        }

        $texto = $fallos->take(3)->implode(' · ');
        if ($relaciones !== []) {
            $texto .= ($texto === '' ? '' : ' · ').count($relaciones).' filas apuntan a algo que no existe';
        }

        return ['correcta' => false, 'texto' => $texto];
    }

    /**
     * Solo nombre y número de filas: nunca el contenido.
     *
     * @return list<array{nombre: string, filas: int}>
     */
    private function tablas(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $nombre) => str_starts_with($nombre, 'sqlite_'))
            ->sort()
            ->map(fn (string $nombre) => ['nombre' => $nombre, 'filas' => DB::table($nombre)->count()])
            ->values()
            ->all();
    }

    private function comprobarSqlite(string $ruta, User $tecnico): int
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException('No se puede leer la base elegida.');
        }

        $cabecera = file_get_contents($ruta, false, null, 0, 16);

        if ($cabecera !== "SQLite format 3\x00") {
            throw new RuntimeException('Ese archivo no es una base SQLite.');
        }

        try {
            $pdo = new PDO('sqlite:'.$ruta, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $resultado = $pdo->query('PRAGMA integrity_check')->fetchColumn();

            if ($resultado !== 'ok') {
                throw new RuntimeException('Esa base no está íntegra. No se ha cambiado la que está en uso.');
            }

            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('Esa base tiene relaciones rotas. No se ha cambiado la que está en uso.');
            }

            $this->comprobarEsquema($pdo);

            $autor = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = ? AND active = 1');
            $autor->execute([$tecnico->email, 'tecnico']);
            $autorId = $autor->fetchColumn();

            if ($autorId === false) {
                throw new RuntimeException('La copia no contiene tu cuenta técnica activa. No se ha cambiado la base en uso.');
            }

            return (int) $autorId;
        } catch (PDOException $fallo) {
            throw new RuntimeException('No se pudo comprobar la base elegida. No se ha cambiado la que está en uso.', 0, $fallo);
        } finally {
            $pdo = null;
        }
    }

    private function comprobarEsquema(PDO $pdo): void
    {
        $tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (Schema::getTables() as $tabla) {
            $nombre = $tabla['name'];
            if (str_starts_with($nombre, 'sqlite_')) {
                continue;
            }

            if (! in_array($nombre, $tablas, true)) {
                throw new RuntimeException('La base no es compatible con esta versión. Falta la tabla '.$nombre.'.');
            }

            $identificador = '"'.str_replace('"', '""', $nombre).'"';
            $columnas = $pdo->query('PRAGMA table_info('.$identificador.')')->fetchAll(PDO::FETCH_COLUMN, 1);
            foreach (Schema::getColumnListing($nombre) as $columna) {
                if (! in_array($columna, $columnas, true)) {
                    throw new RuntimeException('La base no es compatible con esta versión. Falta la columna '.$nombre.'.'.$columna.'.');
                }
            }
        }

        $migraciones = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $actuales = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        if ($migraciones !== $actuales) {
            throw new RuntimeException('Las migraciones de esa base no corresponden a esta versión. Actualiza la copia en un entorno de prueba antes de restaurarla.');
        }
    }

    private function sustituirArchivo(string $origen, string $destino): void
    {
        $nueva = $destino.'.nueva';
        $apartada = $destino.'.apartada';

        if (! File::copy($origen, $nueva)) {
            throw new RuntimeException('No se pudo preparar la base elegida.');
        }

        try {
            if (is_file($destino) && ! File::move($destino, $apartada)) {
                throw new RuntimeException('No se pudo apartar la base que está en uso.');
            }

            if (! File::move($nueva, $destino)) {
                if (is_file($apartada)) {
                    File::move($apartada, $destino);
                }

                throw new RuntimeException('No se pudo colocar la base elegida.');
            }
        } finally {
            if (is_file($nueva)) {
                File::delete($nueva);
            }
        }

        if (is_file($apartada)) {
            File::delete($apartada);
        }
    }

    private function anotar(User $tecnico, string $accion, string $detalle): void
    {
        AccionTecnica::create([
            'user_id' => $tecnico->id,
            'accion' => $accion,
            'detalle' => Str::limit($detalle, 1000),
        ]);
    }
}
