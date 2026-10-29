<?php

namespace Tests\Unit;

use App\Services\Payment\SePayService;
use Illuminate\Http\Request;
use Tests\TestCase;

class SePayServiceTest extends TestCase
{
    public function test_it_verifies_the_official_hmac_format_and_rejects_tampering(): void
    {
        config(['sepay.webhook_secret' => 'test-secret', 'sepay.timestamp_tolerance' => 300]);
        $timestamp = now()->timestamp;
        $body = '{"id":92704,"transferAmount":5000000}';
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'test-secret');
        $request = Request::create('/webhook/sepay', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => $signature,
            'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp,
        ], $body);

        $service = app(SePayService::class);
        $this->assertTrue($service->verifySignature($request));

        $tampered = Request::create('/webhook/sepay', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => $signature,
            'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp,
        ], str_replace('5000000', '9000000', $body));
        $this->assertFalse($service->verifySignature($tampered));
    }

    public function test_it_extracts_only_the_configured_payment_prefix(): void
    {
        config(['sepay.code_prefix' => 'DH']);
        $service = app(SePayService::class);

        $this->assertSame(123456, $service->extractCode(['code' => 'DH123456', 'content' => '']));
        $this->assertSame(9988, $service->extractCode(['code' => null, 'content' => 'Thanh toan dh9988 cam on']));
        $this->assertSame(55667788, $service->extractCode(['code' => 'DH55667788', 'content' => 'NGUYEN VAN A DH55667788 FT25']));
        $this->assertNull($service->extractCode(['code' => 'XX123456', 'content' => 'khong co ma']));
        $this->assertNull($service->extractCode(['code' => 'DHABC', 'content' => '']));
    }

    public function test_the_official_webhook_uses_the_main_domain_api_path(): void
    {
        $route = app('router')->getRoutes()->getByName('webhook.sepay');

        $this->assertNotNull($route);
        $this->assertSame('api/webhook/sepay', $route->uri());
        $this->assertContains('POST', $route->methods());
    }
}
