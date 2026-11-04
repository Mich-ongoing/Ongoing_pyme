<?php

namespace App\Facturacion\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Un usuario con rol «facturacion» solo puede ver la facturación (el resto del panel tiene llave de admin de todas las webs). */
class SoloFacturacion
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'facturacion' && ! $request->routeIs('facturacion.*', 'logout')) {
            return $request->expectsJson() ? abort(403) : redirect()->route('facturacion.index');
        }

        return $next($request);
    }
}
