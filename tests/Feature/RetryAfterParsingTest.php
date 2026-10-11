<?php

namespace Tests\Feature;

use App\Services\TranslationService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the rate-limit backoff parser: on a 429, retries must honor OpenAI's requested
 * wait (from the message body or a Retry-After header) instead of a blind fixed delay.
 * This is what turns a hard rate-limit failure into a graceful wait-and-succeed.
 */
class RetryAfterParsingTest extends TestCase
{
    private function parse(\Throwable $e): ?float
    {
        $svc = app(TranslationService::class);
        $m = new ReflectionMethod(TranslationService::class, 'parseRetryAfterSeconds');
        $m->setAccessible(true);
        return $m->invoke($svc, $e);
    }

    private function dur(string $v): ?float
    {
        $svc = app(TranslationService::class);
        $m = new ReflectionMethod(TranslationService::class, 'durationToSeconds');
        $m->setAccessible(true);
        return $m->invoke($svc, $v);
    }

    public function test_parses_seconds_phrasing(): void
    {
        $e = new \RuntimeException('Rate limit reached. Please try again in 1.5s. (request id ...)');
        $this->assertEqualsWithDelta(1.5, $this->parse($e), 0.001);
    }

    public function test_parses_milliseconds_phrasing(): void
    {
        $e = new \RuntimeException('429 Too Many Requests: please try again in 200ms');
        $this->assertEqualsWithDelta(0.2, $this->parse($e), 0.001);
    }

    public function test_parses_plain_seconds_word(): void
    {
        $e = new \RuntimeException('Rate limit exceeded, try again in 2 seconds');
        $this->assertEqualsWithDelta(2.0, $this->parse($e), 0.001);
    }

    public function test_returns_null_when_unparseable(): void
    {
        $e = new \RuntimeException('Request rate limit has been exceeded.');
        $this->assertNull($this->parse($e));
    }

    public function test_duration_helper(): void
    {
        $this->assertEqualsWithDelta(0.2, $this->dur('200ms'), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->dur('1s'), 0.001);
        $this->assertEqualsWithDelta(90.0, $this->dur('1m30s'), 0.001);
        $this->assertNull($this->dur('garbage'));
    }

    public function test_retry_after_header_is_honored(): void
    {
        // An exception exposing getResponse()->getHeaderLine() like the HTTP client does.
        $e = new class('rate limit') extends \RuntimeException {
            public function getResponse()
            {
                return new class {
                    public function getHeaderLine($name)
                    {
                        return strtolower($name) === 'retry-after' ? '5' : '';
                    }
                };
            }
        };
        $this->assertEqualsWithDelta(5.0, $this->parse($e), 0.001);
    }
}
