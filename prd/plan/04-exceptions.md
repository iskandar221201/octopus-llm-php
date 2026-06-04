## Feature: Exception Classes

### Context
Custom exception hierarchy untuk error handling yang granular. Setiap jenis error punya exception class tersendiri dengan properties yang relevan, memungkinkan consumer melakukan `catch` yang spesifik.

### Scope
**IN scope:**
- 7 exception classes di namespace `OctopusLLM\Gateway\Exceptions`

**OUT of scope:**
- Logic yang throw exception (dikerjakan di task 05)
- Error handling di consumer code

### Files to Create

- `src/Exceptions/ProviderException.php` → Base exception untuk semua provider errors
- `src/Exceptions/GatewayExhaustedException.php` → Semua provider/key habis
- `src/Exceptions/RateLimitException.php` → HTTP 429
- `src/Exceptions/TimeoutException.php` → Request timeout
- `src/Exceptions/AuthenticationException.php` → HTTP 401/403
- `src/Exceptions/CircuitOpenException.php` → Key inactive (circuit open)
- `src/Exceptions/InputTooLongException.php` → Input melebihi maxInputTokens

### Implementation Steps

1. Buat `src/Exceptions/ProviderException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `\RuntimeException`
   - Properties:
     - `public readonly string $provider`
     - `public readonly string $originalMessage`
   - Constructor: `__construct(string $provider, string $originalMessage, int $code = 0, ?\Throwable $previous = null)`
     - Call `parent::__construct("Provider [{$provider}]: {$originalMessage}", $code, $previous)`
     - Set `$this->provider = $provider`
     - Set `$this->originalMessage = $originalMessage`

2. Buat `src/Exceptions/GatewayExhaustedException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `\RuntimeException`
   - Constructor: `__construct(string $message = 'All providers and keys are exhausted.', int $code = 0, ?\Throwable $previous = null)`
     - Call `parent::__construct($message, $code, $previous)`

3. Buat `src/Exceptions/RateLimitException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `ProviderException`
   - Property: `public readonly ?int $retryAfter` (seconds)
   - Constructor: `__construct(string $provider, string $originalMessage, ?int $retryAfter = null, int $code = 429, ?\Throwable $previous = null)`
     - Call `parent::__construct($provider, $originalMessage, $code, $previous)`
     - Set `$this->retryAfter = $retryAfter`

4. Buat `src/Exceptions/TimeoutException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `ProviderException`
   - Property: `public readonly int $timeoutSeconds`
   - Constructor: `__construct(string $provider, int $timeoutSeconds, int $code = 0, ?\Throwable $previous = null)`
     - Call `parent::__construct($provider, "Request timed out after {$timeoutSeconds}s", $code, $previous)`
     - Set `$this->timeoutSeconds = $timeoutSeconds`

5. Buat `src/Exceptions/AuthenticationException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `ProviderException`
   - Property: `public readonly int $keyIndex`
   - Constructor: `__construct(string $provider, int $keyIndex, int $code = 401, ?\Throwable $previous = null)`
     - Call `parent::__construct($provider, "Authentication failed for key index {$keyIndex}", $code, $previous)`
     - Set `$this->keyIndex = $keyIndex`

6. Buat `src/Exceptions/CircuitOpenException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `ProviderException`
   - Properties:
     - `public readonly int $keyIndex`
     - `public readonly string $retryAt` (ISO 8601 timestamp)
   - Constructor: `__construct(string $provider, int $keyIndex, string $retryAt, int $code = 0, ?\Throwable $previous = null)`
     - Call `parent::__construct($provider, "Circuit open for key index {$keyIndex}, retry at {$retryAt}", $code, $previous)`
     - Set `$this->keyIndex = $keyIndex`
     - Set `$this->retryAt = $retryAt`

7. Buat `src/Exceptions/InputTooLongException.php`:
   - Namespace: `OctopusLLM\Gateway\Exceptions`
   - Extends: `\InvalidArgumentException` (BUKAN `ProviderException`)
   - Properties:
     - `public readonly int $estimated` — estimated token count
     - `public readonly int $limit` — maxInputTokens limit
   - Constructor: `__construct(int $estimated, int $limit, int $code = 0, ?\Throwable $previous = null)`
     - Call `parent::__construct("Input too long: estimated {$estimated} tokens, limit is {$limit}", $code, $previous)`
     - Set `$this->estimated = $estimated`
     - Set `$this->limit = $limit`

### Expected Behavior
- `new ProviderException('groq', 'Server error')` → message: `Provider [groq]: Server error`
- `new RateLimitException('groq', 'Too many requests', 60)` → `$e->retryAfter === 60`
- `new AuthenticationException('groq', 2)` → `$e->keyIndex === 2`
- `new InputTooLongException(5000, 4000)` → `$e->estimated === 5000, $e->limit === 4000`
- `GatewayExhaustedException` bisa di-catch tanpa provider context (standalone)
- `InputTooLongException` extends `\InvalidArgumentException`, bukan `ProviderException`
- Semua provider-related exceptions bisa di-catch via `catch (ProviderException $e)`

### What NOT to Touch
- File DTO (task 02)
- File storage (task 03)
- `composer.json` (task 01)
- File apapun di luar `src/Exceptions/`

### Definition of Done
- [ ] 7 exception classes di `src/Exceptions/`
- [ ] `ProviderException` extends `\RuntimeException` dengan properties `$provider`, `$originalMessage`
- [ ] `GatewayExhaustedException` extends `\RuntimeException`
- [ ] `RateLimitException` extends `ProviderException` dengan `$retryAfter`
- [ ] `TimeoutException` extends `ProviderException` dengan `$timeoutSeconds`
- [ ] `AuthenticationException` extends `ProviderException` dengan `$keyIndex`
- [ ] `CircuitOpenException` extends `ProviderException` dengan `$keyIndex`, `$retryAt`
- [ ] `InputTooLongException` extends `\InvalidArgumentException` dengan `$estimated`, `$limit`
- [ ] Semua class punya namespace `OctopusLLM\Gateway\Exceptions`
