## Feature: Core Engine — OctopusLLM Main Class

### Context
Class utama yang mengorkestra semua business logic: key rotation (LRU), provider priority fallback, circuit breaker, retry logic, streaming, event callbacks, dan state persistence. Ini adalah class terbesar dan paling kompleks di package ini.

**Dependency:** Task 01 (scaffold), 02 (DTOs), 03 (storage), 04 (exceptions) harus selesai duluan.

### Scope
**IN scope:**
- `OctopusLLM` class dengan methods: `chat()`, `getStatus()`, `ping()`, `runRecovery()`, `on()`
- Config parsing dan validation
- LRU key rotation logic
- Provider priority-based fallback
- Circuit breaker pattern (per-key)
- Retry logic (per-request)
- Streaming support via `createStreamed()`
- Event callback system
- Integration dengan `StorageInterface`

**OUT of scope:**
- CLI commands (task 06)
- CI4-specific code (task 06)
- Unit tests (task 07)
- Storage implementation details (task 03)

### Files to Create

- `src/OctopusLLM.php` → Main gateway class

### Implementation Steps

1. Buat `src/OctopusLLM.php`:
   - Namespace: `OctopusLLM\Gateway`
   - Use statements:
     ```php
     use OctopusLLM\Gateway\Contracts\StorageInterface;
     use OctopusLLM\Gateway\Storage\JsonFileStorage;
     use OctopusLLM\Gateway\Exceptions\*; // semua exception
     use OpenAI; // dari openai-php/client
     ```

2. Definisikan properties:
   - `private array $providers` — sorted by priority
   - `private array $guard` — guard config (timeoutSeconds, maxInputTokens, maxRetries, maxOutputTokens)
   - `private array $recovery` — recovery config (pingTimeout)
   - `private array $circuitBreaker` — circuit breaker config (failureThreshold)
   - `private StorageInterface $storage`
   - `private array $listeners = []` — event listeners, keyed by event name

3. Implementasi `__construct(array $config)`:
   - Validate `$config['providers']` ada dan not empty → throw `\InvalidArgumentException` jika kosong
   - Validate setiap provider punya field wajib: `id`, `baseURL`, `model`, `keys`, `priority`
   - Validate setiap provider `keys` not empty → throw `\InvalidArgumentException` jika kosong
   - Set defaults untuk guard: `timeoutSeconds => 10`, `maxInputTokens => 4000`, `maxRetries => 2`, `maxOutputTokens => 1000`
   - Set defaults untuk recovery: `pingTimeout => 5`
   - Set defaults untuk circuitBreaker: `failureThreshold => 3`
   - Merge user config dengan defaults
   - Sort providers by `priority` ascending (lower number = higher priority)
   - Instantiate storage: jika `$config['storage']` adalah `StorageInterface` → gunakan itu, else → `new JsonFileStorage()`

