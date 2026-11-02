<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
 ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
 ->withMiddleware(function (Middleware $m) {
   // Only deploy behind the private app SG, whose only ingress is the ALB SG.
   $m->trustProxies(at: '*');
   $m->api(prepend: [App\Http\Middleware\TraceRequest::class]);
   $m->alias(['identity' => App\Http\Middleware\Identity::class]);
 })->withExceptions(function (Exceptions $e) {
   $e->shouldRenderJsonWhen(fn () => true);
 })->create();
