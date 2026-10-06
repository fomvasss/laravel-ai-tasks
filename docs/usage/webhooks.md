# Async providers & webhooks

For providers that finish work out-of-band (batch APIs, long-running generations), a run can wait for a webhook instead of blocking a worker. The flow is app-driven:

1. Your code submits the provider job, then parks the run:

   ```php
   $run->markWaiting(['provider_run_id' => $providerJobId]);
   ```

   The run gets status `waiting`.

2. The provider calls `POST /ai-webhooks/{driver}`. The handler registered for that driver verifies the request and returns a `WebhookPayload`; the package finds the waiting run of that driver by `provider_run_id` and finishes it — `ok` on `succeeded`, `error` otherwise.

3. If the task can be reconstructed, a `PostprocessAiResult` job is dispatched — `postprocess()`, `isAcceptable()`/retries and `onCompleted()` run exactly as on the normal queued path. Reconstruction needs `task_class` (stored automatically) and the constructor arguments (stored only with `AI_STORE_REQUEST=true`), unless the constructor has no required parameters. Otherwise the run is finished as-is and a warning is logged.

The route uses `webhook_middleware` from the config (`['api']` by default). Responses: `404` with `driver_webhook_not_registered` or `run_not_found`, otherwise `{"ok": true}`.

## OpenAI

A handler for `openai` is registered out of the box. It verifies [Standard Webhooks](https://www.standardwebhooks.com/) signatures (`webhook-id`/`webhook-timestamp`/`webhook-signature` headers, HMAC-SHA256, ±5 min replay tolerance) with the `whsec_...` secret from the OpenAI dashboard webhook settings:

```env
OPENAI_WEBHOOK_SECRET=whsec_...
```

> [!WARNING]
> Without a configured `webhook.secret` the endpoint accepts unsigned requests. Set the secret in production.

## Custom handler

Register a handler for another driver in a service provider. It receives the request and returns `Fomvasss\AiTasks\DTO\WebhookPayload`:

```php
use Fomvasss\AiTasks\DTO\WebhookPayload;
use Fomvasss\AiTasks\Support\StandardWebhookVerifier;
use Fomvasss\AiTasks\Support\WebhookRegistry;
use Illuminate\Http\Request;

public function boot(): void
{
    app(WebhookRegistry::class)->extend('acme', function (Request $r): WebhookPayload {
        abort_unless(StandardWebhookVerifier::verify($r, config('services.acme.webhook_secret')), 401);

        return new WebhookPayload(
            providerRunId: $r->input('job_id'),
            status: $r->input('status'),        // 'succeeded' finishes the run as ok
            content: $r->input('output'),       // string, or anything json-encodable
            usage: $r->input('usage', []),
            error: $r->input('error.message'),
        );
    });
}
```

`StandardWebhookVerifier::verify($request, $secret, $tolerance = 300)` is reusable for any provider using the Standard Webhooks scheme. For a provider with its own scheme, verify inside the closure with that scheme.
