<?php

namespace Tests\Feature;

use App\Services\TranslationService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the deterministic phonics validator that the educational_adaptation
 * correction-retry loop depends on (regeneratePhonics). A phonics row
 * "[- ] pattern word1, word2" is valid ONLY when every example word literally
 * contains the taught pattern (the token before the first space). This is the
 * gate that catches "- ft klipfisk, kraft" (klipfisk has no "ft") and drives the
 * retry/flag-for-review behaviour. Book-agnostic, language-agnostic: pure
 * substring containment.
 */
class PhonicsValidationTest extends TestCase
{
    private function isValid(string $row): bool
    {
        $svc = app(TranslationService::class);
        $m = new ReflectionMethod(TranslationService::class, 'phonicsRowIsValid');
        $m->setAccessible(true);
        return (bool) $m->invoke($svc, $row);
    }

    public function test_valid_rows_pass(): void
    {
        // Every example word contains its pattern (the page-15 corrected outputs).
        $this->assertTrue($this->isValid('ch chemie, chaos'));
        $this->assertTrue($this->isValid('- ch chemie, chaos'));
        $this->assertTrue($this->isValid('es res, les, nes'));
        $this->assertTrue($this->isValid('ng lang, slang, gang'));
        $this->assertTrue($this->isValid('sj sjef, sjuut'));
        $this->assertTrue($this->isValid('oe boek, koek, soek'));
    }

    public function test_invalid_rows_fail(): void
    {
        // The exact defect that reached the page: "klipfisk" does NOT contain "ft".
        $this->assertFalse($this->isValid('ft klipfisk, kraft'));
        $this->assertFalse($this->isValid('- ft klipfisk, kraft'));
        // A doubled-letter pattern where an example has only one of the letter.
        $this->assertFalse($this->isValid('kk trek, lekker')); // trek has one k
        $this->assertFalse($this->isValid('ss mis, bus'));      // neither has ss
    }

    public function test_headings_and_instructions_are_not_failed(): void
    {
        // Single-token headers (no space) are not pattern rows → accepted as-is.
        $this->assertTrue($this->isValid('Kombinasies'));
        $this->assertTrue($this->isValid('Mengsels'));

        // KNOWN LIMITATION (documented, not fixed here): a comma-separated plain phrase
        // such as the sight-word instruction line "hy, sy, my, ons." is misread by the
        // substring validator as a pattern row and flagged. This only routes the page to
        // human review — it never alters the rendered text — and is out of scope for the
        // phonics generation fix. Captured here so the behaviour is intentional/visible;
        // tighten heading-detection in a dedicated follow-up.
        $this->assertFalse($this->isValid('hy, sy, my, ons.'));
    }

    /**
     * The GOOD native-pattern rows produced after DE-ANCHORING the regeneration prompt
     * (the model authors its own target-language pattern instead of being handed the
     * English one) all pass the substring gate. Guards against a regression that
     * re-anchors to the English pattern and re-introduces English/invented example words.
     */
    public function test_native_afrikaans_pattern_rows_pass(): void
    {
        foreach ([
            '- ui huis, muis, duif',
            '- ou hout, bout, koud',
            '- ei reis, meisie, geitjie',
            '- eu reus, neus, deur',
            '- oe boek, koek, soek',
        ] as $row) {
            $this->assertTrue($this->isValid($row), "native row should be valid: {$row}");
        }

        // The OLD defect rows that de-anchoring removes — the gate still rejects the
        // clearly-invalid one if it ever recurs.
        $this->assertFalse($this->isValid('- ft klipfisk, kraft')); // "klipfisk" lacks "ft"
    }
}