4. Implementasi `chat(array $messages, array $options = []): ChatResponse`:
   - Merge options defaults: `stream => false`, `maxTokens => $this->guard['maxOutputTokens']`, `temperature => 0.7`, `forceProvider => null`, `onChunk => null`
   - Pre-flight guard — `maxInputTokens`:
     - Hitung total chars dari semua `$messages[*]['content']`
     - Estimasi token: `ceil(totalChars / 4)`
     - Jika estimated > `$this->guard['maxInputTokens']` → throw `InputTooLongException($estimated, $limit)`
   - Load state dari storage: `$state = $this->storage->load()`
   - Initialize state per-provider/key jika belum ada
   - Track: `$attempts = 0`, `$fallbackUsed = false`, `$startTime = microtime(true)`
   - Jika `forceProvider` set → filter hanya provider dengan `id` matching
   - Iterate providers by priority:
     - Dapatkan keys untuk provider ini, sort by `lastUsed` ascending (LRU — key dengan `lastUsed` paling lama dipilih duluan, `null` lastUsed = highest priority)
     - Iterate keys:
       - Skip jika key inactive (status `'inactive'`) dan cooldown belum elapsed
       - Jika key inactive tapi cooldown elapsed → treat sebagai half-open (bisa dicoba)
       - Create `openai-php/client` instance:
         ```php
         $factory = OpenAI::factory()
             ->withApiKey($key)
             ->withBaseUri($provider['baseURL'])
             ->withHttpHeader('Content-Type', 'application/json');
         
         // tambahkan extraHeaders jika ada
         if (isset($provider['extraHeaders'])) {
             foreach ($provider['extraHeaders'] as $name => $value) {
                 $factory = $factory->withHttpHeader($name, $value);
             }
         }
         
         // set timeout
         $factory = $factory->withHttpClient(
             new \GuzzleHttp\Client(['timeout' => $this->guard['timeoutSeconds']])
         );
         
         $client = $factory->make();
         ```
       - Retry loop (up to `maxRetries` + 1 attempts):
         - `$attempts++`
         - Try:
           - Jika `stream === false`:
             ```php
             $result = $client->chat()->create([
                 'model' => $provider['model'],
                 'messages' => $messages,
                 'max_tokens' => $options['maxTokens'],
                 'temperature' => $options['temperature'],
             ]);
             $content = $result->choices[0]->message->content;
             ```
           - Jika `stream === true`:
             ```php
             $stream = $client->chat()->createStreamed([...]);
             $content = '';
             foreach ($stream as $response) {
                 $delta = $response->choices[0]->delta->content ?? '';
                 if ($delta !== '' && $options['onChunk']) {
                     ($options['onChunk'])($delta);
                 }
                 $content .= $delta;
             }
             ```
           - On success:
             - Update state: `lastUsed = date('c')`, reset failure count jika was half-open
             - Save state: `$this->storage->save($state)`
             - Calculate latency: `(microtime(true) - $startTime) * 1000`
             - Return `new ChatResponse($content, $provider['id'], $keyIndex, $provider['model'], $latencyMs, $attempts, $fallbackUsed)`
         - Catch `\OpenAI\Exceptions\ErrorException $e`:
           - Jika HTTP 429 (rate limit):
             - Immediately mark key inactive: set status `'inactive'`, `markedAt = date('c')`
             - Parse `retryAfter` dari response jika ada
             - Emit event `key.deactivated`
             - Break inner retry loop (jangan retry, langsung next key)
           - Jika HTTP 401 atau 403 (auth error):
             - Immediately mark key inactive
             - Emit event `key.deactivated`
             - Break inner retry loop
           - Jika error lain (5xx, network error):
             - Jika masih ada retry → continue retry loop
             - Jika retries habis → increment failure count
             - Jika failure count >= `failureThreshold` → mark key inactive, emit `key.deactivated`
         - Catch `\GuzzleHttp\Exception\ConnectException`:
           - Treat sebagai timeout
           - Same logic sebagai error lain
       - Setelah semua keys di provider habis (semua inactive/failed):
         - Emit event `provider.exhausted`
         - Set `$fallbackUsed = true` (untuk provider berikutnya)
         - Jika ada next provider: emit `fallback` event
   - Jika semua providers exhausted:
     - Save state: `$this->storage->save($state)`
     - Throw `new GatewayExhaustedException()`

5. Implementasi `getStatus(): StatusResponse`:
   - Load state: `$state = $this->storage->load()`
   - Initialize state jika empty
   - Map ke DTO hierarchy:
     - Untuk setiap provider → `new ProviderStatus($id, $priority, $keys)`
     - Untuk setiap key → `new KeyStatus($index, $status, $failureCount, $lastUsed, $markedAt)`
   - Count `$totalActive` dan `$totalInactive`
   - Return `new StatusResponse($providers, $totalActive, $totalInactive)`

