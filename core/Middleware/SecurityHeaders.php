<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;

class SecurityHeaders
{
    /**
     * Menyuntikkan HTTP Security Headers standar industri OWASP ke setiap response
     *
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // 1. Anti-Clickjacking: Cegah website dimuat di dalam iframe situs luar
        $response->header('X-Frame-Options', 'SAMEORIGIN');

        // 2. Anti-MIME Sniffing: Paksa browser mematuhi Content-Type yang dideklarasikan
        $response->header('X-Content-Type-Options', 'nosniff');

        // 3. Referrer Policy: Lindungi data path URL saat navigasi keluar
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 4. Permissions Policy: Batasi akses sensor/hardware berbahaya kecuali diizinkan
        $response->header('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self), payment=(self)');

        // 5. X-XSS-Protection dinonaktifkan (standar modern OWASP merekomendasikan '0' karena filter lama menimbulkan celah XSS baru)
        $response->header('X-XSS-Protection', '0');

        // 6. Content-Security-Policy (CSP) yang kokoh dan mendukung Google Fonts & data URI
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self';";
        $response->header('Content-Security-Policy', $csp);

        // 7. Strict-Transport-Security (HSTS) jika diakses via HTTPS
        if ($request->isSecure() || $request->header('X-Forwarded-Proto') === 'https') {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
