<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Illuminate\Http\Request;

/**
 * Verifies webhook signatures per the Standard Webhooks spec (standardwebhooks.com),
 * used by OpenAI: HMAC-SHA256 over "{webhook-id}.{webhook-timestamp}.{body}", base64,
 * sent as "v1,<signature>" (possibly several, space-separated) in the webhook-signature
 * header. The timestamp check rejects replayed requests.
 */
final class StandardWebhookVerifier
{
    public static function verify(Request $request, string $secret, int $tolerance = 300): bool
    {
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $signatures = (string) $request->header('webhook-signature');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false;
        }

        // OpenAI secrets come as "whsec_<base64>"; the raw key is the decoded part
        $key = str_starts_with($secret, 'whsec_')
            ? (string) base64_decode(substr($secret, 6), true)
            : $secret;

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}." . $request->getContent(), $key, true));

        foreach (explode(' ', $signatures) as $candidate) {
            [$version, $sig] = array_pad(explode(',', $candidate, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
