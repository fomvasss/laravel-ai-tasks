<?php

namespace Fomvasss\AiTasks\Contracts;

use Illuminate\Http\Request;
use Fomvasss\AiTasks\DTO\WebhookPayload;

interface AcceptsWebhooks
{
    /**
     * Check signature/authorization. Except 401/403 if fail.
     */
    public function verifyWebhook(Request $request): void;

    /**
     * Unification payload.
     */
    public function parseWebhook(Request $request): WebhookPayload;
}