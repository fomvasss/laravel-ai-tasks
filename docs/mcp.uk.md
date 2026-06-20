# Інструменти та MCP

[← До README](../README.uk.md) · [English](mcp.md)

## Локальні інструменти

Перевизнач `tools()` у будь-якому таску, щоб передати `Laravel\Ai\Contracts\Tool[]` в `AnonymousAgent`. Інструменти автоматично передаються при `send()`, `stream()` і `queue()`.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ResearchTask extends AiTask
{
    public function tools(): array
    {
        return [
            new class implements Tool {
                public function name(): string        { return 'web_search'; }
                public function description(): string { return 'Search the web for current information.'; }

                public function handle(Request $request): string
                {
                    $query = $request['query'] ?? '';
                    // call your search API here
                    return json_encode(['results' => ["Result for: {$query}"]]);
                }

                public function schema(JsonSchema $schema): array
                {
                    return ['query' => $schema->string('The search query')];
                }
            },
        ];
    }

    public function modality(): string { return 'text'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage('What happened in tech this week?')],
        );
    }
}
```

**Важливо:** анонімний клас, що реалізує `Tool`, повинен мати метод `name()` — без нього `ToolNameResolver` генерує невалідну назву для OpenAI API.

Агент сам вирішує коли і як викликати інструменти. Кожен виклик виконується локально і результат повертається моделі для наступного кроку.

---

## Віддалені MCP-інструменти (Streamable HTTP)

Підключення до будь-якого MCP-сервера зі **Streamable HTTP** ендпоінтом (JSON-RPC 2.0) без встановлення `laravel/mcp`.

### HttpMcpClient

```php
// app/Ai/Mcp/HttpMcpClient.php
class HttpMcpClient
{
    private int $id = 0;

    public function __construct(
        private readonly string $url,
        private readonly string $token = '',
    ) {}

    public function listTools(): array
    {
        return $this->rpc('tools/list')['tools'] ?? [];
    }

    public function readResource(string $uri): string
    {
        $result = $this->rpc('resources/read', ['uri' => $uri]);
        return collect($result['contents'] ?? [])
            ->map(fn($c) => $c['text'] ?? '')
            ->filter()
            ->implode("\n");
    }

    public function callTool(string $name, array $arguments = []): string
    {
        $result  = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        $content = $result['content'] ?? [];
        $isError = $result['isError'] ?? false;
        $text = collect($content)
            ->filter(fn($c) => ($c['type'] ?? '') === 'text')
            ->map(fn($c) => $c['text'] ?? '')
            ->implode("\n");
        if ($isError) {
            throw new \RuntimeException("MCP tool error [{$name}]: {$text}");
        }
        return $text ?: json_encode($result);
    }

    private function rpc(string $method, array $params = []): array
    {
        $http = Http::withHeaders(['Accept' => 'application/json, text/event-stream']);
        if ($this->token !== '') {
            $http = $http->withToken($this->token);
        }

        $body = [
            'jsonrpc' => '2.0',
            'id'      => ++$this->id,
            'method'  => $method,
            'params'  => empty($params) ? (object) [] : $params,
        ];

        $response = $http->withBody(json_encode($body), 'application/json')->post($this->url);
        $raw      = $response->body();

        // Streamable HTTP може повертати SSE: "event: message\ndata: {...}"
        if (str_contains($raw, 'data:')) {
            foreach (explode("\n", $raw) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $raw = trim(substr($line, 5));
                    break;
                }
            }
        }

        $data = json_decode($raw, true) ?? [];

        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? json_encode($data['error']);
            throw new \RuntimeException("MCP error [{$method}]: {$msg}");
        }

        return $data['result'] ?? [];
    }
}
```

### HttpMcpTool

```php
// app/Ai/Mcp/HttpMcpTool.php
class HttpMcpTool implements Tool
{
    public function __construct(
        private readonly HttpMcpClient $client,
        private readonly string $name,
        private readonly string $toolDescription,
        private readonly array $inputSchema,
    ) {}

    public function name(): string        { return $this->name; }
    public function description(): string { return $this->toolDescription; }

    public function handle(Request $request): string
    {
        return $this->client->callTool($this->name, $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        if (empty($this->inputSchema)) return [];
        try {
            $type = \Illuminate\JsonSchema\JsonSchema::fromArray(
                \Laravel\Ai\Schema\SchemaNormalizer::normalize($this->inputSchema)
            );
        } catch (\Throwable) {
            return [];
        }
        return $type instanceof \Illuminate\JsonSchema\Types\ObjectType
            ? (fn(): array => $this->properties)->call($type)
            : [];
    }
}
```

### Приклад таску

```php
class CrmTask extends AiTask
{
    private ?HttpMcpClient $mcpClient = null;

