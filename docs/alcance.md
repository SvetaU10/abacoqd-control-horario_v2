# Alcance

Descripción actualizada el 6 de noviembre de 2026.

Jornada es una aplicación web. La persona entra con su cuenta y el servidor confirma cada fichaje. No es un programa de escritorio ni un instalador.

## Incluye

- Entrada, pausa, vuelta y salida. La pausa no suma como tiempo trabajado.
- Inicio ya en curso («Ya estaba trabajando») y reunión que conserva la hora prevista del horario.
- Día anterior sin cerrar: se pregunta la hora de salida y no se puede abrir el día nuevo hasta cerrarlo. Un tramo sin salida no inventa la hora actual.
- Equipo encendido después de la hora de salida: no alarga la jornada. La persona puede decir que sigue.
- Trabajo fuera del equipo, con la hora de inicio y de fin que indica la persona.
- Horario en el alta desde Gestión; el alta por consola permite dejarlo pendiente sin inventar horas. Calendario de festivos de la sede municipal de cada persona.
- Registro propio, copia CSV y corrección de una hora. La hora anterior se conserva, con quién la cambió y cuándo.
- Consulta del registro de otra persona con un motivo, y ese acceso queda escrito.
- Avisos por cierres sin completar. Cada uno cuenta desde el primero. Dentro del año en curso no caduca hasta que la persona lo explica. Se muestran por antigüedad y solo los de este año. No hay margen de 8 ni reinicio por tiempo. El responsable ve marcados en Estado los cierres sin resolver de hace más de 7 días, de este año.
- Tres papeles: trabajador, responsable y técnico. El responsable gestiona el equipo y consulta los registros con motivo. El técnico gestiona actualizaciones y la base de datos activa; puede participar en el registro laboral o ser una cuenta exclusivamente de mantenimiento, sin duplicar a su titular en la plantilla. La baja cierra el acceso y no borra las jornadas.
- Tickets: cualquier persona cuenta un fallo de la aplicación al técnico y conversan hasta resolverlo.
- Incidencias: el texto tiene mínimo y máximo. El responsable las ve en Pendiente, En curso o Resuelta. Las resueltas salen del listado y quedan en el archivo.

## Fuera

- Geolocalización, biometría, capturas de pantalla y vigilancia de actividad.
- Módulo de horas extra y autorización para seguir después del horario. El exceso se muestra; ABACO decide cómo clasificarlo.
- Borrado general de registros.
- Instalador de escritorio. Una hora hecha en otro ordenador se anota en esta aplicación como trabajo fuera del equipo.
- Inventar festivos si el calendario del año no está cargado.

## Pendiente

La entrega para `/controlhorario` se instala con base nueva y sin actividad ficticia. Incluye una carga explícita de Madrid capital para el periodo restante de 2026 y 2027, contrastada con publicaciones oficiales. Los correos, claves, fechas efectivas y horarios reales se configuran al final; no se hereda la caducidad del piloto. Consulta el despliegue y [las fuentes del calendario](CALENDARIO_MADRID.md).

La [revisión de preentrega](revision-preentrega.html#cierre) recoge las comprobaciones finales y los límites del entorno real. La cola de fichaje sin conexión ya no existe. Una corrección puede cruzarse con otro tramo, pero el total cuenta una sola vez cada intervalo; una reunión no duplica el trabajo ya anotado. Las correcciones releen el tramo al guardar y el borrado de prueba sólo existe con la demo habilitada en un entorno permitido.

La cuenta de responsable carga o actualiza los festivos por sede, municipio y año. Puede aplicar un calendario anual completo o cambiarlo desde una fecha conservando el periodo anterior. Las publicaciones incompletas requieren la revisión indicada antes de confirmar la cobertura; las personas conservan la sede que les correspondía en cada fecha. Madrid es la sede inicial de una instalación nueva, no un calendario impuesto a toda la plantilla.
