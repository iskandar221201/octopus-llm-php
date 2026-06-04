# octopus-llm-php — Product Requirements Document

> OpenAI-compatible AI gateway dengan multi-key rotation, circuit breaker, dan zero-cost free tier management. PHP port dari octopus-llm (npm).

---

## Overview

`octopus-llm-php` adalah Composer package yang mengabstraksi kompleksitas manajemen multiple AI API keys menjadi satu unified `chat()` call. Provider-agnostic selama endpoint OpenAI-compatible. Menggunakan `openai-php/client` sebagai transport layer. Didesain untuk CI4, tapi bisa dipakai di framework PHP manapun.

---

## Installation

```bash
composer require octopus-llm/php
```

**Requirements:**
- PHP >= 8.1
- `openai-php/client` ^0.10

---

## Core Concepts

### Provider
Sebuah "provider" bukan berarti Groq/OpenRouter/Cerebras — tapi sebuah **endpoint + key pool**. User bisa punya multiple provider yang mengarah ke provider yang sama tapi dengan key berbeda.

> **Note:** Hanya provider dengan endpoint OpenAI-compatible yang didukung. Gemini native API tidak didukung — gunakan Gemini via OpenRouter sebagai gantinya.

### Key Pool
Tiap provider punya array of keys. Gateway akan rotate antar keys secara otomatis.

### Priority
Provider diurutkan berdasarkan priority. Fallback terjadi ketika semua keys di satu provider inactive/error. **Strategy: strict priority** — habisin semua key di provider tertinggi dulu sebelum fallback ke berikutnya.

---

## Environment Variables

Keys wajib disimpan di `.env`, bukan hardcode di config.

```env
# Format: comma-separated
GROQ_KEYS=key1,key2,key3,key4,key5
OPENROUTER_KEYS=key6,key7,key8
CEREBRAS_KEYS=key9,key10,key11,key12
```

> Naming convention env variable bebas — user yang tentukan, package tidak hardcode.

---

## PHP Notes vs Node Version

| Concern | Node | PHP |
|---|---|---|
| HTTP Client | Native fetch | `openai-php/client` (Guzzle) |
| Async HTTP | Native async/await | Sync default |
| Streaming | Native ReadableStream | `openai-php/client` `createStreamed()` |
| Background interval | `setInterval` | Manual via CI4 scheduler / spark command |
| Events emitter | EventEmitter | Callback registration via `on()` |
| State persistence | In-memory (process hidup) | Persistent via file/DB/cache |

### State Management di PHP
Perbedaan paling krusial. Di Node, gateway instance hidup selama process — state circuit breaker tersimpan in-memory. Di PHP, setiap request adalah proses baru.

**Solusi:** State (key status, failure count, cooldown) disimpan ke **persistent storage**:

```
Default: JSON file di storage/octopus-llm/state.json
Opsional: Custom StorageInterface (untuk Redis, DB, dll)
```

State di-load saat `chat()` dipanggil, di-save setelah selesai.

### Concurrency & File Locking
Karena multiple PHP-FPM workers bisa akses state file bersamaan, `JsonFileStorage` wajib pakai `flock()` untuk exclusive locking saat read/write.

### Round-robin Cursor
Untuk menghindari semua concurrent requests memilih key yang sama, round-robin menggunakan `lastUsed` timestamp — pilih key dengan `lastUsed` paling lama, bukan sequential index.

---

## API

### Initialization

