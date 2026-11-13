<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class ProtectFormRequests extends PreventRequestForgery
{
    // Los formularios Blade envían su propio _token. No publicar una segunda
    // cookie XSRF-TOKEN que pueda interferir con la web corporativa del dominio.
    protected $addHttpCookie = false;
}
