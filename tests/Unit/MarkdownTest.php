<?php

namespace Tests\Unit;

use App\Support\Markdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The renderer is the only place merchant copy becomes HTML, so these cover the
 * escaping contract as hard as the formatting: a description is author input
 * that ends up in a shopper's browser, and the guarantee is that the only
 * markup in the output is the tag set the renderer itself emits.
 */
class MarkdownTest extends TestCase
{
    public function test_it_renders_the_supported_inline_markers(): void
    {
        $this->assertSame('<p><strong>bold</strong></p>', Markdown::toHtml('**bold**'));
        $this->assertSame('<p><strong>bold</strong></p>', Markdown::toHtml('__bold__'));
        $this->assertSame('<p><em>italic</em></p>', Markdown::toHtml('*italic*'));
        $this->assertSame('<p><em>italic</em></p>', Markdown::toHtml('_italic_'));
        $this->assertSame('<p><code>verbatim</code></p>', Markdown::toHtml('`verbatim`'));
    }

    public function test_strong_wins_over_emphasis(): void
    {
        $this->assertSame('<p><strong>bold</strong></p>', Markdown::toHtml('**bold**'));
        $this->assertSame('<p>a <strong>b</strong> c</p>', Markdown::toHtml('a **b** c'));
    }

    public function test_underscores_inside_a_word_are_not_emphasis(): void
    {
        $this->assertSame('<p>snake_case_name</p>', Markdown::toHtml('snake_case_name'));
        $this->assertSame('<p>CHAMA_perfumes</p>', Markdown::toHtml('CHAMA_perfumes'));
    }

    public function test_a_marker_pair_spanning_other_markup_still_balances(): void
    {
        $this->assertSame(
            '<p><strong>one</strong> and <code>**two**</code></p>',
            Markdown::toHtml('**one** and `**two**`')
        );
    }

    public function test_it_renders_blocks(): void
    {
        $this->assertSame("<p>first</p>\n<p>second</p>", Markdown::toHtml("first\n\nsecond"));
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', Markdown::toHtml("- a\n- b"));
        // Both `1.` and `2)` open an ordered list, and the marker is consumed.
        $this->assertSame('<ol><li>a</li><li>b</li></ol>', Markdown::toHtml("1. a\n2) b"));
        $this->assertSame('<h2>Notes</h2>', Markdown::toHtml('## Notes'));
        $this->assertSame('<h3>Deep</h3>', Markdown::toHtml('### Deep'));
        $this->assertSame('<blockquote><p>quoted</p></blockquote>', Markdown::toHtml('> quoted'));
    }

    public function test_a_single_newline_is_a_line_break(): void
    {
        $this->assertSame('<p>one<br>two</p>', Markdown::toHtml("one\ntwo"));
    }

    public function test_a_list_marker_needs_whitespace_so_emphasis_is_not_a_list(): void
    {
        $this->assertSame('<p><em>note</em></p>', Markdown::toHtml('*note*'));
    }

    public function test_it_renders_links_and_refuses_unsafe_schemes(): void
    {
        $this->assertSame(
            '<p><a href="https://chama.ma" target="_blank" rel="noopener noreferrer">brand</a></p>',
            Markdown::toHtml('[brand](https://chama.ma)')
        );

        // Schemeless, so it stays on this site and gets no target/rel.
        $this->assertSame('<p><a href="/fr/products/x">here</a></p>', Markdown::toHtml('[here](/fr/products/x)'));

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,PHN2Zz4=', 'vbscript:x'] as $hostile) {
            $this->assertSame(
                "<p>[click]({$hostile})</p>",
                Markdown::toHtml("[click]({$hostile})"),
                "scheme {$hostile} must not become an anchor"
            );
        }