6. Implementasi `ping(string $providerId, int $keyIndex): bool`:
   - Load state
   - Find provider by `$providerId` → throw `\InvalidArgumentException` jika tidak ditemukan
   - Find key by `$keyIndex` → throw `\InvalidArgumentException` jika tidak ditemukan
   - Check cooldown: jika `markedAt + cooldown > now` → return `false` (too early to ping)
   - Send GET /models request:
     ```php
     $client = OpenAI::factory()
         ->withApiKey($provider['keys'][$keyIndex])
         ->withBaseUri($provider['baseURL'])
         ->withHttpClient(new \GuzzleHttp\Client(['timeout' => $this->recovery['pingTimeout']]))
         ->make();
     
     $client->models()->list();
     ```
   - On success (no exception):
     - Mark key active: status `'active'`, reset failure count to 0, clear `markedAt`
     - Emit event `key.recovered`
     - Save state
     - Return `true`
   - On exception:
     - Keep inactive, save state
     - Return `false`

7. Implementasi `runRecovery(): RecoveryReport`:
   - Load state
   - Filter semua inactive keys dimana cooldown sudah elapsed: `markedAt + cooldown <= now`
   - Untuk setiap eligible key: call `$this->ping($providerId, $keyIndex)`
   - Collect results ke `$recovered` dan `$failed` arrays
   - Return `new RecoveryReport($recovered, $failed, count($recovered) + count($failed))`

8. Implementasi `on(string $event, callable $callback): self`:
   - Validate event name: harus salah satu dari `['key.deactivated', 'key.recovered', 'provider.exhausted', 'fallback']`
   - Jika event tidak dikenali → throw `\InvalidArgumentException`
   - Push callback ke `$this->listeners[$event][]`
   - Return `$this` (fluent interface)

9. Implementasi private helper `emit(string $event, mixed ...$args): void`:
   - Jika tidak ada listeners untuk event ini → return
   - Foreach listener → call `$listener(...$args)`
   - Wrap dalam try-catch: listener error TIDAK boleh interrupt main flow

10. Implementasi private helper `initializeState(array &$state): void`:
    - Untuk setiap provider yang belum ada di state → init default:
      ```php
      $state[$provider['id']] = [
          'keys' => array_map(fn($i) => [
              'index' => $i,
              'status' => 'active',
              'failureCount' => 0,
              'lastUsed' => null,
              'markedAt' => null,
          ], array_keys($provider['keys']))
      ];
      ```

### Expected Behavior
- `chat()` dengan semua keys aktif → gunakan key dengan `lastUsed` paling lama dari provider priority tertinggi
- Setelah key digunakan → `lastUsed` di-update, key berikutnya dipilih saat request selanjutnya
- Key dengan 3x failure (default threshold) → di-mark inactive
- HTTP 429/401/403 → key langsung inactive tanpa retry
- Semua keys di provider aktif habis → fallback ke provider berikutnya, `$response->fallbackUsed === true`
- Semua provider habis → throw `GatewayExhaustedException`
- Streaming: `onChunk` dipanggil untuk setiap delta, `ChatResponse` tetap lengkap
- Event callbacks dipanggil sesuai event name

### What NOT to Touch
- File DTO (task 02) — sudah fixed
- File storage (task 03) — sudah fixed
- File exception (task 04) — sudah fixed
- `composer.json` (task 01)
- CLI commands (task 06)

### Definition of Done
- [ ] `src/OctopusLLM.php` ada dan bisa di-instantiate dengan config yang valid
- [ ] `chat()` method mengembalikan `ChatResponse`
- [ ] LRU key rotation bekerja (key dengan `lastUsed` terlama dipilih duluan)
- [ ] Provider fallback bekerja (priority order)
- [ ] Circuit breaker bekerja (failure threshold → mark inactive)
- [ ] HTTP 429/401/403 langsung mark inactive tanpa retry
- [ ] Retry logic bekerja (maxRetries attempts sebelum final failure)
- [ ] Streaming via `createStreamed()` dan `onChunk` callback bekerja
- [ ] `getStatus()` mengembalikan `StatusResponse` yang akurat
- [ ] `ping()` bisa recover inactive key
- [ ] `runRecovery()` mengembalikan `RecoveryReport`
- [ ] `on()` mendaftarkan callback dan event ter-emit di zeitpunkt yang tepat
- [ ] State di-persist via `StorageInterface` setelah setiap mutasi
- [ ] `maxInputTokens` guard throw `InputTooLongException` untuk input terlalu panjang
