<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards against a Blade comment that never closes.
 *
 * A comment opens on `{{--` and closes only on `--}}`. Write `-}}` and Blade
 * does not error - it keeps looking, finds the next `--}}` somewhere further
 * down the file, and deletes everything in between. The page still returns
 * 200; it is simply missing whatever fell inside.
 *
 * That is not hypothetical. Nine of these had accumulated in section-divider
 * comments of the form `{{-- ----- the cart -}}`, and one of them was
 * swallowing the POS terminal's entire scan/search field - the box a barcode
 * scanner types into. The counter rendered without it and nothing anywhere
 * said so.
 *
 * Two rules, which together cover the whole failure:
 *
 *   1. A comment that ran past its intended end swallows whatever comment
 *      comes next, so no comment body may contain another `{{--`.
 *   2. A comment with no `--}}` after it anywhere survives the strip, so no
 *      `{{--` may be left over.
 *
 * A lint, not a render: a view needing data cannot be exercised here, but
 * every view's comments can be read.
 */
class BladeCommentTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function views(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $cases = [];

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $cases[$relative] = [$file->getPathname()];
        }

        ksort($cases);

        return $cases;
    }

    #[DataProvider('views')]
    public function test_every_blade_comment_is_closed(string $path): void
    {
        $this->assertBladeCommentsClose(file_get_contents($path));
    }

    /**
     * The lint proves itself: a divider ending in `-}}` must be rejected, and
     * the correct spelling accepted. Without this, a regex that quietly
     * matched nothing would pass all 234 views and guard none of them.
     */
    public function test_the_lint_catches_the_bug_it_exists_for(): void
    {
        $broken = <<<'BLADE'
        {{-- ----- the cart -}}
        <input data-line-search>
        {{-- a later, well-formed comment --}}
        <span>kept</span>
        BLADE;

        // Blade really does delete the input - that is the bug being guarded.
        $this->assertStringNotContainsString(
            'data-line-search',
            app('blade.compiler')->compileString($broken),
        );

        try {
            $this->assertBladeCommentsClose($broken);
            $this->fail('the lint passed a comment that Blade never closes');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            // Expected.
        }

        // The same file, spelled correctly, must pass.
        $fixed = str_replace('the cart -}}', 'the cart --}}', $broken);
        $this->assertStringContainsString(
            'data-line-search',
            app('blade.compiler')->compileString($fixed),
        );
        $this->assertBladeCommentsClose($fixed);
    }

    private function assertBladeCommentsClose(string $source): void
    {
        $hint = 'Unclosed Blade comment. Everything after `{{--`, up to the next `--}}` in the '
            .'file, is silently deleted from the rendered page. Look for a divider comment '
            .'ending in `-}}` instead of `--}}`.';

        preg_match_all('/\{\{--(.*?)--\}\}/s', $source, $matches);

        foreach ($matches[1] as $body) {
            $this->assertStringNotContainsString('{{--', $body, $hint);
        }

        $this->assertStringNotContainsString(
            '{{--',
            preg_replace('/\{\{--.*?--\}\}/s', '', $source),
            $hint,
        );
    }
}