```php
use OctopusLLM\Gateway\OctopusLLM;

$gateway = new OctopusLLM([
    'providers' => [
        [
            'id'       => 'groq',
            'baseURL'  => 'https://api.groq.com/openai/v1',
            'model'    => 'llama-3.1-8b-instant',
            'keys'     => explode(',', $_ENV['GROQ_KEYS'] ?? ''),
            'priority' => 1,    // lower = higher priority
            'cooldown' => 60,   // seconds — rate limit reset
        ],
        [
            'id'       => 'openrouter',
            'baseURL'  => 'https://openrouter.ai/api/v1',
            'model'    => 'mistralai/mistral-7b-instruct:free',
            'keys'     => explode(',', $_ENV['OPENROUTER_KEYS'] ?? ''),
            'priority' => 2,
            'cooldown' => 120,
            'extraHeaders' => [  // opsional, header tambahan per-provider
                'HTTP-Referer' => 'https://myapp.com',
                'X-Title'      => 'MyApp',
            ],
        ],
        [
            'id'       => 'cerebras',
            'baseURL'  => 'https://api.cerebras.ai/v1',
            'model'    => 'llama-3.1-8b',
            'keys'     => explode(',', $_ENV['CEREBRAS_KEYS'] ?? ''),
            'priority' => 3,
            'cooldown' => 60,
        ],
    ],

    'guard' => [
        'timeoutSeconds' => 10,
        'maxInputTokens' => 4000,
        'maxRetries'     => 2,
        'maxOutputTokens'=> 1000,
    ],

    'recovery' => [
        'pingTimeout' => 5, // seconds
    ],

    'circuitBreaker' => [
        'failureThreshold' => 3,
    ],

    // State storage — default JSON file
    'storage' => null, // null = JsonFileStorage default
]);
```

---

### `$gateway->chat(array $messages, array $options = []): ChatResponse`

Unified chat method. Internally handles rotation, fallback, dan recovery.

**Non-streaming (default):**
```php
$response = $gateway->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user',   'content' => 'Halo!'],
]);

echo $response->content;        // string
echo $response->provider;       // 'groq'
echo $response->keyIndex;       // index key yang digunakan
echo $response->latencyMs;      // 1210
echo $response->model;          // 'llama-3.1-8b-instant'
echo $response->attempts;       // jumlah percobaan sebelum berhasil
var_dump($response->fallbackUsed); // bool
```

**Streaming via callback:**
```php
$response = $gateway->chat(
    [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user',   'content' => 'Halo!'],
    ],
    [
        'stream'    => true,
        'onChunk'   => function(string $delta) {
            echo $delta;
            ob_flush();
            flush();
        },
    ]
);

// $response tetap ChatResponse dengan metadata lengkap
echo $response->provider;
echo $response->latencyMs;
```

**Options:**
```php
[
    'stream'        => false,       // default false
    'maxTokens'     => 1000,        // default dari guard config
    'temperature'   => 0.7,         // default 0.7
    'forceProvider' => 'groq',      // bypass rotation
    'onChunk'       => callable,    // wajib jika stream: true
]
```

> **⚠ Streaming Warning:** Streaming membuka long-running HTTP connection. Pada server low-spec, concurrent streaming dapat mengexhaust PHP-FPM workers. Gunakan `stream: false` (default) untuk production traffic tinggi.

---

### `$gateway->getStatus(): StatusResponse`

Returns current state semua provider dan keys.

```php
$status = $gateway->getStatus();

foreach ($status->providers as $provider) {
    echo $provider->id;       // 'groq'
    echo $provider->priority; // 1
    foreach ($provider->keys as $key) {
        echo $key->index;        // 0
        echo $key->status;       // 'active' | 'inactive'
        echo $key->failureCount; // 0
        echo $key->lastUsed;     // ISO timestamp | null
        echo $key->markedAt;     // ISO timestamp | null
    }
}

echo $status->totalActive;   // 38
echo $status->totalInactive; // 2
```

---

### `$gateway->ping(string $providerId, int $keyIndex): bool`

Ping satu key spesifik — untuk manual recovery.

```php
$recovered = $gateway->ping('groq', 1);
// true = key recovered, false = still inactive
```

---

### `$gateway->runRecovery(): RecoveryReport`

Ping semua inactive keys yang sudah melewati cooldown.

```php
$report = $gateway->runRecovery();

$report->recovered; // array of ['provider' => 'groq', 'keyIndex' => 1]
$report->failed;    // array of keys yang masih inactive
$report->total;     // jumlah keys yang di-ping
```

---

## Internal Behavior

