<?php
namespace App\Services;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
class Telemetry {
 public static function run(string $name, callable $fn, array $carrier = []): mixed {
   $parent = $carrier ? Globals::propagator()->extract($carrier) : \OpenTelemetry\Context\Context::getCurrent();
   $span = Globals::tracerProvider()->getTracer('orders')->spanBuilder($name)->setParent($parent)->startSpan();
   $scope = $span->activate();
   try { return $fn(); } catch (\Throwable $e) { $span->setStatus(StatusCode::STATUS_ERROR); throw $e; }
   finally { $scope->detach(); $span->end(); }
 }
 public static function carrier(): array { $c=[]; Globals::propagator()->inject($c); return $c; }
}
