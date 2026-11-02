<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\Telemetry;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
class TelemetryTest extends TestCase {
 public function test_serialized_carrier_preserves_trace_after_request_ends():void {
   $exporter=new InMemoryExporter();$provider=new TracerProvider(new SimpleSpanProcessor($exporter));
   $scope=Configurator::create()->withTracerProvider($provider)->withPropagator(TraceContextPropagator::getInstance())->activate();
   try {
     $carrier=Telemetry::run('request',fn()=>Telemetry::carrier());
     $this->assertArrayHasKey('traceparent',$carrier);
     $wire=json_decode(json_encode($carrier),true);
     Telemetry::run('job',fn()=>true,$wire);
     $spans=$exporter->getSpans();$this->assertCount(2,$spans);
     $this->assertSame($spans[0]->getContext()->getTraceId(),$spans[1]->getContext()->getTraceId());
     $this->assertSame($spans[0]->getContext()->getSpanId(),$spans[1]->getParentContext()->getSpanId());
   }finally{$scope->detach();$provider->shutdown();}
 }
}
