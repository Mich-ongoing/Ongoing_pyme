<?php

namespace App\Webminder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebminderSignature
{
    const TIMESTAMP_TOLERANCE = 900; // 15 minutos

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Webminder-Signature');
        $timestamp = $request->header('X-Webminder-Timestamp');

        // Validar presencia de headers
        if (!$signature || !$timestamp) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required headers: X-Webminder-Signature, X-Webminder-Timestamp'
            ], 401);
        }

        // Validar timestamp (evitar replay attacks)
        $now = time();
        if (abs($now - (int)$timestamp) > self::TIMESTAMP_TOLERANCE) {
            return response()->json([
                'success' => false,
                'message' => 'Request timestamp outside tolerance window'
            ], 401);
        }

        // Obtener credenciales del cliente
        $clientId = config('webminder.client_id');
        $clientSecret = config('webminder.client_secret');

        if (!$clientId || !$clientSecret) {
            return response()->json([
                'success' => false,
                'message' => 'Webminder not configured on this server'
            ], 403);
        }

        // Reconstruir la firma esperada
        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload . $timestamp, $clientSecret);

        // Comparación timing-safe
        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature'
            ], 401);
        }

        return $next($request);
    }
}