        // Protocol-relative inherits the page scheme, so it is refused too.
        $this->assertSame('<p>[click](//evil.example)</p>', Markdown::toHtml('[click](//evil.example)'));
    }

    public function test_emphasis_works_inside_a_link_label(): void
    {
        $this->assertSame(
            '<p><a href="https://chama.ma" target="_blank" rel="noopener noreferrer"><strong>brand</strong></a></p>',
            Markdown::toHtml('[**brand**](https://chama.ma)')
        );
    }

    public function test_images_are_not_interpreted_as_links(): void
    {
        $this->assertSame('<p>![alt](photo.png)</p>', Markdown::toHtml('![alt](photo.png)'));
    }

    /**
     * The core guarantee: author HTML never survives as markup.
     */
    #[DataProvider('hostileInputs')]
    public function test_author_html_is_escaped(string $input, string $expected): void
    {
        $this->assertSame($expected, Markdown::toHtml($input));
    }

    public static function hostileInputs(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'],
            'img onerror' => ['<img src=x onerror=alert(1)>', '<p>&lt;img src=x onerror=alert(1)&gt;</p>'],
            'svg onload' => ['<svg/onload=alert(1)>', '<p>&lt;svg/onload=alert(1)&gt;</p>'],
            'iframe' => ['<iframe src="//evil"></iframe>', '<p>&lt;iframe src=&quot;//evil&quot;&gt;&lt;/iframe&gt;</p>'],
            'closing tag break-out' => ['a</p><script>alert(1)</script>', '<p>a&lt;/p&gt;&lt;script&gt;alert(1)&lt;/script&gt;</p>'],
            'raw ampersand' => ['Tom & Jerry', '<p>Tom &amp; Jerry</p>'],
            'lone angle brackets' => ['5 < 10 & 20 > 3', '<p>5 &lt; 10 &amp; 20 &gt; 3</p>'],
        ];
    }

    public function test_no_generated_anchor_can_carry_an_unexpected_scheme(): void
    {
        preg_match_all('/href="([^"]*)"/', Markdown::toHtml('[a](javascript:x) [b](data:y) [c](https://z.ma)'), $m);

        $this->assertSame(['https://z.ma'], $m[1]);
    }

    public function test_a_url_cannot_break_out_of_its_attribute(): void
    {
        // No spaces, so this does become an anchor — the quote in the query
        // string is what must not terminate the href attribute.
        $html = Markdown::toHtml('[x](https://a.ma/?q="a"onmouseover="b)');

        $this->assertSame(1, substr_count($html, 'href="'));
        $this->assertStringNotContainsString('"onmouseover="', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function test_a_url_containing_a_space_is_not_linked_at_all(): void
    {
        $html = Markdown::toHtml('[x](https://a.ma/" onmouseover="alert(1))');

        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function test_a_nul_in_the_input_cannot_forge_a_placeholder(): void
    {
        $html = Markdown::toHtml("**a\0 0\0b**");

        $this->assertStringNotContainsString("\0", $html);
    }

    public function test_empty_and_null_input_render_nothing(): void
    {
        $this->assertSame('', Markdown::toHtml(null));
        $this->assertSame('', Markdown::toHtml(''));
        $this->assertSame('', Markdown::toHtml("\n\n   \n"));
    }

    public function test_unclosed_markers_are_left_as_text(): void
    {
        $this->assertSame('<p>**unclosed</p>', Markdown::toHtml('**unclosed'));
        $this->assertSame('<p>[label](</p>', Markdown::toHtml('[label]('));
    }

    public function test_plain_text_drops_markup_and_keeps_word_boundaries(): void
    {
        $this->assertSame('Bold link one two', Markdown::toPlainText("**Bold** [link](https://a.ma)\n\n- one\n- two"));
    }

    public function test_plain_text_of_empty_input_is_empty(): void
    {
        $this->assertSame('', Markdown::toPlainText(null));
        $this->assertSame('', Markdown::toPlainText(''));
    }

    public function test_plain_text_agrees_with_the_html(): void
    {
        $source = "## Notes\nTop note is bergamot.\n\n**Rose** heart, see [the house](https://chama.ma).";

        $this->assertStringNotContainsString('<', Markdown::toPlainText($source));
        $this->assertStringContainsString('bergamot', Markdown::toPlainText($source));
        $this->assertStringContainsString('the house', Markdown::toPlainText($source));
        $this->assertStringNotContainsString('https', Markdown::toPlainText($source));
    }
}
