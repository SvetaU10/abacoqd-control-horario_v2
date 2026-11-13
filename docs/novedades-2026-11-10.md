# Novedades del 10 de noviembre de 2026

Esta versión parte de la entrega del 30 de octubre. Recoge el trabajo de AbacoQD del 3 y del 6 de noviembre y lo que se ha aprovechado de la versión final del alumnado del 6 de noviembre (repositorio de prácticas, commit `73f811f`). La batería completa pasa 536 pruebas y 3.807 aserciones en una copia limpia instalada desde el ZIP.

## Cambios de AbacoQD desde el 30 de octubre

| Área | Cambio |
| --- | --- |
| Perfiles | Los roles internos pasan a `jefe` y `trabajador`, con el masculino genérico en los textos. Una migración convierte los valores antiguos sin recrear cuentas. |
| Cuenta técnica | Una cuenta creada con `--solo-tecnico` queda fuera de la plantilla: no ficha, no pide vacaciones, no entra en los cálculos de jornada ni recibe avisos. Cualquier ruta laboral sobre ella responde 404. |
| Instalación en subruta | La aplicación funciona bajo una ruta como `/controlhorario`: enlaces, recursos, cookies de sesión propias y formularios protegidos sin una cookie `XSRF-TOKEN` que choque con otra web del mismo dominio. Las respuestas llevan `noindex`. |
| Calendario de Madrid | Copias locales y contrastadas del calendario oficial de Madrid capital para 2026 y 2027 (`resources/data/calendarios`), que se cargan con `abaco:preparar-calendario-madrid` indicando autor y fecha de vigencia. No dependen de una API que todavía devuelve fechas incompletas. |
| Carga administrativa | El responsable puede cargar jornadas de un periodo pasado con un horario acordado, con lote, autor, motivo e historial por día. |
| Vacaciones | El saldo de cada año lo confirma el responsable, con historial. Una ausencia anotada por error se anula dejando rastro, y el día recupera lo que tenía antes. |
| Mi cuenta | Cada persona puede cambiar su correo. Hace falta la contraseña actual y confirmar el enlace que llega a la dirección nueva. |
| Recuperación de contraseña | No revela si un correo existe. Sin un correo real configurado en producción, avisa de que no está disponible en lugar de prometer un envío. |
| Acceso por fecha | Una instalación de pruebas puede cerrarse sola a partir de un día (`APP_ACCESS_UNTIL`). |
| Interfaz | Contraste revisado en los dos temas, estados de foco visibles y ajustes de móvil comprobados a 320, 390 y 430 píxeles. |

## Incorporado de la versión final del alumnado

Se han tomado las ideas, no el código tal cual. Cada una se ha adaptado a esta versión y tiene sus propias pruebas.

| Propuesta | Cómo queda |
| --- | --- |
| Panel de vacaciones en el calendario | Muestra, para el año que se está viendo, los días que corresponden, los disfrutados, los aprobados por disfrutar, los pedidos en espera y los que quedan sin asignar, más los periodos aprobados y las solicitudes rechazadas. Usa el saldo confirmado por el responsable en lugar de 22 días fijos y cuenta los días con la misma función que valida la solicitud, así que el panel y el servidor no pueden discrepar. Corrige dos fallos que tenían las dos versiones: el saldo mostrado era siempre el del año en curso y el trabajador no veía sus solicitudes rechazadas. |
| Baja con fecha y reactivación | La baja pide la fecha del último día y un motivo, y se confirma antes de guardarla. Si la fecha es futura, la persona conserva el acceso hasta ese día. Desde la fecha de baja ya no hay días previstos, algo que ninguna de las dos versiones resolvía. La reincorporación pide la fecha de vuelta y guarda el periodo intermedio como interrupción, para que esos días no cuenten como faltas. El responsable no puede darse de baja a sí mismo y la cuenta técnica queda fuera. |
| Editar el alta | Se pueden corregir el nombre y el fin de contrato, con motivo y con el antes y el después en el historial de la ficha. |
| Filtro del estado del equipo | Cada contador del panel filtra la lista; «Ver todos» la restablece. |

## No incorporado y por qué

| Propuesta | Motivo |
| --- | --- |
| Copiar `ends_on` en `baja_on` al migrar | La migración borraba la fecha de fin de contrato de las cuentas inactivas. Aquí la columna nueva se añade sin mover datos. |
| Cambiar desde «Editar alta» la fecha de inicio, la sede, el tipo de jornada o el correo | Reescribía el primer horario y la sede hacia atrás, de modo que cambiaban las horas previstas y los festivos de días ya pasados. El correo se cambia desde Mi cuenta con verificación; la sede y el horario tienen sus formularios con fecha de efecto. |
| Corregir a mano un festivo de un calendario cargado | Modificaba la copia oficial sin dejar autor ni motivo, la seguía presentando como oficial y no recalculaba lo ya generado en esa fecha. |
| Tipo de jornada «Completa» | Tiene las mismas horas que «Partida» y mezcla la dedicación con el reparto del horario. |
| Cambios de Gestión y del alta | El municipio fijo en la pantalla no protegía nada en el servidor, y el formulario del alta dejaba de funcionar sin JavaScript. Con una sola sede aportaban poco. |
| Quitar la cuenta de prueba del inicio de sesión | En esta versión ese bloque solo aparece en entornos `local` y `testing`. |

## Actualizar una copia anterior

1. Conserva tu `.env` y tu base de datos. Sustituye el resto del código por el de este ZIP.
2. Ejecuta `composer install`, `npm ci --ignore-scripts` y `npm run build`.
3. Ejecuta `php artisan migrate`. Las migraciones nuevas añaden columnas y tablas, convierten los roles `jefa` y `trabajadora` a `jefe` y `trabajador` y crean un índice que impide dos cuentas con el mismo correo escrito con distintas mayúsculas. Si tu base tiene correos repetidos de esa forma, corrígelos antes. No borran registros.
4. Comprueba la instalación con `composer test`.

En una base de pruebas, el responsable debe confirmar el saldo de vacaciones de cada persona y año antes de que se puedan pedir días.
