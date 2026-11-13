<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5; color: #061B20;">
    <p>Hay un ticket nuevo.</p>

    <p>
        <strong>{{ $ticket->asunto }}</strong><br>
        Lo ha abierto {{ $ticket->autor->name }}.
    </p>

    <p>
        <a href="{{ route('tickets.show', $ticket) }}" style="color: #066A75; font-weight: bold;">Abrir el ticket</a>
    </p>

    <p style="font-size: 13px; color: #4B6B6B;">
        AbacoQD. Guardamos tu nombre, tu horario y las horas de la jornada. No guardamos ubicación.
    </p>
</body>
</html>
