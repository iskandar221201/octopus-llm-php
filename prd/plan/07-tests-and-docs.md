## Feature: Unit Tests & Documentation

### Context
Test suite dan README untuk memastikan semua business logic bekerja benar dan package bisa digunakan oleh consumer. Semua test fully mocked — tidak ada real API calls.

**Dependency:** Task 01–06 harus selesai duluan. Tests menguji code yang sudah ditulis.

### Scope
**IN scope:**
- 6 test classes di `tests/Unit/`
- `phpunit.xml` configuration
- `README.md` dengan full API reference

**OUT of scope:**
- Integration test dengan real API (consumer test)
- CI/CD pipeline setup

### Files to Create

- `phpunit.xml` → PHPUnit configuration
- `tests/Unit/StorageTest.php` → Test JsonFileStorage dan NullStorage
- `tests/Unit/RotationTest.php` → Test LRU key selection dan provider fallback
- `tests/Unit/CircuitBreakerTest.php` → Test failure threshold dan mark inactive
- `tests/Unit/GuardTest.php` → Test maxInputTokens dan InputTooLongException
- `tests/Unit/ChatTest.php` → Test full chat flow dengan mocked client
- `tests/Unit/EventTest.php` → Test callback registration dan emission
- `README.md` → Package documentation

### Implementation Steps

1. Buat `phpunit.xml` di root:
   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
            bootstrap="vendor/autoload.php"
            colors="true"
            testdox="true">
       <testsuites>
           <testsuite name="Unit">
               <directory>tests/Unit</directory>
           </testsuite>
       </testsuites>
   </phpunit>
   ```

2. Buat `tests/Unit/StorageTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Extends: `PHPUnit\Framework\TestCase`
   - Test methods:
     - `test_null_storage_load_returns_empty_array()` — `NullStorage::load()` return `[]`
     - `test_null_storage_save_does_nothing()` — `NullStorage::save()` is no-op
     - `test_json_storage_returns_empty_when_file_not_exists()` — file belum ada, return `[]`
     - `test_json_storage_save_and_load()` — save data, load kembali, verify sama
     - `test_json_storage_auto_creates_directory()` — directory belum ada, auto-created after save
     - `test_json_storage_handles_corrupted_json()` — file ada tapi isi invalid JSON, return `[]`
   - Setup/Teardown: gunakan temp directory (`sys_get_temp_dir()`) untuk test files, cleanup after each test

3. Buat `tests/Unit/RotationTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Test methods:
     - `test_selects_least_recently_used_key()` — key dengan `lastUsed` terlama dipilih
     - `test_selects_null_last_used_first()` — key yang belum pernah dipakai dipilih duluan
     - `test_skips_inactive_keys()` — key inactive di-skip
     - `test_falls_back_to_next_provider()` — semua keys di provider 1 gagal → pindah ke provider 2
     - `test_respects_provider_priority_order()` — provider priority 1 dicoba duluan, baru 2, baru 3
   - Implementation: instantiate `OctopusLLM` dengan `NullStorage` dan injected/mocked state, mock `openai-php/client`

4. Buat `tests/Unit/CircuitBreakerTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Test methods:
     - `test_marks_key_inactive_after_failure_threshold()` — N failures → inactive
     - `test_does_not_mark_inactive_below_threshold()` — N-1 failures → masih active
     - `test_rate_limit_immediately_marks_inactive()` — HTTP 429 → langsung inactive
     - `test_auth_error_immediately_marks_inactive()` — HTTP 401 → langsung inactive
     - `test_cooldown_prevents_premature_retry()` — key baru inactive, cooldown belum elapsed → skip
     - `test_half_open_allows_retry_after_cooldown()` — cooldown elapsed → bisa dicoba lagi

5. Buat `tests/Unit/GuardTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Test methods:
     - `test_throws_input_too_long_for_excessive_tokens()` — input > maxInputTokens → exception
     - `test_allows_input_within_token_limit()` — input <= maxInputTokens → no exception
     - `test_token_estimation_uses_4_chars_per_token()` — verify formula `ceil(chars / 4)`
     - `test_exception_contains_estimated_and_limit()` — exception properties benar

6. Buat `tests/Unit/ChatTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Test methods:
     - `test_successful_chat_returns_chat_response()` — happy path, verify all DTO fields
     - `test_retry_on_transient_failure()` — first attempt fail, second succeed
     - `test_throws_gateway_exhausted_when_all_fail()` — semua provider/key fail → exception
     - `test_streaming_calls_on_chunk()` — stream mode, `onChunk` dipanggil per delta
     - `test_force_provider_bypasses_rotation()` — `forceProvider` option
     - `test_fallback_used_flag_is_true_when_fallback()` — fallback terjadi, flag `true`
   - Mocking: mock `openai-php/client` menggunakan PHPUnit mock atau test double

7. Buat `tests/Unit/EventTest.php`:
   - Namespace: `OctopusLLM\Gateway\Tests\Unit`
   - Test methods:
     - `test_registers_event_listener()` — `on()` tidak throw error
     - `test_emits_key_deactivated_event()` — key di-mark inactive → callback dipanggil
     - `test_emits_provider_exhausted_event()` — semua keys di provider fail → callback dipanggil
     - `test_emits_fallback_event()` — fallback ke provider lain → callback dipanggil
     - `test_emits_key_recovered_event()` — key recovered via ping → callback dipanggil
     - `test_invalid_event_name_throws_exception()` — event name tidak dikenali → exception

8. Buat `README.md` di root project:
   - Sections:
     - **Overview** — apa itu octopus-llm-php, value proposition
     - **Installation** — `composer require octopus-llm/php`
     - **Quick Start** — minimal working example
     - **Configuration** — full config reference dengan semua options
     - **API Reference** — `chat()`, `getStatus()`, `ping()`, `runRecovery()`, `on()`
     - **Streaming** — streaming usage example
     - **Error Handling** — semua exception classes dan usage
     - **Events** — semua event types dan callback signatures
     - **CI4 Integration** — Service registration, scheduled recovery, spark commands
     - **Storage** — custom StorageInterface implementation guide
     - **Non-Goals** — apa yang tidak di-support di v1

### Expected Behavior
- `vendor/bin/phpunit` → semua tests pass (green)
- Tidak ada test yang memerlukan API keys atau network access
- README cukup lengkap untuk user bisa install dan pakai tanpa baca source code

### What NOT to Touch
- Semua file `src/` (sudah final dari task 01–06)
- `composer.json` (task 01, kecuali jika perlu tambah dev dependency)
- File `prd/` dan `agent/`

### Definition of Done
- [ ] `phpunit.xml` ada di root
- [ ] 6 test files di `tests/Unit/`
- [ ] `vendor/bin/phpunit` → all tests pass
- [ ] Tidak ada test yang hit real API
- [ ] `README.md` ada di root dengan semua sections:
  - [ ] Overview
  - [ ] Installation
  - [ ] Quick Start
  - [ ] Configuration
  - [ ] API Reference
  - [ ] Streaming
  - [ ] Error Handling
  - [ ] Events
  - [ ] CI4 Integration
  - [ ] Storage
  - [ ] Non-Goals
