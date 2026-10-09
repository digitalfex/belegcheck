<?php

use App\Api\Problem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hinter Caddy (HTTPS) auf demselben Rechner: Links in Antworten mit https und richtigem Host
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Schnittstelle: alle Fehler als application/problem+json (RFC 9457)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return Problem::antwort(422, 'Ungültige Anfrage', 'Mindestens ein Feld ist ungültig.', 'ungueltige-anfrage', ['fehler' => $e->errors()]);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return Problem::antwort(429, 'Zu viele Anfragen', 'Bitte etwas warten.', 'zu-viele-anfragen')->withHeaders($e->getHeaders());
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return Problem::antwort($e->getStatusCode(), match ($e->getStatusCode()) {
                    404 => 'Nicht gefunden', 405 => 'Methode nicht erlaubt', 413 => 'Datei zu groß', default => 'Fehler',
                }, null)->withHeaders($e->getHeaders());
            }
        });
    })->create();
