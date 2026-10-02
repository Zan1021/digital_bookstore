<?php

namespace Tests\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * world-class-render-engine spec, Phase 3 — Book typography policy persistence +
 * validation (Req 4). The ENGINE honours a policy (covered by the Python suite); this
 * proves the PHP write-side: a role font is stored, validated against the approved
 * fonts dir, path-like / unknown ids are rejected, and clearing reverts to default.
 */
class TypographyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create([
            'title' => 'Policy Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    public function test_empty_policy_by_default(): void
    {
        $this->assertSame([], $this->book()->getTypographyPolicy());
    }

    public function test_approved_fonts_come_from_the_fonts_dir(): void
    {
        $fonts = Book::approvedFontAssets();
        // The project ships fonts; the set must be non-empty and contain the house font.
        $this->assertNotEmpty($fonts);
        $norm = fn ($s) => strtolower(str_replace(['-', '_', ' '], '', $s));
        $this->assertTrue(
            collect($fonts)->contains(fn ($f) => str_contains($norm($f), 'playpensans')),
            'expected PlaypenSans among approved fonts'
        );
    }

    public function test_set_role_font_persists_when_approved(): void
    {
        $fonts = Book::approvedFontAssets();
        $pick = $fonts[0];
        $book = $this->book();
        $book->setRoleFont('body', $pick);

        $policy = $book->fresh()->getTypographyPolicy();
        $this->assertSame($pick, $policy['roles']['body']['font_asset_id']);
        $this->assertSame(1, $policy['version']);
    }

    public function test_rejects_unknown_font(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->book()->setRoleFont('body', 'NoSuchFontFamily12345');
    }

    public function test_rejects_path_like_asset_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->book()->setRoleFont('body', '../../etc/passwd');
    }

    public function test_clearing_a_role_removes_it(): void
    {
        $fonts = Book::approvedFontAssets();
        $book = $this->book();
        $book->setRoleFont('title', $fonts[0]);
        $this->assertArrayHasKey('title', $book->fresh()->getTypographyPolicy()['roles']);

        $book->setRoleFont('title', null);
        $policy = $book->fresh()->getTypographyPolicy();
        $this->assertArrayNotHasKey('title', $policy['roles'] ?? []);
    }

    public function test_language_override_scopes_to_language(): void
    {
        $fonts = Book::approvedFontAssets();
        $book = $this->book();
        $book->setRoleFont('body', $fonts[0], null, 'af');

        $policy = $book->fresh()->getTypographyPolicy();
        $this->assertSame($fonts[0], $policy['language_overrides']['af']['body']['font_asset_id']);
        $this->assertArrayNotHasKey('body', $policy['roles'] ?? []);
    }
}
