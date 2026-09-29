<?php

namespace App\Support;

/**
 * A deliberately small Markdown subset for merchant-authored product copy.
 *
 * The site needs bold, italics, links and lists in a description, and nothing
 * else. Rather than take a renderer dependency, this handles exactly that and
 * nothing more — which is also what makes it safe.
 *
 * The whole input is HTML-escaped *first*. From that point on every literal
 * `<`, `>`, `&` and quote is inert text, and the only markup that can reach a
 * shopper is what this class puts back itself, from a fixed tag set. There is no
 * pass-through of author HTML, so a `<script>` typed into a description renders
 * as visible text and cannot execute.
 *
 * Links are the one place where the author's intent becomes an attribute, so
 * the scheme is checked against an allow-list (http, https, mailto, tel) plus
 * schemeless relative URLs. `javascript:` and `data:` are dropped and the label
 * is shown as plain text instead, which loses the link but keeps the sentence.
 */
final class Markdown
{
    /**
     * Render to a safe HTML fragment. Returns '' for empty input so callers can
     * test the result without a null check.
     */
    public static function toHtml(?string $text): string
    {
        $lines = explode("\n", self::normalize($text));

        $html = [];
        $paragraph = [];
        $list = null;
        $quote = [];

        $flushParagraph = function () use (&$paragraph, &$html) {
            if ($paragraph === []) {
                return;
            }

            $body = self::inline(implode("\n", $paragraph));
            $paragraph = [];

            if ($body !== '') {
                $html[] = '<p>'.$body.'</p>';
            }
        };

        $flushList = function () use (&$list, &$html) {
            if ($list === null) {
                return;
            }

            $tag = $list['type'];
            $items = '';

            foreach ($list['items'] as $item) {
                $items .= '<li>'.self::inline($item).'</li>';
            }

            $html[] = '<'.$tag.'>'.$items.'</'.$tag.'>';
            $list = null;
        };

        $flushQuote = function () use (&$quote, &$html) {
            if ($quote === []) {
                return;
            }

            $html[] = '<blockquote><p>'.self::inline(implode("\n", $quote)).'</p></blockquote>';
            $quote = [];
        };

        $flushAll = function () use ($flushParagraph, $flushList, $flushQuote) {
            $flushParagraph();
            $flushList();
            $flushQuote();
        };

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                $flushAll();

                continue;
            }

            // `#` to `###`. A description sits under the product's own h1, so
            // it never emits a competing level 1.
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flushAll();
                $level = strlen($m[1]);
                $body = self::inline($m[2]);

                if ($body !== '') {
                    $html[] = "<h{$level}>{$body}</h{$level}>";
                }

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $quote[] = $m[1];

