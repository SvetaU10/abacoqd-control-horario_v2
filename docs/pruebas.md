# Pruebas

Las pruebas automáticas viven en `tests/Feature` y `tests/Unit`. Se lanzan con `php artisan test`. Usan una base en memoria y no tocan la base de desarrollo.

El estado de esta entrega se recoge en la [revisión de preentrega](revision-preentrega.html#cierre), donde se registra el cierre de la batería completa y la comprobación visual y de teclado. Los resultados de octubre que aparecen al final son antecedentes históricos, no el resultado actual.

## Casos cubiertos

| Prueba | Qué demuestra |
| --- | --- |
| `test_entrada_pausa_salida_y_no_duplica` | Entrada, pausa, vuelta y salida. Una segunda entrada no abre otra jornada |
| `test_cierre_olvidado_permite_empezar_el_dia_nuevo` | No se puede empezar el día nuevo hasta indicar la hora de salida del anterior |
| `test_empezar_jornada_antes_de_tiempo_pide_confirmacion` | Diez minutos o más antes de la hora prevista, Hoy pregunta antes de fichar |
| `test_empezar_jornada_en_el_margen_de_diez_minutos_no_pregunta` | Dentro de los diez minutos se ficha sin pregunta |
| `test_una_persona_no_ve_el_registro_de_otra` | El registro ajeno no se abre sin permiso |
| `test_la_baja_conserva_la_jornada` | La baja cierra el acceso y deja la jornada |
| `test_el_festivo_sigue_el_centro_y_no_el_municipio` | El festivo es el de la sede (`centro`), no el del municipio escrito en la ficha |
| `test_el_alta_guarda_la_sede_elegida` | El alta guarda la sede municipal y sus festivos |
| `PlantillaDemoTest` | El seeder explícito de demostración se puede repetir, conserva cuentas y fichajes existentes y no exporta las altas reales al JSON |
| `RecuperarClaveTest` | Pedir el enlace, avisar si el correo no está registrado, cambiar la contraseña y rechazar un token inventado |
| `test_la_reunion_conserva_la_hora_prevista` | La reunión guarda la hora de inicio del horario |
| `test_el_equipo_encendido_no_alarga_la_jornada` | Pasada la hora de salida, el equipo encendido no suma de más en el panel |
| `test_seguir_guarda_la_salida_real_sin_pedir_autorizacion` | Salir más tarde guarda esa hora, sin autorización |
| `test_el_festivo_del_centro_pregunta_si_hay_trabajo` | El festivo pregunta si hay trabajo real |
| `test_el_trabajo_fuera_del_equipo_usa_las_horas_indicadas` | El tramo fuera del equipo usa las horas indicadas |
| `test_un_solo_cierre_sin_completar_ya_se_avisa_y_no_cambia_el_registro` | Un solo cierre sin completar ya se avisa y el aviso no cambia el registro |
| `test_un_aviso_sin_explicar_no_caduca_con_el_tiempo` | Un aviso sin explicar sigue contando meses después, dentro del mismo año |
| `test_un_aviso_del_anio_anterior_no_sale_en_pendientes` | Un aviso de un año anterior se conserva y no sale en la lista de este año |
| `test_una_entrada_sin_salida_de_ayer_no_inventa_la_hora` | Un tramo abierto de ayer no usa la hora actual ni suma minutos |
| `test_explicar_un_aviso_deja_de_contarlo` | Explicar un aviso es lo único que lo deja de contar |
| `test_la_jefa_ve_marcados_los_cierres_sin_resolver_de_mas_de_siete_dias` | Estado y la ficha marcan los avisos sin explicar de hace más de 7 días y no crean avisos |
| `test_el_fallo_comun_y_el_horario_no_cuentan` | Un fallo común o un cambio de horario deja el aviso fuera de la cuenta |
| `test_la_consulta_pide_motivo_y_queda_escrita` | Ver el registro de otra persona exige motivo y queda anotado |
| `test_la_incidencia_se_envia_sin_horas_y_la_jefa_la_ve` | El trabajador envía el texto, sin horas. El responsable lo ve en la lista y en el detalle. El responsable no comunica incidencias |
| `IncidenciaGestionTest` | El motivo exige 10 a 500 caracteres. Pendiente, En curso y Resuelta. Las resueltas salen del listado y van al archivo |
| `JefeTest` | El responsable hace las funciones de dirección: estado, registro con motivo, resumen mensual, CSV y consultas. Los demás papeles no entran |
| `TecnicoTest` | El técnico ficha, solo él entra en Sistema, el panel no enseña datos de personas, lista cambios pendientes y exige confirmar |
| `SistemaEnArchivoTest` | La copia es una base completa; actualizar copia antes de aplicar; si falla, queda la copia y la anotación |
| `AvisoPorCorreoTest` | El correo de olvido de inicio sale una vez, con las exclusiones previstas |
| `DuplicadosTest` | Una ausencia repetida no se duplica |
| `ArranqueSeguroTest` | La instalación normal queda sin cuentas ficticias; la demo y la creación administrativa requieren pasos explícitos |
| `VigenciasCalendarioTest` | Sustituir los festivos desde una fecha conserva el calendario anterior y no inventa cobertura en los huecos |
| `AuditoriaFlujosTest` | El cambio de sede conserva el historial personal y el saldo de vacaciones; los rangos inválidos no provocan errores, los avisos respetan la ventana vigente, una respuesta reabre el ticket resuelto y la demo desactivada impide borrar fichajes |

## Defectos históricos revisados

La revisión del 9 de octubre de 2026 los reprodujo.

| Defecto | Qué ocurre | Estado |
| --- | --- | --- |
| Cola sin conexión | El formulario se guardaba en el navegador y se reenviaba al volver | Cerrado. Ya no hay cola. La declaración queda en `test_la_incidencia_de_conexion_declara_horas_sin_fichar_en_directo` |
| Tramos solapados | Los tramos que coinciden podían sumar dos veces el mismo tiempo | Cerrado. Las correcciones permiten el cruce y el total cuenta la unión de los intervalos; las reuniones no duplican un tramo existente. `test_una_correccion_puede_pisar_otro_rato` y `test_la_reunion_no_repite_un_rato_ya_anotado` |
| Cifra distinta en el panel y en el registro | Con la jornada abierta pasada la hora de salida, Hoy puede cortar el tiempo y el registro o el CSV seguir sumando | Cerrado. `test_el_registro_cuenta_igual_que_hoy_cuando_la_jornada_sigue_abierta` |
| Dos correcciones seguidas | No se relee el tramo al guardar. El inicio puede quedar después del fin | Cerrado. `test_dos_correcciones_seguidas_no_dejan_una_hora_imposible` |
| Borrado de demostración | Ana y Marta pueden borrar el fichaje de hoy sin mirar el entorno | Cerrado fuera de la demo. `test_fuera_de_la_demo_no_se_borran_fichajes_ni_se_ven_las_cuentas` |
| Cambio de horario | Excluye todos los avisos de la persona, no solo los de las fechas afectadas | Cerrado. `test_el_fallo_comun_y_el_horario_no_cuentan` |
| Quién declara la salida | La hora de salida se guardaba sin autor ni momento propios | Cerrado. `test_la_salida_guarda_quien_la_declaro_y_una_correccion_no_lo_borra` |
| Histórico | La pantalla de registro muestra como máximo 60 jornadas | Cerrado. `test_el_registro_no_se_queda_en_sesenta_jornadas` |

## Aún sin probar en un entorno real

Desconexión de verdad, cambio de cuenta en el mismo navegador, sesión caducada, restaurar `database/database.sqlite` y dos ediciones a la vez. Las pruebas actuales no cubren ese recorrido.

## Resultados históricos

Como antecedente, el 14 de octubre de 2026, tras resolver el merge de los papeles, `php artisan test` pasó 67 pruebas y 362 aserciones. El resultado vigente y los límites de la comprobación final se consultan en la [revisión de preentrega](revision-preentrega.html#cierre). El 9 de octubre de 2026 pasó 53 pruebas y 283 aserciones. La revisión de Pablo de esa misma fecha se hizo sobre una versión anterior, con 18 pruebas y 92 aserciones.