### Rotation Strategy
```
Request masuk
  → Load state dari storage
  → Ambil provider dengan priority tertinggi (priority: 1)
  → Dari provider itu, pilih key dengan lastUsed paling lama (least-recently-used)
  → Jika key inactive → skip ke key berikutnya
  → Jika semua keys inactive → fallback ke provider priority berikutnya
  → Jika semua provider inactive → throw GatewayExhaustedException
  → Setelah request → save state ke storage
```

### Retry & Failure Counting
```
Request → attempt 1 gagal → retry (failure count TIDAK naik)
        → attempt 2 gagal → retry (failure count TIDAK naik)
        → attempt 3 gagal → maxRetries habis → failure count NAIK
        → failure count >= failureThreshold → key di-mark inactive
```

- Retry attempt yang gagal **tidak** increment failure count
- Hanya final failure setelah semua retries habis yang increment
- Rate limit (429) dan auth error (401/403) langsung mark inactive tanpa retry

### Circuit Breaker
- Per-key, bukan per-provider
- States: `CLOSED` (normal) → `OPEN` (inactive) → `HALF-OPEN` (ping attempt) → `CLOSED` / `OPEN`
- Key tidak di-ping ulang sebelum `cooldown` seconds sejak `markedAt`

### State Persistence
```
chat() dipanggil
  → loadState() dari storage (with shared lock)
  → mutate state (failure count, lastUsed, key status)
  → saveState() ke storage (with exclusive lock)
  → return response
```

Default storage: `JsonFileStorage` → `storage/octopus-llm/state.json`

Custom storage implement `StorageInterface`:
```php
interface StorageInterface {
    public function load(): array;
    public function save(array $state): void;
}
```

### Guard
- `timeoutSeconds` — request timeout, dihitung sebagai failure setelah maxRetries habis
- `maxInputTokens` — estimasi token sebelum request (1 token ≈ 4 chars). Melebihi → throw `InputTooLongException`
- `maxRetries` — retry per-request sebelum final failure
- `maxOutputTokens` — di-pass sebagai `max_tokens` ke provider

---

## Error Handling

```php
use OctopusLLM\Gateway\Exceptions\GatewayExhaustedException;
use OctopusLLM\Gateway\Exceptions\ProviderException;
use OctopusLLM\Gateway\Exceptions\InputTooLongException;
use OctopusLLM\Gateway\Exceptions\RateLimitException;
use OctopusLLM\Gateway\Exceptions\TimeoutException;
use OctopusLLM\Gateway\Exceptions\AuthenticationException;
use OctopusLLM\Gateway\Exceptions\CircuitOpenException;

try {
    $response = $gateway->chat($messages);
} catch (GatewayExhaustedException $e) {
    // Semua provider dan keys inactive
} catch (RateLimitException $e) {
    // $e->provider, $e->retryAfter
} catch (TimeoutException $e) {
    // $e->provider, $e->timeoutSeconds
} catch (AuthenticationException $e) {
    // $e->provider, $e->keyIndex
} catch (CircuitOpenException $e) {
    // $e->provider, $e->keyIndex, $e->retryAt
} catch (InputTooLongException $e) {
    // $e->estimated, $e->limit — request tidak dikirim
} catch (ProviderException $e) {
    // $e->provider, $e->originalMessage
}
```

---

## Events / Callbacks

```php
$gateway->on('key.deactivated', function(string $provider, int $keyIndex) {
    log_message('warning', "Key #{$keyIndex} di {$provider} inactive");
});

$gateway->on('key.recovered', function(string $provider, int $keyIndex) {
    log_message('info', "Key #{$keyIndex} di {$provider} recovered");
});

$gateway->on('provider.exhausted', function(string $provider) {
    log_message('error', "Provider {$provider} exhausted");
});

$gateway->on('fallback', function(string $from, string $to) {
    log_message('notice', "Fallback dari {$from} ke {$to}");
});
```

---

## CI4 Integration

### Service Registration (`app/Config/Services.php`)

