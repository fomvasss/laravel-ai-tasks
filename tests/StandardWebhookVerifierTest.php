<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Tests;

use Fomvasss\AiTasks\Support\StandardWebhookVerifier;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StandardWebhookVerifierTest extends TestCase
{
    private function sign(string $id, string $timestamp, string $body, string $key): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    private function request(string $id, string $timestamp, string $body, ?string $signature): Request
    {
        $server = [
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        ];
        if ($signature !== null) {
            $server['HTTP_WEBHOOK_SIGNATURE'] = "v1,{$signature}";
        }

        return Request::create('/ai-webhooks/openai', 'POST', [], [], [], $server, $body);
    }

    public function test_valid_signature_with_plain_secret_passes(): void
    {
        $body = '{"data":{"id":"x"}}';
        $ts = (string) now()->getTimestamp();
        $sig = $this->sign('msg_1', $ts, $body, 'my-secret');

        $this->assertTrue(StandardWebhookVerifier::verify($this->request('msg_1', $ts, $body, $sig), 'my-secret'));
    }

    public function test_valid_signature_with_whsec_prefixed_secret_passes(): void
    {
        $body = '{"data":{"id":"x"}}';
        $rawKey = 'raw-key-bytes';
        $secret = 'whsec_' . base64_encode($rawKey);
        $ts = (string) now()->getTimestamp();
        $sig = $this->sign('msg_1', $ts, $body, $rawKey);

        $this->assertTrue(StandardWebhookVerifier::verify($this->request('msg_1', $ts, $body, $sig), $secret));
    }

    public function test_wrong_signature_fails(): void
    {
        $body = '{"data":{"id":"x"}}';
        $ts = (string) now()->getTimestamp();

        $this->assertFalse(StandardWebhookVerifier::verify($this->request('msg_1', $ts, $body, 'bogus'), 'my-secret'));
    }

    public function test_tampered_body_fails(): void
    {
        $body = '{"data":{"id":"x"}}';
        $ts = (string) now()->getTimestamp();
        $sig = $this->sign('msg_1', $ts, $body, 'my-secret');

        $tampered = $this->request('msg_1', $ts, '{"data":{"id":"y"}}', $sig);
        $this->assertFalse(StandardWebhookVerifier::verify($tampered, 'my-secret'));
    }

    public function test_stale_timestamp_fails(): void
    {
        $body = '{"data":{"id":"x"}}';
        $ts = (string) now()->subMinutes(10)->getTimestamp();
        $sig = $this->sign('msg_1', $ts, $body, 'my-secret');

        $this->assertFalse(StandardWebhookVerifier::verify($this->request('msg_1', $ts, $body, $sig), 'my-secret'));
    }

    public function test_missing_headers_fail(): void
    {
        $req = Request::create('/ai-webhooks/openai', 'POST', [], [], [], [], '{}');

        $this->assertFalse(StandardWebhookVerifier::verify($req, 'my-secret'));
    }
}
