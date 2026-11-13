<?php

namespace Tests\Feature;

use App\Mail\OlvidoDeInicio;
use App\Models\Ausencia;
use App\Models\Aviso;
use App\Models\Horario;
use App\Models\ImportacionCalendario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Services\JornadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AvisoPorCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_no_avisa_antes_de_pasar_los_minutos_de_margen(): void
    {
        $this->persona();

        $this->ejecutarA('2026-10-07 09:19:00');

        Mail::assertNothingSent();
    }

    public function test_avisa_una_sola_vez_a_quien_no_ha_empezado(): void
    {
        $ana = $this->persona();

        $this->ejecutarA('2026-10-07 09:20:00');
        $this->ejecutarA('2026-10-07 09:25:00');
        $this->ejecutarA('2026-10-07 11:00:00');

        Mail::assertSent(OlvidoDeInicio::class, 1);
        Mail::assertSent(
            OlvidoDeInicio::class,
            fn (OlvidoDeInicio $correo) => $correo->hasTo($ana->email)
                && $correo->horaPrevista === '09:00'
                && str_contains($correo->render(), 'Tu jornada empezaba a las 09:00'),
        );
        $this->assertNotNull(Aviso::where('user_id', $ana->id)->where('tipo', 'inicio')->first()->correo_enviado_at);
    }

    public function test_el_correo_va_con_copia_a_la_jefa_activa(): void
    {
        $ana = $this->persona();
        $marta = $this->persona(['role' => 'jefa']);
        $this->persona(['role' => 'jefa', 'active' => false]);
        $this->fichar($marta);

        $this->ejecutarA('2026-10-07 09:30:00');

        Mail::assertSent(OlvidoDeInicio::class, 1);
        Mail::assertSent(
            OlvidoDeInicio::class,
            fn (OlvidoDeInicio $correo) => $correo->hasTo($ana->email)
                && $correo->hasCc($marta->email)
                && count($correo->cc) === 1
                && str_contains($correo->render(), 'con copia, a tu responsable'),
        );
    }

    public function test_la_jefa_que_se_olvida_no_se_copia_a_si_misma(): void
    {
        $marta = $this->persona(['role' => 'jefa']);

        $this->ejecutarA('2026-10-07 09:30:00');

        Mail::assertSent(
            OlvidoDeInicio::class,
            fn (OlvidoDeInicio $correo) => $correo->hasTo($marta->email)
                && $correo->cc === []
                && ! str_contains($correo->render(), 'con copia'),
        );
    }

    public function test_no_avisa_a_quien_ya_ha_fichado(): void
    {
        $ana = $this->persona();
        $this->fichar($ana);

        $this->ejecutarA('2026-10-07 09:30:00');

        Mail::assertNothingSent();
    }

    public function test_no_crea_aviso_ni_envia_correo_si_el_calendario_no_esta_confirmado(): void
    {
        $ana = $this->persona();
        ImportacionCalendario::query()->where('centro_id', $ana->centro_id)->delete();

        $this->ejecutarA('2026-10-07 09:30:00');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('avisos', 0);
        $this->assertSame('desconocido', app(JornadaService::class)->expectativaDe($ana, now())['estado']);
    }

    public function test_no_avisa_en_dias_sin_fichaje_esperado(): void
    {
        $conAusencia = $this->persona();
        Ausencia::create(['user_id' => $conAusencia->id, 'absence_date' => '2026-10-07', 'tipo' => 'vacaciones']);

        $this->ejecutarA('2026-10-07 09:30:00');
        $this->ejecutarA('2026-10-10 09:30:00');

        Mail::assertNothingSent();
    }

    public function test_no_avisa_a_quien_esta_de_baja_ni_despues_del_horario(): void
    {
        $this->persona(['active' => false]);

        $this->ejecutarA('2026-10-07 09:30:00');
        $this->assertSame(0, Aviso::count());

        $this->persona();
        $this->ejecutarA('2026-10-07 18:00:00');

        Mail::assertNothingSent();
    }

    public function test_si_el_correo_falla_se_reintenta_en_la_siguiente_pasada(): void
    {
        $ana = $this->persona();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('sin conexión'));

        $this->ejecutarA('2026-10-07 09:30:00');

        $this->assertNull(Aviso::where('user_id', $ana->id)->where('tipo', 'inicio')->first()->correo_enviado_at);
    }

    public function test_si_el_servidor_rechaza_la_copia_la_persona_recibe_el_aviso_igualmente(): void
    {
        $ana = $this->persona();
        $this->persona(['role' => 'jefa']);

        $conCopia = Mockery::mock(PendingMail::class);
        $conCopia->shouldReceive('cc')->once()->andReturnSelf();
        $conCopia->shouldReceive('send')->once()->andThrow(new RuntimeException('dirección rechazada'));
        $sinCopia = Mockery::mock(PendingMail::class);
        $sinCopia->shouldReceive('send')->once();
        Mail::shouldReceive('to')->with($ana->email)->twice()->andReturn($conCopia, $sinCopia);
        Mail::shouldReceive('to')->with(Mockery::not($ana->email))->andReturn($sinCopia);

        $this->ejecutarA('2026-10-07 09:30:00');

        $this->assertNotNull(Aviso::where('user_id', $ana->id)->where('tipo', 'inicio')->first()->correo_enviado_at);
    }

    public function test_el_comando_de_prueba_manda_el_correo_a_la_direccion_indicada(): void
    {
        $this->artisan('jornada:probar-correo', ['destino' => 'trabajadora@ejemplo.es'])->assertSuccessful();

        Mail::assertSent(
            OlvidoDeInicio::class,
            fn (OlvidoDeInicio $correo) => $correo->hasTo('trabajadora@ejemplo.es'),
        );
    }

    public function test_el_comando_de_prueba_rechaza_una_direccion_invalida(): void
    {
        $this->artisan('jornada:probar-correo', ['destino' => 'esto-no-es-un-correo'])->assertFailed();

        Mail::assertNothingSent();
    }

    private function fichar(User $persona): void
    {
        $jornada = Jornada::create(['user_id' => $persona->id, 'work_date' => '2026-10-07']);
        Tramo::create(['jornada_id' => $jornada->id, 'tipo' => Tramo::TRABAJO, 'started_at' => '2026-10-07 09:05:00']);
    }

    private function ejecutarA(string $momento): void
    {
        Carbon::setTestNow(Carbon::parse($momento, 'Europe/Madrid'));
        $this->artisan('jornada:avisar-inicio')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function persona(array $datos = []): User
    {
        $persona = User::factory()->create($datos);
        $this->calendarioConfirmado($persona->sede, 2026);
        Horario::create([
            'user_id' => $persona->id,
            'morning_start' => '09:00',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);

        return $persona;
    }
}
