# Reglas de jornada

Zona horaria: `Europe/Madrid`. Las horas de fichar, pausar, volver y salir son las del servidor en el momento de la petición. La entrada usa la hora del servidor si no se envía otra.

Cada persona tiene una sede municipal (`centro`, por ejemplo Principal en Madrid). Se aplican los festivos nacionales, autonómicos y locales de esa sede, con los ámbitos provinciales o insulares que correspondan a las publicaciones disponibles. Al dar de alta se elige la sede; un cambio posterior de sede de la persona se aplica desde hoy y conserva la asignación de los días anteriores.

## Horario

Cada persona tiene un horario con vigencia (`effective_from`). Para un día se usa el de vigencia más reciente que no sea posterior a ese día.

El horario de demostración es de 09:00 a 14:00 y de 15:00 a 18:00, desde el 7 de enero de 2026.

Si se pulsa Empezar jornada 10 minutos o más antes de la hora de inicio, Hoy pregunta «¿Empiezas ya?». Sí guarda la hora real. Todavía no no ficha. No hay correo.

Si se guardan dos horarios con la misma vigencia, la aplicación no tiene una regla explícita de desempate; es un límite pendiente de resolver.

Un cambio de horario pone `cuenta = false` en los avisos de esa persona desde la fecha de vigencia del nuevo horario. Los avisos anteriores conservan su estado.

## Pausa

Pausa cierra el tramo de trabajo abierto y abre un tramo de pausa. Volver cierra la pausa y abre otro tramo de trabajo. Los minutos de pausa no entran en Hoy ni en Semana.

La comida de 14:00 a 15:00 es la referencia del horario. No se pausa sola. Si la persona se olvida, el tiempo sigue contando como trabajo hasta que pulse Pausa o corrija la hora.

## Olvidos y cierre

Si queda un tramo abierto de un día anterior, lo primero que se ve es ese día. Hay que indicar la hora de salida. No se puede empezar el día nuevo hasta cerrar el anterior. Un tramo sin salida de un día pasado no usa la hora actual: no se sabe cuándo terminó, así que no se inventa.

Si el equipo sigue encendido pasada la hora de fin de tarde, y la persona no ha dicho que sigue, el tiempo visible se corta en esa hora. Terminar jornada no se ofrece hasta pulsar Sigo. Seguir después de las 18:00 guarda la salida real. No se convierte en hora extra ni pide autorización. El texto actual dice «No es una hora extra»; Pablo pide revisar esa frase porque mostrar un exceso no clasifica el tiempo.

La persona puede corregir una hora suya durante 7 días. Después lo hace el responsable. La hora anterior, la nueva, quién y cuándo quedan en `correcciones`. La operación vuelve a leer y bloquea el tramo antes de guardarlo. Los tramos pueden cruzarse, pero el total cuenta la unión de sus intervalos, sin duplicar minutos; al mover una pausa se ajustan los tramos adyacentes y se conserva la trazabilidad.

## Festivos

Si el día es festivo en la sede de la persona, antes de empezar se pregunta si hay trabajo real. Si la respuesta es no, no se ficha. Si es sí, se puede empezar y el día sigue siendo festivo en el calendario.

Un calendario anual completo requiere periodos de vigencia confirmados que cubran todo el año; tener una o varias fechas manuales no basta. Para decidir si un día exige trabajar se utiliza la sede y el calendario confirmados en esa fecha, el horario vigente y la incorporación. Un festivo conocido o una ausencia aprobada eximen aunque falte el resto del calendario. Si falta cobertura u horario, la planificación se muestra como pendiente y no genera una falta automática; la persona puede registrar trabajo real. Al actualizar desde una fecha se conserva el calendario anterior, las vacaciones y los fichajes.

## Avisos

Se cuenta un aviso por persona, tipo y día. Varios mensajes del mismo cierre son un solo aviso.

Los avisos de cierre sin completar se cuentan desde el primero, sin un umbral por cantidad. Los días sin entrada tienen una ventana de siete días en la bandeja y en la creación automática de avisos; un aviso histórico ya registrado se conserva y puede revisarse mediante su acceso explícito. Cada aviso de cierre con `cuenta` verdadera y sin respuesta se cuenta desde el primero. Dentro del año en curso no caduca: deja de contar solo cuando la persona lo explica. En Hoy y en el registro salen por antigüedad, el más antiguo primero, y solo los de este año. El aviso no cambia el registro por sí solo. Un día sin cerrar nunca es un registro correcto, haya los avisos que haya. El histórico se conserva cuatro años; un aviso de un año anterior no sale en esa lista, pero un tramo que siga abierto sigue impidiendo fichar.

El responsable se entera por la antigüedad y no por el número: en Estado se marca a quien tiene avisos sin explicar de hace más de 7 días (`DIAS_HASTA_AVISAR_A_LA_JEFA`), con un resumen arriba y la misma señal en la ficha de la persona. Es solo consulta y no crea avisos. Los 7 días están pendientes de confirmar con el cliente y con Pablo.

Un fallo común de una fecha marca los avisos de ese día como exclusión común y no cuentan para cada persona.

Explicar un aviso escribe `responded_at`. Con eso deja de contar. Pablo pide separar explicación, corrección de horas y resolución: explicar no debe completar una salida ni borrar un olvido confirmado. Eso no está separado todavía.

Si pasan 20 minutos desde la hora de inicio y no hay fichaje, un comando puede avisar por correo una sola vez. Si el envío falla, se reintenta en la siguiente pasada.

## Conexión

El fichaje necesita conexión y la confirmación del servidor. Si el navegador está sin red, el envío no sale y no se guarda nada en el ordenador. El texto es «No guardado: sin conexión».

Al recuperar la conexión, la persona puede comunicar la incidencia. No hace falta escribir horas: el texto queda en Incidencias para el responsable y, además, llega al administrador técnico como un ticket («Incidencia de conexión») para que pueda contestarlo en Ayuda. Si el ticket o el correo fallan, el texto de el responsable se guarda igualmente. No abre un fichaje ni un aviso de olvido.

El motivo tiene entre 10 y 500 caracteres. Una incidencia nueva está Pendiente. Un mensaje o «En curso» la pasa a En curso. Al resolverla, el chat se cierra y sale del listado principal; se consulta en Archivo.

## Papeles

- Trabajador: ficha y ve su registro.
- Responsable (papel `jefe`): además gestiona el equipo, los horarios, las ausencias, la baja y el calendario. También hace las funciones de dirección: estado del equipo, registro con resumen mensual, copia CSV y lista de consultas. Cada consulta de un registro ajeno exige motivo y queda escrita.
- Técnico: gestiona las actualizaciones y comprueba la base de datos activa (estado, integridad, copias). Con `registra_jornada=true` también participa en el registro laboral. Una cuenta creada con `--solo-tecnico` queda fuera de plantilla, fichajes, vacaciones, incidencias laborales y avisos; entra directamente en Sistema. No tiene pantalla de consulta laboral de otras personas, pero una copia íntegra incluye sus datos y exige acceso técnico autorizado. Cada copia o actualización queda escrita en `acciones_tecnicas`.
