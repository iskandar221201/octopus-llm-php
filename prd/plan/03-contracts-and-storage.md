## Feature: Contracts & Storage Implementations

### Context
Storage layer untuk state persistence. Di PHP, setiap request adalah proses baru sehingga state circuit breaker (key status, failure count, cooldown timestamps) harus disimpan ke persistent storage. Interface + 2 implementasi: `JsonFileStorage` (default, production) dan `NullStorage` (testing).

### Scope
**IN scope:**
- `StorageInterface` contract
- `JsonFileStorage` — default storage, JSON file dengan file locking
- `NullStorage` — no-op, untuk testing

**OUT of scope:**
- Redis/DB storage (user implement sendiri via `StorageInterface`)
- Business logic yang menggunakan storage (dikerjakan di task 05)

### Files to Create

- `src/Contracts/StorageInterface.php` → Interface contract untuk storage
- `src/Storage/JsonFileStorage.php` → Default JSON file storage dengan flock
- `src/Storage/NullStorage.php` → No-op storage untuk testing

### Implementation Steps

1. Buat `src/Contracts/StorageInterface.php`:
   - Namespace: `OctopusLLM\Gateway\Contracts`
   - Interface dengan 2 method:
     - `public function load(): array` — load state, return `[]` jika belum ada
     - `public function save(array $state): void` — persist state

2. Buat `src/Storage/JsonFileStorage.php`:
   - Namespace: `OctopusLLM\Gateway\Storage`
   - Implements: `OctopusLLM\Gateway\Contracts\StorageInterface`
   - Constructor: `__construct(string $path = 'storage/octopus-llm/state.json')`
   - Property: `private string $path`
   - Method `load(): array`:
     - Jika file tidak ada → return `[]`
     - Buka file dengan `fopen($this->path, 'r')`
     - Acquire shared lock: `flock($handle, LOCK_SH)`
     - Baca isi: `stream_get_contents($handle)`
     - Release lock: `flock($handle, LOCK_UN)`
     - Close file: `fclose($handle)`
     - Decode JSON: `json_decode($contents, true)` → return hasilnya
     - Jika decode gagal → return `[]`
   - Method `save(array $state): void`:
     - Auto-create directory jika belum ada: `mkdir(dirname($this->path), 0755, true)`
     - Encode JSON: `json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`
     - Atomic write strategy:
       - Tulis ke temp file: `$tmpPath = $this->path . '.tmp'`
       - `file_put_contents($tmpPath, $json, LOCK_EX)`
       - Rename: `rename($tmpPath, $this->path)`
     - Jika `rename` gagal (Windows edge case): fallback ke direct write dengan `flock(LOCK_EX)`

3. Buat `src/Storage/NullStorage.php`:
   - Namespace: `OctopusLLM\Gateway\Storage`
   - Implements: `OctopusLLM\Gateway\Contracts\StorageInterface`
   - Method `load(): array` → return `[]`
   - Method `save(array $state): void` → no-op (empty body)

### Expected Behavior
- `JsonFileStorage::load()` return `[]` jika file belum ada (first run)
- `JsonFileStorage::save(['key' => 'value'])` → file tertulis sebagai pretty-printed JSON
- `JsonFileStorage::load()` setelah `save()` → return data yang sama
- Concurrent access aman karena `flock()`
- Directory auto-created jika belum ada
- `NullStorage::load()` selalu return `[]`
- `NullStorage::save()` tidak melakukan apa-apa

### What NOT to Touch
- `composer.json`
- File DTO (task 02)
- File exception (task 04)
- File di luar namespace `OctopusLLM\Gateway\Contracts` dan `OctopusLLM\Gateway\Storage`

### Definition of Done
- [ ] `src/Contracts/StorageInterface.php` ada dengan 2 method signature
- [ ] `src/Storage/JsonFileStorage.php` ada, implements `StorageInterface`
- [ ] `JsonFileStorage` menggunakan `flock()` untuk file locking
- [ ] `JsonFileStorage` melakukan atomic write (write temp → rename)
- [ ] `JsonFileStorage` auto-create directory
- [ ] `src/Storage/NullStorage.php` ada, implements `StorageInterface`
- [ ] `NullStorage::load()` return `[]`
- [ ] `NullStorage::save()` adalah no-op
