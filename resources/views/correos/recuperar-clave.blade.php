<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5; color: #061B20;">
    <p>Hola, {{ $persona->name }}.</p>

    <p>Hemos recibido una petición para cambiar la contraseña de tu acceso a Control horario de AbacoQD.</p>

    <p>
        <a href="{{ $enlace }}" style="color: #066A75; font-weight: bold;">Elegir una contraseña nueva</a>
    </p>

    <p>El enlace caduca en una hora. Si no has sido tú, no hagas nada: tu contraseña no cambia.</p>

    <p style="font-size: 13px; color: #4B6B6B;">
        AbacoQD. Guardamos tu nombre, tu horario y las horas de la jornada. No guardamos ubicación.
    </p>
</body>
</html>
