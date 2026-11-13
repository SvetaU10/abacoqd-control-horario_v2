<?php

use App\Http\Controllers\AjusteSaldoVacacionesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\GestionController;
use App\Http\Controllers\ImportacionCalendarioController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\JefeController;
use App\Http\Controllers\JornadaController;
use App\Http\Controllers\MiCuentaController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\TecnicoController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\EnsureRegistraJornada;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/clave', [AuthController::class, 'pedirClave'])->name('password.request');
    Route::post('/clave', [AuthController::class, 'enviarClave'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/clave/{token}', [AuthController::class, 'editarClave'])->name('password.reset');
    Route::post('/clave/nueva', [AuthController::class, 'guardarClave'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'activo'])->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/mi-cuenta', [MiCuentaController::class, 'index'])->name('mi-cuenta');
    Route::post('/mi-cuenta/correo', [MiCuentaController::class, 'solicitar'])->middleware('throttle:6,1')->name('mi-cuenta.correo.solicitar');
    Route::get('/mi-cuenta/correo/confirmar', [MiCuentaController::class, 'confirmar'])->name('mi-cuenta.correo.confirmar');
    Route::post('/mi-cuenta/correo/confirmar', [MiCuentaController::class, 'aplicar'])->middleware('throttle:10,1')->name('mi-cuenta.correo.aplicar');

    Route::middleware(EnsureRegistraJornada::class)->group(function () {
        Route::get('/', [JornadaController::class, 'index'])->name('jornada');
        Route::post('/jornada/entrada', [JornadaController::class, 'entrar'])->name('jornada.entrada');
        Route::post('/jornada/salida', [JornadaController::class, 'salir'])->name('jornada.salida');
        Route::post('/jornada/pausa', [JornadaController::class, 'pausar'])->name('jornada.pausa');
        Route::post('/jornada/volver', [JornadaController::class, 'volver'])->name('jornada.volver');
        Route::post('/jornada/ya-estaba', [JornadaController::class, 'yaEstaba'])->name('jornada.ya');
        Route::post('/jornada/reunion', [JornadaController::class, 'reunion'])->name('jornada.reunion');
        Route::post('/jornada/cerrar', [JornadaController::class, 'cerrar'])->name('jornada.cerrar');
        Route::post('/jornada/seguir', [JornadaController::class, 'seguir'])->name('jornada.seguir');
        Route::get('/jornada/incidencia', [JornadaController::class, 'crearIncidencia'])->name('jornada.incidencia.crear');
        Route::post('/jornada/incidencia', [JornadaController::class, 'incidencia'])->name('jornada.incidencia');
        Route::post('/incidencias/{incidencia}/mensajes', [IncidenciaController::class, 'mensaje'])->middleware('throttle:20,1')->name('incidencias.mensaje');
        Route::post('/jornada/festivo', [JornadaController::class, 'festivo'])->name('jornada.festivo');
        Route::post('/jornada/completar', [JornadaController::class, 'completar'])->name('jornada.completar');
        Route::post('/jornada/borrar-prueba', [JornadaController::class, 'borrarPrueba'])->name('jornada.borrar-prueba');
        Route::post('/jornada/borrar-prueba-ayer', [JornadaController::class, 'borrarAyer'])->name('jornada.borrar-ayer');
        Route::post('/jornada/borrar-prueba-tres-dias', [JornadaController::class, 'borrarHaceTresDias'])->name('jornada.borrar-tres-dias');

        Route::get('/registro', [RegistroController::class, 'index'])->name('registro');
        Route::get('/registro/copia.csv', [RegistroController::class, 'csv'])->name('registro.csv');
        Route::post('/registro/explicacion', [RegistroController::class, 'explicar'])->name('registro.explicar');
        Route::get('/registro/dia/{fecha}', [RegistroController::class, 'dia'])->where('fecha', '\d{4}-\d{2}-\d{2}')->name('registro.dia');
        Route::post('/registro/dia/{fecha}/horas', [RegistroController::class, 'registrarHoras'])->where('fecha', '\d{4}-\d{2}-\d{2}')->name('registro.dia.horas');
        Route::get('/registro/{jornada}', [RegistroController::class, 'show'])->name('registro.show');
        Route::post('/tramos/{tramo}/corregir', [RegistroController::class, 'corregir'])->name('tramos.corregir');

        // El calendario lo ve toda la plantilla. Los festivos salen de la tabla local, filtrados por el centro de quien mira.
        Route::get('/calendario', [CalendarioController::class, 'index'])->name('calendario');
        Route::post('/calendario/vacaciones', [CalendarioController::class, 'solicitar'])->name('calendario.vacaciones');

    });

    // Tickets: cualquier persona cuenta un problema; solo ella y el técnico lo ven.
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/nuevo', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:10,1')->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/mensajes', [TicketController::class, 'mensaje'])->middleware('throttle:20,1')->name('tickets.mensaje');

    Route::middleware(['jefa', EnsureRegistraJornada::class])->group(function () {
        Route::get('/gestion/municipios', [ImportacionCalendarioController::class, 'municipios'])->middleware('throttle:60,1')->name('gestion.municipios');
        Route::get('/gestion/calendario/{centro}', [ImportacionCalendarioController::class, 'mostrar'])->name('gestion.calendario.mostrar');
        Route::post('/gestion/calendario/{centro}', [ImportacionCalendarioController::class, 'cargar'])->middleware('throttle:10,1')->name('gestion.calendario.cargar');
        Route::post('/gestion/calendario/{centro}/importaciones/{importacion}/confirmar', [ImportacionCalendarioController::class, 'confirmar'])->name('gestion.calendario.confirmar');
        Route::get('/equipo', [EquipoController::class, 'index'])->name('equipo.index');
        Route::post('/equipo/centros', [EquipoController::class, 'crearCentro'])->name('equipo.centros.store');
        Route::patch('/equipo/centros/{centro}', [EquipoController::class, 'actualizarCentro'])->name('equipo.centros.update');
        Route::patch('/equipo/centros/{centro}/minimo', [EquipoController::class, 'actualizarMinimo'])->name('equipo.centros.minimo');
        Route::get('/equipo/nueva', [EquipoController::class, 'create'])->name('equipo.create');
        Route::post('/equipo', [EquipoController::class, 'store'])->name('equipo.store');
        Route::get('/equipo/{user}', [EquipoController::class, 'show'])->name('equipo.show');
        Route::get('/equipo/{user}/saldo-vacaciones', [AjusteSaldoVacacionesController::class, 'edit'])->name('equipo.vacaciones.saldo');
        Route::post('/equipo/{user}/saldo-vacaciones', [AjusteSaldoVacacionesController::class, 'store'])->name('equipo.vacaciones.saldo.guardar');
        Route::post('/equipo/{user}/centro', [EquipoController::class, 'centro'])->name('equipo.centro');
        Route::post('/equipo/{user}/horario', [EquipoController::class, 'horario'])->name('equipo.horario');
        Route::post('/equipo/{user}/ausencia', [EquipoController::class, 'ausencia'])->name('equipo.ausencia');
        Route::post('/equipo/{user}/ausencias/{ausencia}/anular', [EquipoController::class, 'anularAusencia'])->name('equipo.ausencia.anular');
        Route::post('/equipo/{user}/baja', [EquipoController::class, 'baja'])->name('equipo.baja');
        Route::post('/equipo/{user}/reincorporar', [EquipoController::class, 'reincorporar'])->name('equipo.reincorporar');
        Route::get('/equipo/{user}/editar', [EquipoController::class, 'edit'])->name('equipo.edit');
        Route::patch('/equipo/{user}', [EquipoController::class, 'update'])->name('equipo.update');
        Route::get('/equipo/{user}/registro', [RegistroController::class, 'de'])->name('equipo.registro');
        Route::post('/equipo/{user}/registro', [RegistroController::class, 'motivo'])->name('equipo.motivo');
        Route::get('/equipo/{user}/copia.csv', [RegistroController::class, 'csvDe'])->name('equipo.csv');

        Route::post('/calendario/fallo', [CalendarioController::class, 'fallo'])->name('calendario.fallo');
        Route::get('/calendario/festivos/plantilla', [CalendarioController::class, 'plantillaFestivos'])->name('calendario.festivos.plantilla');
        Route::post('/calendario/festivos/previsualizar', [CalendarioController::class, 'previsualizarFestivos'])->name('calendario.festivos.previsualizar');
        Route::post('/calendario/festivos/previsualizacion/cerrar', [CalendarioController::class, 'cerrarPrevisualizacionFestivos'])->name('calendario.festivos.previsualizacion.cerrar');
        Route::post('/calendario/festivos/importar', [CalendarioController::class, 'importarFestivos'])->name('calendario.festivos.importar');
        Route::patch('/calendario/festivos/{festivo}', [CalendarioController::class, 'actualizarFestivo'])->name('calendario.festivos.update');
        Route::delete('/calendario/festivos/{festivo}', [CalendarioController::class, 'eliminarFestivo'])->name('calendario.festivos.destroy');
        Route::get('/gestion', [GestionController::class, 'index'])->name('gestion.index');
        Route::get('/gestion/cobertura', [GestionController::class, 'cobertura'])->name('gestion.cobertura');
        Route::get('/incidencias', [IncidenciaController::class, 'index'])->name('incidencias.index');
        Route::get('/incidencias/{incidencia}', [IncidenciaController::class, 'show'])->name('incidencias.show');
        Route::post('/incidencias/{incidencia}/curso', [IncidenciaController::class, 'enCurso'])->name('incidencias.curso');
        Route::post('/incidencias/{incidencia}/resolver', [IncidenciaController::class, 'resolver'])->name('incidencias.resolver');

        // Funciones de dirección: estado del equipo y registros en solo lectura.
        // Lo único que se escribe es el motivo de cada consulta, que queda anotado.
        Route::prefix('jefe')->name('jefe.')->group(function () {
            Route::get('/', [JefeController::class, 'index'])->name('index');
            Route::get('/consultas', [JefeController::class, 'consultas'])->name('consultas');
            Route::post('/vacaciones/{solicitud}', [JefeController::class, 'decidirVacaciones'])->name('vacaciones.decidir');
            Route::get('/personas/{user}', [JefeController::class, 'persona'])->name('persona');
            Route::get('/personas/{user}/registro', [JefeController::class, 'registro'])->name('registro');
            Route::post('/personas/{user}/registro', [JefeController::class, 'motivo'])->name('motivo');
            Route::get('/personas/{user}/copia.csv', [JefeController::class, 'csv'])->name('csv');
            Route::get('/dias/{jornada}', [JefeController::class, 'dia'])->name('dia');
        });
    });

    // Administrador técnico: estado de la base de datos, copias y actualizaciones.
    // No ve registros de otras personas.
    Route::middleware('tecnico')->prefix('tecnico')->name('tecnico.')->group(function () {
        Route::get('/', [TecnicoController::class, 'index'])->name('index');
        Route::post('/copia', [TecnicoController::class, 'copia'])->name('copia');
        Route::post('/exportar', [TecnicoController::class, 'exportar'])->name('exportar');
        Route::post('/actualizar', [TecnicoController::class, 'actualizar'])->name('actualizar');
        Route::post('/restaurar', [TecnicoController::class, 'restaurar'])->name('restaurar');
        Route::get('/tickets', [TecnicoController::class, 'tickets'])->name('tickets');
        Route::post('/tickets/{ticket}/estado', [TicketController::class, 'estado'])->name('tickets.estado');
    });
});
