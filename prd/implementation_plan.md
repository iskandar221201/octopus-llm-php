# octopus-llm-php — Implementation Plan

Phased implementation plan untuk membangun `octopus-llm-php` Composer package berdasarkan [PRD](file:///c:/laragon/www/octopus-llm-php/PRD.md).

---

## Phase 1: Foundation

Scaffold project dan buat semua base types yang menjadi building blocks.

### 1.1 Project Scaffold

#### [NEW] [composer.json](file:///c:/laragon/www/octopus-llm-php/composer.json)
- PSR-4 autoload: `OctopusLLM\Gateway\` → `src/`
- Require: `php >=8.1`, `openai-php/client ^0.10`
- Require-dev: `phpunit/phpunit ^10.0`
- Suggest: `codeigniter4/framework`

#### [NEW] [.gitignore](file:///c:/laragon/www/octopus-llm-php/.gitignore)
- `vendor/`, `composer.lock`, `.phpunit.result.cache`

---

### 1.2 DTOs (Data Transfer Objects)

Semua DTOs readonly, immutable, dibuat via constructor.

#### [NEW] [ChatResponse.php](file:///c:/laragon/www/octopus-llm-php/src/ChatResponse.php)
```php
readonly class ChatResponse {
    public function __construct(
        public string $content,
        public string $provider,
        public int    $keyIndex,
        public string $model,
        public float  $latencyMs,
        public int    $attempts,
        public bool   $fallbackUsed,
    ) {}
}
```

#### [NEW] [StatusResponse.php](file:///c:/laragon/www/octopus-llm-php/src/StatusResponse.php)
```php
readonly class StatusResponse {
    /** @param ProviderStatus[] $providers */
    public function __construct(
        public array $providers,
        public int   $totalActive,
        public int   $totalInactive,
    ) {}
}
```

#### [NEW] [ProviderStatus.php](file:///c:/laragon/www/octopus-llm-php/src/ProviderStatus.php)
```php
readonly class ProviderStatus {
    /** @param KeyStatus[] $keys */
    public function __construct(
        public string $id,
        public int    $priority,
        public array  $keys,
    ) {}
}
```

#### [NEW] [KeyStatus.php](file:///c:/laragon/www/octopus-llm-php/src/KeyStatus.php)
```php
readonly class KeyStatus {
    public function __construct(
        public int     $index,
        public string  $status,      // 'active' | 'inactive'
        public int     $failureCount,
        public ?string $lastUsed,    // ISO timestamp
        public ?string $markedAt,    // ISO timestamp (jika inactive)
    ) {}
}
```

#### [NEW] [RecoveryReport.php](file:///c:/laragon/www/octopus-llm-php/src/RecoveryReport.php)
```php
readonly class RecoveryReport {
    public function __construct(
        public array $recovered, // [['provider' => string, 'keyIndex' => int]]
        public array $failed,
        public int   $total,
    ) {}
}
```

---

### 1.3 Contracts

#### [NEW] [StorageInterface.php](file:///c:/laragon/www/octopus-llm-php/src/Contracts/StorageInterface.php)
```php
interface StorageInterface {
    public function load(): array;
    public function save(array $state): void;
}
```

---

### 1.4 Storage Implementations

#### [NEW] [JsonFileStorage.php](file:///c:/laragon/www/octopus-llm-php/src/Storage/JsonFileStorage.php)
- Constructor: `__construct(string $path = 'storage/octopus-llm/state.json')`
- Auto-create directory jika belum ada
- `load()`: baca file dengan `flock(LOCK_SH)`, return `[]` jika file belum ada
- `save()`: tulis file dengan `flock(LOCK_EX)`, atomic write (write to temp, rename)
- JSON encode dengan `JSON_PRETTY_PRINT`

#### [NEW] [NullStorage.php](file:///c:/laragon/www/octopus-llm-php/src/Storage/NullStorage.php)
- `load()`: return `[]`
- `save()`: no-op
- Untuk testing — stateless, tidak persist apa-apa

---

### 1.5 Exception Classes

Semua exception di namespace `OctopusLLM\Gateway\Exceptions`.

#### [NEW] [ProviderException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/ProviderException.php)
- Base exception untuk semua provider errors
- Properties: `public readonly string $provider`, `public readonly string $originalMessage`

#### [NEW] [GatewayExhaustedException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/GatewayExhaustedException.php)
- Extends `\RuntimeException`
- Thrown ketika semua providers dan keys habis

#### [NEW] [RateLimitException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/RateLimitException.php)
- Extends `ProviderException`
- Properties: `public readonly ?int $retryAfter`

#### [NEW] [TimeoutException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/TimeoutException.php)
- Extends `ProviderException`
- Properties: `public readonly int $timeoutSeconds`

#### [NEW] [AuthenticationException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/AuthenticationException.php)
- Extends `ProviderException`
- Properties: `public readonly int $keyIndex`

#### [NEW] [CircuitOpenException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/CircuitOpenException.php)
- Extends `ProviderException`
- Properties: `public readonly int $keyIndex`, `public readonly string $retryAt`

#### [NEW] [InputTooLongException.php](file:///c:/laragon/www/octopus-llm-php/src/Exceptions/InputTooLongException.php)
- Extends `\InvalidArgumentException`
- Properties: `public readonly int $estimated`, `public readonly int $limit`

---

## Phase 2: Core Engine

Implementasi main class `OctopusLLM` dan seluruh business logic.

### 2.1 Main Class

#### [NEW] [OctopusLLM.php](file:///c:/laragon/www/octopus-llm-php/src/OctopusLLM.php)

**Constructor:**
- Parse config array, validate required fields
- Instantiate `StorageInterface` (default `JsonFileStorage`)
- Sort providers by priority
- Initialize event listeners array

**`chat(array $messages, array $options = []): ChatResponse`**
- Load state from storage
- Pre-flight check: `maxInputTokens` guard (1 token ≈ 4 chars)
- Iterate providers by priority:
  - Iterate keys by least-recently-used
  - Skip inactive keys
  - Create `openai-php/client` instance per key: `OpenAI::factory()->withApiKey($key)->withBaseUri($baseURL)->make()`
  - Try request with retry logic (up to `maxRetries`)
  - On success: update `lastUsed` timestamp, save state, return `ChatResponse`
  - On retryable failure: retry (no failure count increment)
  - On final failure (retries exhausted): increment failure count
  - On rate limit (429) / auth error (401/403): immediately mark inactive, emit event
  - If failure count >= threshold: mark key inactive, emit `key.deactivated`
  - If all keys in provider inactive: emit `provider.exhausted`, continue to next provider, emit `fallback`
- If all providers exhausted: throw `GatewayExhaustedException`
- Save state to storage before returning

**Streaming support:**
- When `$options['stream'] === true`:
  - Use `$client->chat()->createStreamed()` instead of `create()`
  - Call `$options['onChunk']($delta)` for each chunk
  - Collect full content for `ChatResponse`
  - Same rotation/fallback logic applies

**`getStatus(): StatusResponse`**
- Load state, map to DTO hierarchy

**`ping(string $providerId, int $keyIndex): bool`**
- Load state, find key
- Check cooldown elapsed
- Send `GET /models` request via openai-php client
- On 200: mark key active, reset failure count, emit `key.recovered`, save state
- Else: keep inactive, save state

**`runRecovery(): RecoveryReport`**
- Get all inactive keys with elapsed cooldown
- Call `ping()` for each
- Return `RecoveryReport`

**`on(string $event, callable $callback): self`**
- Register callback for event type
- Supported: `key.deactivated`, `key.recovered`, `provider.exhausted`, `fallback`

---

## Phase 3: CI4 Integration

Spark commands untuk CLI management. Opsional — hanya loaded jika CI4 tersedia.

### 3.1 Commands

#### [NEW] [OctopusValidate.php](file:///c:/laragon/www/octopus-llm-php/src/Commands/OctopusValidate.php)
- `php spark octopus:validate`
- Parse provider config, check keys not empty

#### [NEW] [OctopusStatus.php](file:///c:/laragon/www/octopus-llm-php/src/Commands/OctopusStatus.php)
- `php spark octopus:status [--json]`
- Call `getStatus()`, format output

#### [NEW] [OctopusBenchmark.php](file:///c:/laragon/www/octopus-llm-php/src/Commands/OctopusBenchmark.php)
- `php spark octopus:benchmark [--samples N]`
- Send test requests, measure latency, rank providers

#### [NEW] [OctopusTest.php](file:///c:/laragon/www/octopus-llm-php/src/Commands/OctopusTest.php)
- `php spark octopus:test [--prompt "..."]`
- Send single test prompt, display response + metadata

#### [NEW] [OctopusRecover.php](file:///c:/laragon/www/octopus-llm-php/src/Commands/OctopusRecover.php)
- `php spark octopus:recover`
- Call `runRecovery()`, display report

---

## Phase 4: Testing & README

### 4.1 Unit Tests

#### [NEW] tests/Unit/

- **StorageTest.php** — test `JsonFileStorage` (read/write/locking/auto-create dir) dan `NullStorage`
- **RotationTest.php** — test LRU key selection, provider priority fallback, skip inactive keys
- **CircuitBreakerTest.php** — test failure threshold, mark inactive, cooldown respect
- **GuardTest.php** — test `maxInputTokens` estimation, `InputTooLongException`
- **ChatTest.php** — test full chat flow with mocked OpenAI client, retry logic, streaming
- **EventTest.php** — test callback registration dan emission

All tests use `NullStorage` dan mocked `openai-php/client` — **no real API calls**.

### 4.2 Documentation

#### [NEW] [README.md](file:///c:/laragon/www/octopus-llm-php/README.md)
- Overview, installation, quick start, full API reference, CI4 integration guide

---

## Verification Plan

### Automated Tests
```bash
cd c:\laragon\www\octopus-llm-php
composer install
vendor/bin/phpunit
```

All tests harus pass tanpa API keys — fully mocked.

### Manual Integration Test
1. Di `verra-app`, add local repository ke `composer.json`:
   ```json
   "repositories": [
       {"type": "path", "url": "../octopus-llm-php"}
   ]
   ```
2. `composer require octopus-llm/php:@dev`
3. Replace `AiService` usage dengan `OctopusLLM`
4. Test chat response — verify rotation dan fallback bekerja

---

## Execution Order

```
Phase 1 → Phase 2 → Phase 3 → Phase 4
```

Setiap phase bisa di-commit dan di-test secara independen. Phase 1 bisa `composer install` dan autoload tanpa error. Phase 2 bisa dipakai tanpa Phase 3. Phase 3 opsional.
