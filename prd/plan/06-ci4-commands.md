## Feature: CI4 Spark Commands

### Context
CLI commands untuk CodeIgniter 4 `spark` runner. Memungkinkan user manage gateway dari command line: validate config, check status, benchmark providers, test prompt, dan manual recovery. Commands ini opsional — hanya loaded jika CI4 tersedia.

**Dependency:** Task 01–05 harus selesai duluan. Commands menggunakan `OctopusLLM` class.

### Scope
**IN scope:**
- 5 spark command classes

**OUT of scope:**
- CI4 service registration (itu urusan consumer app)
- CI4 scheduler setup (itu urusan consumer app)
- `OctopusLLM` class itself (task 05)

### Files to Create

- `src/Commands/OctopusValidate.php` → php spark octopus:validate
- `src/Commands/OctopusStatus.php` → php spark octopus:status [--json]
- `src/Commands/OctopusBenchmark.php` → php spark octopus:benchmark [--samples N]
- `src/Commands/OctopusTest.php` → php spark octopus:test [--prompt "..."]
- `src/Commands/OctopusRecover.php` → php spark octopus:recover

### Implementation Steps

1. Buat `src/Commands/OctopusValidate.php`:
   - Namespace: `OctopusLLM\Gateway\Commands`
   - Extends: `\CodeIgniter\CLI\BaseCommand`
   - Properties:
     - `$group = 'OctopusLLM'`
     - `$name = 'octopus:validate'`
     - `$description = 'Validate provider configuration and API keys'`
   - Method `run(array $params): void`:
     - Get OctopusLLM instance via `\Config\Services::octopus(false)` (non-shared untuk fresh instance)
     - Bisa juga terima config dari constructor/static method
     - Iterate semua providers dari config:
       - Check `id` not empty
       - Check `baseURL` not empty
       - Check `model` not empty
       - Check `keys` is array dan not empty
       - Check setiap key string not empty (trim whitespace)
     - Output per provider: ✓ atau ✗ dengan detail
     - Output summary: `"X providers validated, Y issues found"`

2. Buat `src/Commands/OctopusStatus.php`:
   - Namespace: `OctopusLLM\Gateway\Commands`
   - Extends: `\CodeIgniter\CLI\BaseCommand`
   - Properties:
     - `$group = 'OctopusLLM'`
     - `$name = 'octopus:status'`
     - `$description = 'Show status of all providers and keys'`
     - `$options = ['--json' => 'Output as JSON']`
   - Method `run(array $params): void`:
     - Get OctopusLLM instance
     - Call `$gateway->getStatus()`
     - Jika `--json` flag → output `json_encode($status, JSON_PRETTY_PRINT)`
     - Else → format sebagai table:
       ```
       Provider: groq (priority: 1)
         Key #0: active  | failures: 0 | last used: 2024-01-01T12:00:00
         Key #1: inactive | failures: 3 | marked at: 2024-01-01T11:55:00
       
       Provider: openrouter (priority: 2)
         Key #0: active  | failures: 0 | last used: never
       
       Summary: 2 active, 1 inactive
       ```

3. Buat `src/Commands/OctopusBenchmark.php`:
   - Namespace: `OctopusLLM\Gateway\Commands`
   - Extends: `\CodeIgniter\CLI\BaseCommand`
   - Properties:
     - `$group = 'OctopusLLM'`
     - `$name = 'octopus:benchmark'`
     - `$description = 'Benchmark latency of all active providers'`
     - `$options = ['--samples' => 'Number of samples per provider (default: 3)']`
   - Method `run(array $params): void`:
     - Parse `--samples` option, default 3
     - Untuk setiap provider:
       - Untuk setiap active key (key pertama saja cukup):
         - Send test prompt N kali: `chat([['role' => 'user', 'content' => 'Say "ok"']], ['forceProvider' => $id, 'maxTokens' => 5])`
         - Record latency dari `$response->latencyMs`
     - Calculate: min, max, avg latency per provider
     - Output ranked by avg latency:
       ```
       #1 cerebras — avg: 120ms (min: 100, max: 150) — 3 samples
       #2 groq     — avg: 250ms (min: 200, max: 350) — 3 samples
       #3 openrouter — avg: 800ms (min: 600, max: 1200) — 3 samples
       ```
     - Catch exceptions per provider → report as "FAILED"

4. Buat `src/Commands/OctopusTest.php`:
   - Namespace: `OctopusLLM\Gateway\Commands`
   - Extends: `\CodeIgniter\CLI\BaseCommand`
   - Properties:
     - `$group = 'OctopusLLM'`
     - `$name = 'octopus:test'`
     - `$description = 'Send a test prompt to the gateway'`
     - `$options = ['--prompt' => 'Test prompt (default: "Say hello in one sentence")']`
   - Method `run(array $params): void`:
     - Parse `--prompt` option, default `'Say hello in one sentence'`
     - Call `$gateway->chat([['role' => 'user', 'content' => $prompt]])`
     - Output:
       ```
       Provider: groq
       Model: llama-3.1-8b-instant
       Key Index: 2
       Latency: 1210ms
       Attempts: 1
       Fallback Used: No
       
       Response:
       Hello! How can I assist you today?
       ```
     - Catch `GatewayExhaustedException` → output error message
     - Catch `InputTooLongException` → output token limit info

5. Buat `src/Commands/OctopusRecover.php`:
   - Namespace: `OctopusLLM\Gateway\Commands`
   - Extends: `\CodeIgniter\CLI\BaseCommand`
   - Properties:
     - `$group = 'OctopusLLM'`
     - `$name = 'octopus:recover'`
     - `$description = 'Attempt to recover inactive keys'`
   - Method `run(array $params): void`:
     - Call `$gateway->runRecovery()`
     - Output:
       ```
       Recovery Report
       ───────────────
       Recovered:
         ✓ groq key #1
         ✓ openrouter key #0
       
       Still Inactive:
         ✗ cerebras key #2
       
       Total pinged: 3 | Recovered: 2 | Failed: 1
       ```
     - Jika tidak ada inactive keys → output `"No inactive keys to recover"`

### Expected Behavior
- `php spark octopus:validate` → validasi config, output ✓/✗ per provider
- `php spark octopus:status` → tabel status, `--json` → JSON output
- `php spark octopus:benchmark --samples 5` → benchmark 5 samples per provider, ranked by latency
- `php spark octopus:test --prompt "Siapa presiden RI?"` → kirim prompt, tampilkan response + metadata
- `php spark octopus:recover` → ping inactive keys, tampilkan report
- Semua command require CI4 framework — graceful error jika dijalankan tanpa CI4

### What NOT to Touch
- `src/OctopusLLM.php` (task 05)
- File DTO (task 02)
- File storage (task 03)
- File exception (task 04)
- `composer.json` (task 01)
- Consumer app config files (`app/Config/Services.php`, `app/Config/Tasks.php`)

### Definition of Done
- [ ] 5 command files di `src/Commands/`
- [ ] Setiap command extends `\CodeIgniter\CLI\BaseCommand`
- [ ] Setiap command punya `$group`, `$name`, `$description`
- [ ] `octopus:validate` → validasi config, output per provider
- [ ] `octopus:status` → tabel status, support `--json` flag
- [ ] `octopus:benchmark` → benchmark per provider, support `--samples` option
- [ ] `octopus:test` → kirim prompt, tampilkan response + metadata, support `--prompt` option
- [ ] `octopus:recover` → recovery report dengan ✓/✗ per key
- [ ] Semua command handle exceptions gracefully
