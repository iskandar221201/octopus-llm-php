## Feature: Project Scaffold

### Context
Setup dasar project `octopus-llm-php` sebagai Composer package. Ini adalah langkah pertama yang harus selesai sebelum task lain bisa dikerjakan. Tanpa ini, autoloading tidak akan bekerja.

### Scope
**IN scope:**
- Buat `composer.json` dengan konfigurasi autoload PSR-4
- Buat `.gitignore`

**OUT of scope:**
- Instalasi dependency (`composer install` dilakukan manual setelah file dibuat)
- Semua file source code (dikerjakan di task lain)

### Files to Create
- `composer.json` → Composer package definition dengan PSR-4 autoload
- `.gitignore` → Git ignore rules

### Implementation Steps

1. Buat file `composer.json` di root project dengan isi:
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

2. Buat file `.gitignore` di root project dengan isi:
   ```
   vendor/
   composer.lock
   .phpunit.result.cache
   ```

3. Buat directory kosong `src/` (jika belum ada)

4. Buat directory kosong `tests/` (jika belum ada)

### Expected Behavior
- `composer validate` harus sukses tanpa error
- Namespace `OctopusLLM\Gateway\` ter-map ke `src/`
- Namespace `OctopusLLM\Gateway\Tests\` ter-map ke `tests/`
- `vendor/`, `composer.lock`, `.phpunit.result.cache` ter-ignore oleh git

### What NOT to Touch
- Semua file di luar root project
- File apapun di `src/` atau `tests/` (belum ada, dikerjakan di task lain)
- File `prd/`, `agent/`

### Definition of Done
- [ ] `composer.json` ada di root, valid JSON, bisa di-validate oleh `composer validate`
- [ ] `.gitignore` ada di root dengan entry yang benar
- [ ] Directory `src/` dan `tests/` ada (boleh kosong)
- [ ] Tidak ada file lain yang diubah