                continue;
            }

            // The space after the marker is what keeps a line that merely starts
            // with an emphasis marker (`*note*`) out of the list.
            if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
                $flushParagraph();
                $flushQuote();

                if ($list === null || $list['type'] !== 'ul') {
                    $flushList();
                    $list = ['type' => 'ul', 'items' => []];
                }

                $list['items'][] = $m[1];

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $m)) {
                $flushParagraph();
                $flushQuote();

                if ($list === null || $list['type'] !== 'ol') {
                    $flushList();
                    $list = ['type' => 'ol', 'items' => []];
                }

                $list['items'][] = $m[1];

                continue;
            }

            $flushList();
            $flushQuote();
            $paragraph[] = $line;
        }

        $flushAll();

        return implode("\n", $html);
    }

    /**
     * The same source as plain text, for meta descriptions and anything else
     * that must not carry markup.
     *
     * Derived from toHtml() rather than parsed a second time, so the two can
     * never disagree about what the copy says.
     */
    public static function toPlainText(?string $text): string
    {
        $html = static::toHtml($text);

        // Block boundaries have to become spaces first, otherwise "one" and
        // "two" in adjacent <p> tags come out of strip_tags() glued together.
        $html = preg_replace('#<(?:br|/?p|/?li|/?h[1-6]|/?blockquote)\s*/?>#i', ' ', $html) ?? $html;

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    }

    private static function normalize(?string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $text);
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Generated markup is parked behind a sentinel while the remaining
        // transforms run, so an emphasis marker inside a URL or a backtick span
        // can never be read as markup. NUL bytes are stripped from the input
        // first, so the text cannot forge a placeholder of its own.
        $parked = [];
        $park = function (string $html) use (&$parked): string {
            $parked[] = $html;

            return "\0".(count($parked) - 1)."\0";
        };

        $escaped = str_replace("\0", '', $escaped);

        // Emphasis is a reusable pass rather than a straight block of
        // replacements, so a link label can be emphasised too (`[**bold**](url)`)
        // instead of parking the label unformatted.
        $emphasis = function (string $subject) use ($park): string {
            $strong = fn (array $m): string => $park('<strong>'.$m[1].'</strong>');
            $em = fn (array $m): string => $park('<em>'.$m[1].'</em>');

            // Strong before emphasis, or `**bold**` is read as two italics.
            $subject = preg_replace_callback('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', $strong, $subject) ?? $subject;
            $subject = preg_replace_callback('/__(?=\S)(.+?)(?<=\S)__/s', $strong, $subject) ?? $subject;
            $subject = preg_replace_callback('/\*(?=\S)(.+?)(?<=\S)\*/s', $em, $subject) ?? $subject;

            // Underscores only count between word boundaries, so identifiers
            // like `snake_case_name` survive intact.
            return preg_replace_callback(
                '/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/su',
                $em,
                $subject
            ) ?? $subject;
        };

        // Code spans first, so a `*` inside backticks stays literal.
        $escaped = preg_replace_callback(
            '/`([^`]+)`/',
            fn (array $m): string => $park('<code>'.$m[1].'</code>'),
            $escaped
        ) ?? $escaped;

        // The negative lookbehind leaves `![alt](file)` as written: the subset
        // has no images, so it should read as text rather than become a link.
        $escaped = preg_replace_callback(
            '/(?<!!)\[([^\]]*)\]\(([^)\s]+)\)/',
            function (array $m) use ($park, $emphasis): string {
                $url = self::safeUrl($m[2]);

                if ($url === null) {
                    // Refused scheme: keep the sentence, drop only the link.
                    return $m[0];
                }

                $external = (bool) preg_match('#^(?:https?:)?//#i', self::decode($url));
                $attrs = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

                return $park('<a href="'.$url.'"'.$attrs.'>'.$emphasis($m[1]).'</a>');
            },
            $escaped
        ) ?? $escaped;

        $escaped = $emphasis($escaped);

        // A plain textarea gives no way to type a trailing double space, so any
        // newline in a block is a line break rather than a soft wrap.
        $escaped = str_replace("\n", '<br>', $escaped);

        // A parked fragment can hold an earlier placeholder — a link whose label
        // was emphasised parks the label first — and preg_replace_callback does
        // not rescan what it substitutes, so this resolves until it settles.
        for ($pass = 0; $pass <= count($parked); $pass++) {
            $restored = preg_replace_callback(
                '/\0(\d+)\0/',
                fn (array $m): string => $parked[(int) $m[1]] ?? '',
                $escaped
            );

            if ($restored === null || $restored === $escaped) {
                break;
            }

            $escaped = $restored;
        }

        return $escaped;
    }

    /**
     * The URL is already HTML-escaped, so it is safe to place in an attribute
     * as-is. This only decides whether it is safe to link to at all.
     */
    private static function safeUrl(string $escaped): ?string
    {
        $url = trim(self::decode($escaped));

        if ($url === '') {
            return null;
        }

        if (preg_match('#^(https?|mailto|tel):#i', $url)) {
            return $escaped;
        }

        // Protocol-relative (`//host`) inherits the page scheme and is not
        // covered by the allow-list above, so it is refused too.
        if (str_starts_with($url, '//')) {
            return null;
        }

        // Any other scheme — javascript:, data:, vbscript: — is refused.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            return null;
        }

        return $escaped;
    }

    private static function decode(string $escaped): string
    {
        return html_entity_decode($escaped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
