# Calendario oficial de Madrid capital, 2026–2027

Revisión de fuentes: 6 de noviembre de 2026. Aplicación exclusiva al municipio INE **28079**, no a todos los municipios de la comunidad.

La preparación utiliza dos copias JSON versionadas en `resources/data/calendarios/`, con catorce fechas distintas cada una: doce generales y dos municipales. Valida año, territorio, fechas, ámbitos, locales y huella SHA-256 del contenido canónico. Un fichero recortado o modificado no se activa. Las referencias, fecha de comprobación y huella de la copia quedan guardadas en `importaciones_calendario.fuentes`; cada festivo conserva su procedencia. No se necesitan APIs ni red para instalar o consultar estas copias.

## Preparación explícita

```sh
php artisan abaco:preparar-calendario-madrid --centro=ID --autor=ID --desde=2026-11-06 --hasta-anio=2027
```

Sustituir los ID por un centro configurado como Madrid capital y una cuenta activa de jefe o técnico. `--desde` es obligatorio: se debe indicar la fecha real de puesta en marcha, no copiar la fecha de este ejemplo por defecto. El instalador también puede invocar `CalendarioMadridOficial::preparar($centro, $autor, $desde, 2027)` y recibe las importaciones por año.

El primer año comienza en la fecha elegida y termina el 31 de diciembre. El siguiente cubre del 1 de enero al 31 de diciembre de 2027. Se conserva la copia anual completa para acreditar las catorce fechas, pero los festivos operativos y la cobertura se aplican mediante esas vigencias. No se crean jornadas, horarios, fichajes ni ausencias anteriores. Desde el 6 de noviembre de 2026 quedan el 9 de noviembre y el 7, 8 y 25 de diciembre. Iniciar en diciembre no crea vigencia en noviembre.

Repetir los mismos parámetros reutiliza las filas existentes. Un calendario activo distinto, una vigencia diferente o un ajuste manual aplicable bloquea la preparación completa; los dos años se tratan en una transacción. El comando no ofrece un `--force`: una sustitución requiere revisar y autorizar expresamente el cambio. Los festivos de otro municipio permanecen intactos.

La revisión general `calendario:revisar` sigue consultando sus proveedores. Una respuesta parcial, ausente o fallida conserva el calendario oficial. Una respuesta completa coincidente tampoco reemplaza su procedencia; una discrepancia queda registrada y no cambia la vigencia oficial. Este trabajo no añade una tarea programada ni envía notificaciones.

## Fuentes y clasificación

- [Ayuntamiento de Madrid: calendario oficial 2026](https://www.madrid.es/portales/munimadrid/es/Calendario-oficial-laboral-y-de-dias-inhabiles-y-festivos-2026-para-Madrid/?vgnextchannel=a68fed8a96b06010VgnVCM100000dc0ca8c0RCRD&vgnextoid=b59770d1c3589910VgnVCM100000891ecb1aRCRD), contrastado con [Comunidad de Madrid](https://www.comunidad.madrid/empleo/calendario-laboral-comunidad-madrid-municipios) y [BOE-A-2025-21667](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2025-21667).
- [Ayuntamiento de Madrid: calendario de la ciudad 2027](https://diario.madrid.es/blog/2026/10/10/calendario-laboral-2027-todos-los-dias-festivos-en-la-ciudad-de-madrid/), [Decreto 82/2026 en BOCM](https://www.bocm.es/bocm-20261001-19), [BOE-A-2026-21254](https://www.boe.es/diario_boe/txt.php?id=BOE-A-2026-21254) y [acuerdo del Pleno del 29/09/2026](https://www.madrid.es/UnidadesDescentralizadas/UDCPleno/Actividad/Pleno/2026/2026-09-29/RA_PO_29_09_2026.pdf).

Se conserva la convención del servicio `FestivosBoe`: `*` y `**` son `estado` (nacionales, incluidas las sustituibles que Madrid conserva); `***` es `comunidad`; las dos fiestas de la capital son `pueblo`. Por eso San José y Jueves Santo de 2027 se clasifican como nacionales sustituibles según el BOE, aunque la noticia municipal los llame regional/autonómico. Esto no afirma que todos los festivos `estado` se celebren en todas las comunidades. Los días inhábiles administrativos y los días libres por convenio no se incorporan como festivos laborales generales.

Las copias son una fotografía de las fuentes revisadas en esa fecha. Una corrección oficial posterior requiere una nueva revisión de los datos y huellas; no se traslada automáticamente un festivo de otro año. No hay cobertura preparada para 2028.
