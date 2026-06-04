## Feature: Data Transfer Objects (DTOs)

### Context
Semua DTOs yang digunakan sebagai return type dari public methods di `OctopusLLM`. Readonly, immutable, dibuat via constructor. Ini adalah building blocks yang harus ada sebelum `OctopusLLM` main class bisa diimplementasi.

### Scope
**IN scope:**
- 5 DTO classes: `ChatResponse`, `StatusResponse`, `ProviderStatus`, `KeyStatus`, `RecoveryReport`

**OUT of scope:**
- Business logic apapun
- Validation logic (DTOs hanya data container)
- Exception classes (task terpisah)

### Files to Create

- `src/ChatResponse.php` → DTO untuk response dari `chat()` method
- `src/StatusResponse.php` → DTO untuk response dari `getStatus()` method
- `src/ProviderStatus.php` → DTO untuk status per-provider (child dari `StatusResponse`)
- `src/KeyStatus.php` → DTO untuk status per-key (child dari `ProviderStatus`)
- `src/RecoveryReport.php` → DTO untuk response dari `runRecovery()` method

### Implementation Steps

1. Buat `src/ChatResponse.php`:
   - Namespace: `OctopusLLM\Gateway`
   - `readonly class ChatResponse`
   - Constructor properties:
     - `public string $content` — isi teks response dari AI
     - `public string $provider` — ID provider yang digunakan (misal `'groq'`)
     - `public int $keyIndex` — index key yang digunakan
     - `public string $model` — nama model yang digunakan
     - `public float $latencyMs` — latency dalam milidetik
     - `public int $attempts` — jumlah percobaan sebelum berhasil
     - `public bool $fallbackUsed` — `true` jika pindah ke provider lain

2. Buat `src/StatusResponse.php`:
   - Namespace: `OctopusLLM\Gateway`
   - `readonly class StatusResponse`
   - Constructor properties:
     - `public array $providers` — array of `ProviderStatus` (tambahkan PHPDoc `@param ProviderStatus[] $providers`)
     - `public int $totalActive` — jumlah key aktif
     - `public int $totalInactive` — jumlah key inactive

3. Buat `src/ProviderStatus.php`:
   - Namespace: `OctopusLLM\Gateway`
   - `readonly class ProviderStatus`
   - Constructor properties:
     - `public string $id` — provider ID
     - `public int $priority` — priority number
     - `public array $keys` — array of `KeyStatus` (tambahkan PHPDoc `@param KeyStatus[] $keys`)

4. Buat `src/KeyStatus.php`:
   - Namespace: `OctopusLLM\Gateway`
   - `readonly class KeyStatus`
   - Constructor properties:
     - `public int $index` — index key dalam array keys provider
     - `public string $status` — `'active'` atau `'inactive'`
     - `public int $failureCount` — jumlah failure berturut-turut
     - `public ?string $lastUsed` — ISO 8601 timestamp, `null` jika belum pernah dipakai
     - `public ?string $markedAt` — ISO 8601 timestamp saat di-mark inactive, `null` jika active

5. Buat `src/RecoveryReport.php`:
   - Namespace: `OctopusLLM\Gateway`
   - `readonly class RecoveryReport`
   - Constructor properties:
     - `public array $recovered` — array of `['provider' => string, 'keyIndex' => int]`
     - `public array $failed` — array of `['provider' => string, 'keyIndex' => int]`
     - `public int $total` — total keys yang di-ping

### Expected Behavior
- Semua class bisa di-instantiate via constructor
- Semua properties readonly — tidak bisa diubah setelah construct
- `new ChatResponse('hello', 'groq', 0, 'llama-3.1-8b', 120.5, 1, false)` → valid object
- `new StatusResponse([], 0, 0)` → valid object
- Semua class autoloadable via Composer PSR-4

### What NOT to Touch
- `composer.json` (sudah dikerjakan di task 01)
- File apapun di luar `src/`
- File exception, contract, atau storage

### Definition of Done
- [ ] `src/ChatResponse.php` ada dan berisi readonly class dengan 7 properties
- [ ] `src/StatusResponse.php` ada dengan PHPDoc `@param ProviderStatus[]`
- [ ] `src/ProviderStatus.php` ada dengan PHPDoc `@param KeyStatus[]`
- [ ] `src/KeyStatus.php` ada dengan 5 properties (2 nullable)
- [ ] `src/RecoveryReport.php` ada dengan 3 properties
- [ ] Semua class menggunakan namespace `OctopusLLM\Gateway`
- [ ] Semua class `readonly`
- [ ] Tidak ada method selain `__construct`
