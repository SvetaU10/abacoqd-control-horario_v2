# Instalación y operación

La zona de la aplicación es `Europe/Madrid`. Hace falta una versión de PHP compatible con `composer.lock`, sus extensiones, Composer, Node.js y npm. Los pasos siguientes son la referencia general de instalación desde código.

## Instalar

En la carpeta del proyecto:

1. Copia `.env.example` a `.env`: en PowerShell, `Copy-Item .env.example .env`; en bash, `cp .env.example .env`. Mantén `APP_DEMO=false`. En producción, configura `APP_ENV=production`, `APP_DEBUG=false`, HTTPS y una base propia.
2. Instala las dependencias y genera la clave después de crear `.env`:

   ```bash
   composer install
   php artisan key:generate
   ```

   En el entorno final usa `composer install --no-dev --optimize-autoloader`. Conserva después la clave en la configuración privada; no la regeneres al actualizar.
3. Configura la conexión. Si usas SQLite, crea `database/database.sqlite` vacío cuando aún no exista. Aplica las migraciones y el seeder normal:

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

   El seeder prepara únicamente la sede **Principal, Madrid**, con su municipio INE 28079. No crea personas, horarios, vacaciones, fichajes ni contraseñas de demostración. En producción, estos dos comandos requieren `--force` después de comprobar la base de destino.
4. Crea el acceso administrativo:

   ```bash
   php artisan abaco:crear-administrador correo@dominio.es "Administración"
   ```

   La contraseña se pide dos veces de forma oculta y necesita al menos doce caracteres. No hay una contraseña inicial pública ni se admite pasarla como argumento. La cuenta creada es `jefe`; para la operación técnica exclusiva crea otra con `--rol=tecnico --solo-tecnico`. En las cuentas laborales, indica la fecha acordada mediante `--desde=AAAA-MM-DD`; sin ella no podrán fichar. Si hay varias sedes, indica `--centro=ID` para las cuentas laborales. El comando rechaza un correo que ya tenga cuenta y conserva sus permisos y contraseña.
5. Construye los recursos del navegador desde el archivo de dependencias fijadas:

   ```bash
   npm ci --ignore-scripts
   npm run build
   ```

6. En desarrollo, arranca con `php artisan serve` y abre `http://127.0.0.1:8000/login`. El servidor final debe servir únicamente `public/`, con escritura limitada a `storage/` y `bootstrap/cache/`.
7. Configura el calendario laboral, los horarios y las incorporaciones reales antes de activar los avisos. Crear la cuenta administrativa no le asigna un horario ficticio.

Las altas se guardan en la base de datos de esa instalación; no se exportan al JSON de demostración ni al repositorio.

### Acceso temporal para pruebas

En una instalación aislada se puede fijar `APP_ACCESS_UNTIL` con una fecha ISO 8601 que incluya la zona, por ejemplo `2026-11-07T00:00:00+01:00`. Ese límite permite entrar durante todo el viernes 6 de noviembre de 2026 y cierra el acceso al empezar el sábado, hora de Madrid. Vacío o sin definir mantiene el funcionamiento habitual.

Al llegar el límite se bloquean el inicio de sesión, la recuperación de contraseña y todas las pantallas y operaciones de la aplicación; las sesiones abiertas se cierran en su siguiente petición. También afecta a cuentas nuevas y a bases restauradas: la fecha pertenece a la configuración de la instalación. Los registros se conservan. Una fecha mal formada cierra el acceso por precaución. Después de cambiarla, actualiza la configuración con `php artisan config:cache`. La comprobación de salud `/up` y los recursos estáticos siguen disponibles.

Para recuperar una contraseña, en la pantalla de entrada está «No recuerdo la contraseña». Con `MAIL_MAILER=log`, el correo y su enlace quedan en `storage/logs/laravel.log`, sin enviarse. Configura SMTP para la recuperación real y limita el acceso y la retención de esos registros.

## Demostración separada

Usa otra carpeta o base de datos sin información real. En el `.env` de esa instalación, configura `APP_ENV=local` y `APP_DEMO=true`, y carga la demo expresamente:

```bash
php artisan db:seed --class=DemoSeeder
```

La demo usa personas sintéticas de `database/seeders/data/personas.json` con correos `abaco.test`. Las credenciales ficticias sólo se muestran con la demo habilitada en `local` o `testing`; la demostración está bloqueada en producción. Repetir el seeder no cambia las cuentas existentes. El JSON es una fuente de lectura, no un archivo al que se exporten las altas reales.

## Actualizar

Haz una copia antes de aplicar el código nuevo. Instala las dependencias desde `composer.lock` y `package-lock.json`, sin actualizarlas durante el despliegue:

```bash
composer install
php artisan migrate
npm ci --ignore-scripts
npm run build
```

En producción ejecuta las migraciones con `--force` después de comprobar la base de destino. `migrate` sólo aplica los cambios pendientes y conserva las jornadas guardadas. Mantén el `.env` y la clave de la instalación.

## Copia

Detén la aplicación, las colas y el planificador y espera a que terminen las peticiones. Para SQLite, conserva fuera del repositorio una copia de:

- `database/database.sqlite`.
- El archivo `.env`, que contiene la clave y la conexión privadas.
- Los archivos de `storage/app` que formen parte de la actividad de la instalación, incluidos los adjuntos.

Una copia sólo del código no recupera los fichajes. Para otras bases de datos, utiliza el procedimiento de copia del motor elegido.

## Restaurar

1. Detén la aplicación, las colas y el planificador.
2. Conserva la base actual antes de sustituir `database/database.sqlite` por la copia.
3. Recupera los archivos asociados y comprueba que el `.env` corresponde a esa instalación, con la clave original y la conexión correcta.
4. Vuelve a arrancar y abre un registro conocido para verificar la recuperación.

El administrador técnico puede crear una copia desde Sistema; se guarda en `storage/app/copias`. Esa pantalla también aplica las migraciones y hace antes una copia. Comprueba la restauración y las copias completas en el entorno de destino antes de depender de ellas. El seeder normal no limpia una instalación de pruebas existente.

## Pruebas

```bash
php artisan test
```

Las pruebas usan bases efímeras y correo simulado, sin modificar la base de la instalación. Los casos están descritos en [pruebas.md](pruebas.md); la [revisión de preentrega](revision-preentrega.html#cierre) registra el resultado final y las comprobaciones pendientes del entorno real.
