<?php

namespace Tests\Feature;

use App\Services\PhonicsWordlist;
use App\Services\TranslationService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the real-word validity gate layered on top of the substring phonics check.
 *
 * The substring check alone passes any word that merely CONTAINS the taught pattern,
 * including invented/borrowed non-words ("priek" under "iek", "kraft" under "ft").
 * {@see PhonicsWordlist} closes that gap using a per-language wordlist asset, and the
 * gate is advisory + fail-safe: with no asset for a language the check is skipped so
 * nothing regresses.
 *
 * These tests use a tiny in-repo fixture wordlist (not the shipped 100k-word af.txt) so
 * they are fast, deterministic, and independent of the real asset's exact contents.
 */
class PhonicsWordlistGateTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        // A controlled fixture language "zz": only these are "real words".
        $this->fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phonics_wl_' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
        // Real words incl. ones used by the valid rows below. "priek"/"kraft" are absent.
        file_put_contents(
            $this->fixtureDir . DIRECTORY_SEPARATOR . 'zz.txt',
            implode("\n", [
                'boek', 'koek', 'soek',   // oe
                'huis', 'muis', 'duif',   // ui
                'kiekie', 'wiek',         // iek  (NOTE: "priek" deliberately NOT here)
                'eier',                   // real word
            ]) . "\n"
        );
        PhonicsWordlist::useDirectory($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        PhonicsWordlist::useDirectory(null); // restore default + clear cache
        @unlink($this->fixtureDir . DIRECTORY_SEPARATOR . 'zz.txt');
        @rmdir($this->fixtureDir);
        parent::tearDown();
    }

    private function rowValid(string $row, string $lang = ''): bool
    {
        $svc = app(TranslationService::class);
        $m = new ReflectionMethod(TranslationService::class, 'phonicsRowIsValid');
        $m->setAccessible(true);
        return (bool) $m->invoke($svc, $row, $lang);
    }

    public function test_wordlist_presence_detection(): void
    {
        $this->assertTrue(PhonicsWordlist::hasWordlist('zz'));
        $this->assertFalse(PhonicsWordlist::hasWordlist('qq')); // no asset
    }

    public function test_real_word_lookup_is_accent_and_case_insensitive(): void
    {
        $this->assertTrue(PhonicsWordlist::isRealWord('Boek', 'zz'));
        $this->assertTrue(PhonicsWordlist::isRealWord('  koek, ', 'zz')); // punctuation stripped
        $this->assertFalse(PhonicsWordlist::isRealWord('priek', 'zz'));
    }

    public function test_substring_valid_but_not_a_real_word_is_rejected_with_asset(): void
    {
        // "priek" contains the pattern "iek" (passes substring) but is NOT in the list.
        $this->assertFalse($this->rowValid('- iek kiekie, priek, wiek', 'zz'));
        // "kraft" contains "ft" but is not a real word.
        $this->assertFalse($this->rowValid('- ft kraft, klipfisk', 'zz'));
    }

    public function test_real_words_that_contain_the_pattern_pass(): void
    {
        $this->assertTrue($this->rowValid('- oe boek, koek, soek', 'zz'));
        $this->assertTrue($this->rowValid('- ui huis, muis, duif', 'zz'));
    }

    public function test_no_asset_language_falls_back_to_substring_only(): void
    {
        // Language "qq" has no list → the real-word gate is skipped, so a word that
        // merely contains the pattern passes (original substring-only behaviour).
        $this->assertTrue($this->rowValid('- iek kiekie, priek, wiek', 'qq'));
        // Still enforces substring even without an asset.
        $this->assertFalse($this->rowValid('- ft klipfisk', 'qq'));
    }

    public function test_empty_langcode_preserves_legacy_substring_behaviour(): void
    {
        // No lang passed at all → unchanged legacy behaviour (what the existing
        // PhonicsValidationTest relies on): "priek" passes because it contains "iek".
        $this->assertTrue($this->rowValid('- iek kiekie, priek, wiek'));
        $this->assertFalse($this->rowValid('- ft klipfisk')); // substring still enforced
    }

    public function test_problem_reason_distinguishes_patternless_from_nonword(): void
    {
        $svc = app(TranslationService::class);
        $m = new ReflectionMethod(TranslationService::class, 'phonicsRowProblems');
        $m->setAccessible(true);

        $nonword = $m->invoke($svc, '- iek priek, wiek', 'zz');
        $this->assertStringContainsString('priek', $nonword);
        $this->assertStringContainsString('not real words', $nonword);

        $patternless = $m->invoke($svc, '- ft klipfisk', 'zz');
        $this->assertStringContainsString('klipfisk', $patternless);
        $this->assertStringContainsString('do not contain the pattern', $patternless);
    }
}
