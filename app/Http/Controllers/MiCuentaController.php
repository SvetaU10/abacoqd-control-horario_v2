<?php

namespace App\Http\Controllers;

use App\Mail\ConfirmarCambioCorreo;
use App\Models\User;
use App\Services\CorreoEntregaReal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MiCuentaController extends Controller
{
    public function index(Request $request): View
    {
        return view('cuenta.index', ['persona' => $request->user()]);
    }

    public function solicitar(Request $request, CorreoEntregaReal $correo): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'current_password' => ['required', 'string'],
        ]);
        $nuevo = Str::lower(trim($datos['email']));
        if (str_ends_with($nuevo, '.invalid')) {
            throw ValidationException::withMessages(['email' => 'Indica una dirección real que puedas consultar.']);
        }
        if (app()->environment('production') && ! $correo->disponible((string) config('mail.default'))) {
            return back()->with('aviso', 'La confirmación por correo no está disponible temporalmente.');
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $usuarioId = $request->user()->id;
        DB::transaction(function () use ($datos, $nuevo, $hash, $usuarioId): void {
            $persona = User::query()->whereKey($usuarioId)->lockForUpdate()->firstOrFail();
            if (! $persona->active || ! Hash::check($datos['current_password'], $persona->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
            }
            if (User::query()->whereKeyNot($persona->id)->whereRaw('LOWER(email) = ?', [$nuevo])->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta dirección ya está en uso.']);
            }
            if (Str::lower($persona->email) === $nuevo && $persona->correo_habilitado) {
                throw ValidationException::withMessages(['email' => 'Ese ya es tu correo de acceso.']);
            }
            DB::table('cambios_correo_pendientes')->updateOrInsert(['user_id' => $persona->id], [
                'correo_nuevo' => $nuevo, 'token_hash' => $hash, 'vence_at' => now()->addHour(),
                'password_version_hash' => hash('sha256', $persona->password),
                'correo_original_hash' => hash('sha256', Str::lower($persona->email)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        // El token viaja en el fragmento: no se incluye en la URL que registran los servidores.
        $enlace = url(route('mi-cuenta.correo.confirmar', [], false)).'#'.$token;
        try {
            Mail::to($nuevo)->send(new ConfirmarCambioCorreo($enlace));
        } catch (\Throwable) {
            DB::table('cambios_correo_pendientes')->where('user_id', $usuarioId)->where('token_hash', $hash)->delete();
            Log::warning('No se pudo enviar una confirmación de cambio de correo.');

            return back()->with('aviso', 'No se pudo enviar la confirmación. Inténtalo más tarde.');
        }

        return redirect()->route('mi-cuenta')->with('ok', 'Hemos enviado un enlace al correo nuevo. Tu acceso actual seguirá activo hasta confirmarlo.');
    }

    public function confirmar(): View
    {
        return view('cuenta.confirmar-correo');
    }

    public function aplicar(Request $request): RedirectResponse
    {
        $token = $request->input('token');
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return redirect()->route('mi-cuenta')->with('aviso', 'El enlace no es válido o ha caducado.');
        }
        try {
            $cambiado = DB::transaction(function () use ($request, $token): bool {
                $persona = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $pendiente = DB::table('cambios_correo_pendientes')->where('user_id', $persona->id)->lockForUpdate()->first();
                if (! $persona->active || ! $pendiente || now()->greaterThanOrEqualTo($pendiente->vence_at)
                    || ! hash_equals($pendiente->token_hash, hash('sha256', $token))
                    || ! hash_equals($pendiente->password_version_hash, hash('sha256', $persona->password))
                    || ! hash_equals($pendiente->correo_original_hash, hash('sha256', Str::lower($persona->email)))) {
                    return false;
                }
                $nuevo = $pendiente->correo_nuevo;
                if (User::query()->whereKeyNot($persona->id)->whereRaw('LOWER(email) = ?', [$nuevo])->exists()) {
                    return false;
                }
                $anterior = $persona->email;
                $persona->forceFill(['email' => $nuevo, 'correo_habilitado' => true, 'email_verified_at' => now(),
                    'remember_token' => Str::random(60)])->save();
                DB::table('eventos_cambio_correo')->insert([
                    'user_id' => $persona->id, 'correo_anterior' => $anterior, 'correo_nuevo' => $nuevo, 'created_at' => now(),
                ]);
                DB::table(config('auth.passwords.users.table'))->whereIn('email', [$anterior, $nuevo])->delete();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table'))
                        ->where('user_id', $persona->id)->where('id', '!=', $request->session()->getId())->delete();
                }
                DB::table('cambios_correo_pendientes')->where('user_id', $persona->id)->delete();

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $cambiado = false;
        }
        if (! $cambiado) {
            return redirect()->route('mi-cuenta')->with('aviso', 'El enlace no es válido, ha caducado o el correo ya está en uso.');
        }
        $request->session()->regenerate(true);

        return redirect()->route('mi-cuenta')->with('ok', 'Correo confirmado. Ya puedes usarlo para acceder.');
    }
}
