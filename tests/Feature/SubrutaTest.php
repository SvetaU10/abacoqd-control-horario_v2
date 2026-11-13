<?php

namespace Tests\Feature;

use App\Mail\RecuperarClave;
use App\Models\Ticket;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;
use Tests\TestCase;

class SubrutaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://abacoqd.com/controlhorario';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::BASE,
            'app.asset_url' => null,
            'app.access_until' => null,
            'session.cookie' => 'abacoqd_controlhorario_session',
            'session.path' => '/controlhorario',
            'session.domain' => null,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
        Mail::fake();
    }

    public function test_el_arranque_http_conserva_el_prefijo_para_rutas_y_recursos(): void
    {
        // El proveedor se arranca en contexto HTTP, no en el arranque de consola
        // que usa PHPUnit. Reproduce SCRIPT_NAME del montaje de LiteSpeed.
        $request = Request::create(self::BASE.'/login', 'GET', server: $this->servidor());
        $this->app->instance('request', $request);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->assertSame('/controlhorario', $request->getBaseUrl());
            $this->assertSame(self::BASE.'/login', route('login'));
            $this->assertSame(self::BASE.'/marca/logos/abacoqd_isotipo.svg', asset('marca/logos/abacoqd_isotipo.svg'));
        } finally {
            $console->setValue($this->app, true);
        }
    }

    public function test_login_formularios_recursos_y_redirecciones_se_quedan_en_la_subruta(): void
    {
        $this->peticion('GET', '/registro')->assertRedirect(self::BASE.'/login');

        $this->peticion('GET', '/login')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('action="'.self::BASE.'/login"', false)
            ->assertSee('href="'.self::BASE.'/clave"', false)
            ->assertSee('src="'.self::BASE.'/js/reloj.js', false)
            ->assertSee('href="'.self::BASE.'/build/assets/', false)
            ->assertDontSee('https://abacoqd.com/build/', false);

        $persona = User::factory()->create(['password' => 'ClavePruebaSubruta']);
        $this->peticion('POST', '/login', ['email' => $persona->email, 'password' => 'ClavePruebaSubruta'])
            ->assertRedirect(self::BASE);
        $this->peticion('GET', '/login')->assertRedirect(self::BASE);
        $this->peticion('POST', '/logout')->assertRedirect(self::BASE.'/login');
    }

    public function test_recuperar_clave_genera_un_enlace_completo_sin_duplicar_el_prefijo(): void
    {
        $persona = User::factory()->create();
        $this->peticion('POST', '/clave', ['email' => $persona->email], [
            'HTTP_REFERER' => self::BASE.'/clave',
        ])->assertRedirect(self::BASE.'/clave');

        Mail::assertSent(RecuperarClave::class, function (RecuperarClave $correo) use ($persona): bool {
            $this->assertStringStartsWith(self::BASE.'/clave/', $correo->enlace);
            $this->assertStringNotContainsString('/controlhorario/controlhorario/', $correo->enlace);
            $this->peticion('GET', substr($correo->enlace, strlen(self::BASE)))
                ->assertOk()->assertSee('action="'.self::BASE.'/clave/nueva"', false);

            return $correo->hasTo($persona->email);
        });
    }

    public function test_la_paginacion_los_enlaces_y_las_acciones_autenticadas_conservan_la_subruta(): void
    {
        $persona = User::factory()->create();
        Ticket::factory()->count(26)->for($persona, 'autor')->create();
        $this->actingAs($persona);

        $this->peticion('GET', '/tickets?todas_incidencias=1')->assertOk()
            ->assertSee(self::BASE.'/tickets?todas_incidencias=1&amp;page=2', false)
            ->assertSee('action="'.self::BASE.'/logout"', false)
            ->assertSee('href="'.self::BASE.'/registro"', false);
        $this->peticion('GET', '/tickets?todas_incidencias=1&page=2')->assertOk()
            ->assertSee('Página 2 de 2');
    }

    public function test_los_enlaces_de_correo_generados_desde_consola_conservan_la_subruta(): void
    {
        (new SetRequestForConsole)->bootstrap($this->app);

        $persona = User::factory()->create();
        $correo = new RecuperarClave($persona, 'token-de-prueba');

        $this->assertSame(self::BASE.'/clave/token-de-prueba?email='.urlencode($persona->email), $correo->enlace);
        $this->assertSame(self::BASE, route('jornada'));
    }

    public function test_la_sesion_es_propia_y_no_se_emite_otra_cookie_xsrf_del_cms(): void
    {
        $respuesta = $this->peticion('GET', '/login');
        $cookies = collect($respuesta->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());

        $this->assertFalse($cookies->has('XSRF-TOKEN'));
        $this->assertTrue($cookies->has('abacoqd_controlhorario_session'));
        $sesion = $cookies->get('abacoqd_controlhorario_session');
        $this->assertSame('/controlhorario', $sesion->getPath());
        $this->assertNull($sesion->getDomain());
        $this->assertTrue($sesion->isSecure());
        $this->assertTrue($sesion->isHttpOnly());
        $this->assertSame('lax', $sesion->getSameSite());
    }

    public function test_csrf_se_valida_aunque_lleguen_cookies_de_la_web_principal(): void
    {
        // Desactivar únicamente la excepción de pruebas de CSRF; la BD sigue
        // siendo SQLite en memoria y Mail permanece simulado.
        $this->app->instance('env', 'production');
        $persona = User::factory()->create(['password' => 'ClavePruebaSubruta']);
        $this->withSession(['_token' => 'token-propio-control-horario']);
        $cookies = ['XSRF-TOKEN' => 'cookie-del-cms', 'abacoqd_session' => 'sesion-del-cms'];
        $datos = ['email' => $persona->email, 'password' => 'ClavePruebaSubruta'];

        $this->peticion('POST', '/login', $datos, [], $cookies)->assertStatus(419);
        $this->assertGuest();
        $this->peticion('POST', '/login', $datos + ['_token' => 'token-propio-control-horario'], [], $cookies)
            ->assertRedirect(self::BASE);
        $this->assertAuthenticatedAs($persona);
    }

    public function test_un_despliegue_en_la_raiz_sigue_generando_enlaces_correctos(): void
    {
        URL::forceRootUrl(null);
        $this->get('https://horario.example.test/login')->assertOk()
            ->assertSee('action="https://horario.example.test/login"', false)
            ->assertSee('href="https://horario.example.test/clave"', false);
    }

    /** @return array<string, mixed> */
    private function servidor(): array
    {
        return [
            'SCRIPT_NAME' => '/controlhorario/index.php',
            'SCRIPT_FILENAME' => '/srv/abacoqd.com/public_html/controlhorario/index.php',
            'PHP_SELF' => '/controlhorario/index.php',
            'HTTP_HOST' => 'abacoqd.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => 443,
        ];
    }

    /** @param array<string, mixed> $datos
     * @param  array<string, mixed>  $servidor
     * @param  array<string, string>  $cookies
     */
    private function peticion(string $metodo, string $ruta, array $datos = [], array $servidor = [], array $cookies = []): TestResponse
    {
        return $this->call($metodo, self::BASE.$ruta, $datos, $cookies, [], array_replace($this->servidor(), $servidor));
    }
}
