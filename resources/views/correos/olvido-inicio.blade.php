<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5; color: #061B20;">
    <p>Hola, {{ $persona->name }}.</p>

    <p>Tu jornada empezaba a las {{ $horaPrevista }} y todavía no hay nada anotado.</p>

    <p>
        Si ya estabas trabajando, entra y anótalo.
        Puedes poner la hora a la que empezaste.
    </p>

    <p>
        <a href="{{ route('jornada') }}" style="color: #066A75; font-weight: bold;">Abrir Control horario de AbacoQD</a>
    </p>

    <p>Si hoy no te tocaba trabajar, díselo a tu responsable.</p>

    @if ($conCopia)
        <p>Este aviso va también, con copia, a tu responsable.</p>
    @endif

    <p style="font-size: 13px; color: #4B6B6B;">
        AbacoQD. Guardamos tu nombre, tu horario y las horas de la jornada. No guardamos ubicación.
    </p>
</body>
</html>