    public function __construct(private readonly string $question) {}

    public function tools(): array
    {
        $client = $this->client();
        return collect($client->listTools())
            ->map(fn(array $t) => new HttpMcpTool(
                client: $client,
                name: $t['name'],
                toolDescription: $t['description'] ?? $t['name'],
                inputSchema: $t['inputSchema'] ?? [],
            ))
            ->all();
    }

    public function toPayload(): AiPayload
    {
        $me = $this->client()->readResource('crm://me');
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage($this->question)],
            systemPrompt: "Поточний користувач: {$me}\nВикористовуй надані інструменти для відповіді.",
        );
    }

    private function client(): HttpMcpClient
    {
        return $this->mcpClient ??= new HttpMcpClient(
            url: config('services.crm_mcp.url'),
            token: config('services.crm_mcp.token'),
        );
    }

    public function modality(): string        { return 'text'; }
    public function serializeForQueue(): array { return [$this->question]; }
}
```

```php
AI::send(new CrmTask('Показати завантаженість всіх користувачів'));
AI::queue(new CrmTask('Створи задачу "Виправити баг входу" в проекті CRM, пріоритет 3'));
```

---

## stdio MCP через HTTP-проксі (npx-пакети)

Більшість MCP-серверів спільноти (наприклад `@upstash/context7-mcp`, `@modelcontextprotocol/server-filesystem`) використовують **stdio transport** — комунікація через stdin/stdout, а не HTTP.

Використовуй [supergateway](https://github.com/supermaven-inc/supergateway) щоб обгорнути будь-який stdio-сервер у Streamable HTTP ендпоінт і підключити через `HttpMcpClient`.

### Запуск

```bash
# Запустити проксі (один раз; потрібен перезапуск після ребуту)
npx -y supergateway \
  --stdio "npx -y @upstash/context7-mcp" \
  --port 8808 \
  --outputTransport streamableHttp \
  > /tmp/context7-mcp.log 2>&1 &

# Перевірити (~8 с на старт)
curl -s http://localhost:8808/mcp \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' \
  | grep -o '"name":"[^"]*"'
# → "name":"resolve-library-id"  "name":"get-library-docs"
```

Додай в `config/services.php`:

```php
'context7_mcp' => [
    'url' => env('CONTEXT7_MCP_URL', 'http://localhost:8808/mcp'),
],
```

### Таск

```php
class McpContext7Task extends AiTask
{
    private ?HttpMcpClient $mcpClient = null;

    public function __construct(private readonly string $question) {}

    public function modality(): string { return 'text'; }

    public function tools(): array
    {
        $client = $this->client();
        return collect($client->listTools())
            ->map(fn(array $t) => new HttpMcpTool(
                client: $client,
                name: $t['name'],
                toolDescription: $t['description'] ?? $t['title'] ?? $t['name'],
                inputSchema: $t['inputSchema'] ?? [],
            ))
            ->all();
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'text',
            messages: [new UserMessage($this->question)],
            systemPrompt: 'You are a helpful developer assistant with access to up-to-date library documentation via Context7. Always use the provided tools to fetch relevant docs before answering.',
        );
    }

    public function serializeForQueue(): array { return [$this->question]; }

    private function client(): HttpMcpClient
    {
        return $this->mcpClient ??= new HttpMcpClient(
            url: config('services.context7_mcp.url'),
        );
    }
}
```

```php
AI::send(new McpContext7Task('Як використовувати simplePaginate в Laravel?'));
AI::send(new McpContext7Task('Приклади Redis-черг в Laravel'));
```

### Запуск у продакшені

**Supervisor (`/etc/supervisor/conf.d/context7-mcp.conf`):**
```ini
[program:context7-mcp]
command=npx -y supergateway --stdio "npx -y @upstash/context7-mcp" --port 8808 --outputTransport streamableHttp
autostart=true
autorestart=true
stderr_logfile=/var/log/context7-mcp.err.log
stdout_logfile=/var/log/context7-mcp.out.log
```

**Docker Compose (окремий сервіс):**
```yaml
context7-mcp:
  image: node:20-alpine
  command: npx -y supergateway --stdio "npx -y @upstash/context7-mcp" --port 8808 --outputTransport streamableHttp
  ports:
    - "8808:8808"
  restart: unless-stopped
```

Потім в `.env`: `CONTEXT7_MCP_URL=http://context7-mcp:8808/mcp`