```php
use OctopusLLM\Gateway\OctopusLLM;

public static function octopus(bool $getShared = true): OctopusLLM
{
    if ($getShared) return static::getSharedInstance('octopus');

    return new OctopusLLM([
        'providers' => [
            [
                'id'       => 'groq',
                'baseURL'  => 'https://api.groq.com/openai/v1',
                'model'    => 'llama-3.1-8b-instant',
                'keys'     => explode(',', env('GROQ_KEYS', '')),
                'priority' => 1,
                'cooldown' => 60,
            ],
            // ...
        ],
    ]);
}
```

### Usage

```php
$octopus  = \Config\Services::octopus();
$response = $octopus->chat([
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user',   'content' => $userMessage],
]);
```

### Scheduled Recovery (`app/Config/Tasks.php`)

```php
$schedule->call(function () {
    \Config\Services::octopus()->runRecovery();
})->everyMinute()->named('octopus-recovery');
```

---

## CLI Commands (CI4 Spark)

| Command | Description |
|---|---|
| `php spark octopus:validate` | Cek `.env` — keys tidak kosong, format valid |
| `php spark octopus:status [--json]` | Status aktif/inactive per provider |
| `php spark octopus:benchmark [--samples N]` | Benchmark latency semua provider |
| `php spark octopus:test [--prompt "..."]` | Kirim test prompt ke provider aktif |
| `php spark octopus:recover` | Trigger manual `runRecovery()` |

---

## File Structure

```
octopus-llm-php/
├── composer.json
├── README.md
├── PRD.md
├── implementation_plan.md
├── src/
│   ├── OctopusLLM.php              # Main gateway class
│   ├── ChatResponse.php            # Response DTO
│   ├── StatusResponse.php          # Status DTO
│   ├── ProviderStatus.php          # Provider status DTO
│   ├── KeyStatus.php               # Key status DTO
│   ├── RecoveryReport.php          # Recovery DTO
│   ├── Contracts/
│   │   └── StorageInterface.php
│   ├── Storage/
│   │   ├── JsonFileStorage.php     # Default (JSON file with flock)
│   │   └── NullStorage.php         # For testing
│   ├── Exceptions/
│   │   ├── GatewayExhaustedException.php
│   │   ├── ProviderException.php
│   │   ├── InputTooLongException.php
│   │   ├── RateLimitException.php
│   │   ├── TimeoutException.php
│   │   ├── AuthenticationException.php
│   │   └── CircuitOpenException.php
│   └── Commands/                   # CI4 spark commands
│       ├── OctopusValidate.php
│       ├── OctopusStatus.php
│       ├── OctopusBenchmark.php
│       ├── OctopusTest.php
│       └── OctopusRecover.php
└── tests/
```

---

## composer.json

```json
{
    "name": "octopus-llm/php",
    "description": "OpenAI-compatible AI gateway with multi-key rotation, circuit breaker, and zero-cost free tier management.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.1",
        "openai-php/client": "^0.10"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "OctopusLLM\\Gateway\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "OctopusLLM\\Gateway\\Tests\\": "tests/"
        }
    },
    "suggest": {
        "codeigniter4/framework": "Required for spark CLI commands"
    }
}
```

---

## Non-Goals (v1)

- Direct Gemini native API support (gunakan via OpenRouter)
- Async HTTP
- Redis/DB storage built-in (via StorageInterface — user implement sendiri)
- Per-request model override (v2)
- Weighted priority strategy (v2)
- Dashboard UI
- Prompt templating

---

## Key Differences dari Node Version

| Feature | Node (npm) | PHP (Composer) |
|---|---|---|
| Transport | Native fetch | `openai-php/client` |
| State | In-memory | JSON file / StorageInterface |
| Background recovery | `setInterval` | Manual via spark / scheduler |
| Streaming | Async generator | `createStreamed()` + `onChunk` callback |
| Events | EventEmitter | Callback `on()` |
| Concurrency safety | Single-thread | File locking (`flock`) |
| CLI | `npx octopus-llm` | `php spark octopus:*` |
