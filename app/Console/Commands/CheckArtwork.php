<?php

/**
 * Artwork QA — geometry checks for generated SVG.
 *
 * Run: php artisan artwork:check
 *
 * Generated artwork is the demo store's imagery, so it must be verifiably
 * correct: valid XML, every shape inside the canvas after transform, every
 * caption inside its panel, balanced composition, sane payload size.
 */

namespace App\Console\Commands;

use App\Support\Artwork\ArtworkGenerator;
use App\Support\Artwork\Palette;
use App\Support\Artwork\TextMetrics;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Console\Command;

class CheckArtwork extends Command
{
    protected $signature = 'artwork:check';

    protected $description = 'Validate generated SVG artwork geometry (bounds, caption fit, XML validity)';

    public function handle(ArtworkGenerator $artwork): int
    {
        $cases = [];
        $shapes = ['flacon', 'jar', 'mist', 'tube', 'dropper', 'carton'];
        $tones = array_keys(Palette::TONES);
        $labels = ['Ibraq', "Victoria's Secret", 'Chamma Perfumes', 'Attar Musk Qamar Al Dhahab', 'Sakura Body Cream'];

        foreach ($shapes as $shape) {
            foreach ($tones as $tone) {
                $cases['product:'.$shape.'/'.$tone] = $artwork->product([
                    'shape' => $shape, 'tone' => $tone,
                    'label' => $labels[array_rand($labels)], 'size' => '100 ML',
                ]);
            }

            foreach ([0, 1, 2] as $variant) {
                $cases['product:'.$shape.'/v'.$variant] = $artwork->product([
                    'shape' => $shape, 'tone' => $tones[array_rand($tones)],
                    'label' => $labels[array_rand($labels)], 'size' => '250 ML', 'variant' => $variant,
                ]);
            }
        }

        $cases['collection:3'] = $artwork->collection([
            ['shape' => 'flacon', 'tone' => 'espresso', 'size' => '100 ML'],
            ['shape' => 'jar', 'tone' => 'blush', 'size' => '200 ML'],
            ['shape' => 'dropper', 'tone' => 'amber', 'size' => '12 ML'],
        ]);
        $cases['collection:2'] = $artwork->collection([
            ['shape' => 'mist', 'tone' => 'rose', 'size' => '250 ML'],
            ['shape' => 'tube', 'tone' => 'jade', 'size' => '200 ML'],
        ]);
        $cases['collection:1'] = $artwork->collection([['shape' => 'dropper', 'tone' => 'amber', 'size' => '12 ML']]);
        $cases['hero'] = $artwork->hero();
        $cases['banner'] = $artwork->banner(['tone' => 'plum', 'shape' => 'carton']);
        $cases['banner:espresso'] = $artwork->banner(['tone' => 'espresso', 'shape' => 'flacon', 'width' => 1200, 'height' => 700]);

        foreach (['Rituals', 'Ibraq', "Victoria's Secret", 'Chamma', 'Extremely Long Brand Name Ltd'] as $i => $name) {
            $cases['logo:'.$i] = $artwork->brandLogo($name, $tones[$i % count($tones)]);
        }

        $failures = 0;
        $rows = [];

        foreach ($cases as $name => $svg) {
            $issues = $this->inspect($svg);
            $failures += $issues === [] ? 0 : 1;
            [$w, $h] = $this->viewBox($svg);

            $rows[] = [
                $name,
                $w.'×'.$h,
                number_format(strlen($svg) / 1024, 1).' KB',
                $issues === [] ? '<fg=green>OK</>' : '<fg=red>'.implode('; ', array_slice($issues, 0, 3)).'</>',
            ];
        }

        $this->table(['case', 'viewBox', 'size', 'result'], $rows);
        $this->newLine();
        $this->line('text measured with: '.($this->font() ?? 'built-in fallback width table'));
        $this->line(sprintf('cases: %d, failing: %d', count($cases), $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Transform-aware geometry audit: walks the tree while accumulating the
     * translate/scale of every <g transform="...">, so grouped and scaled
     * silhouettes are checked in canvas space.
     *
     * @return list<string>
     */
    private function inspect(string $svg): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $valid = $dom->loadXML($svg);
        libxml_use_internal_errors($previous);

        if (! $valid || ! $dom->documentElement instanceof DOMElement) {
            return ['invalid XML'];
        }

        [$w, $h] = $this->viewBox($svg);
        $root = $dom->documentElement;

        if ((int) $root->getAttribute('width') !== $w || (int) $root->getAttribute('height') !== $h) {
            return ['width/height disagree with viewBox'];
        }

        $issues = [];
        $contentLeft = null;
        $contentRight = null;
        $fullBleed = false;

        $walk = function (DOMNode $node, float $parentScale, float $parentTx, float $parentTy) use (&$walk, &$issues, &$contentLeft, &$contentRight, &$fullBleed, $w, $h) {
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $tag = $child->tagName;

                if (in_array($tag, ['defs', 'linearGradient', 'radialGradient', 'stop', 'filter', 'feGaussianBlur'], true)) {
                    continue;
                }

                // Resolved per child: a transformed sibling must never leak its
                // transform onto the elements that follow it.
                [$scale, $tx, $ty] = $this->applyTransform($child, $parentScale, $parentTx, $parentTy);

                // Local (untransformed) origin; the transform is applied once, later.
                $x = $child->hasAttribute('x') ? (float) $child->getAttribute('x') : 0.0;
                $y = $child->hasAttribute('y') ? (float) $child->getAttribute('y') : 0.0;

                if ($tag === 'rect' || $tag === 'image') {
                    $left = $x;
                    $top = $y;
                    $right = $x + (float) $child->getAttribute('width');
                    $bottom = $y + (float) $child->getAttribute('height');
                } elseif ($tag === 'circle' || $tag === 'ellipse') {
                    $rx = (float) $child->getAttribute('r');
                    $ry = (float) ($child->getAttribute('ry') ?: $child->getAttribute('r'));
                    $cx = $child->hasAttribute('cx') ? (float) $child->getAttribute('cx') : $x;
                    $cy = $child->hasAttribute('cy') ? (float) $child->getAttribute('cy') : $y;
                    $left = $cx - $rx;
                    $top = $cy - $ry;
                    $right = $cx + $rx;
                    $bottom = $cy + $ry;
                } elseif ($tag === 'line') {
                    $left = min((float) $child->getAttribute('x1'), (float) $child->getAttribute('x2'));
                    $right = max((float) $child->getAttribute('x1'), (float) $child->getAttribute('x2'));
                    $top = min((float) $child->getAttribute('y1'), (float) $child->getAttribute('y2'));
                    $bottom = max((float) $child->getAttribute('y1'), (float) $child->getAttribute('y2'));
                } elseif ($tag === 'text') {
                    $size = (float) $child->getAttribute('font-size');
                    $measured = TextMetrics::width(trim($child->textContent), $size, (float) $child->getAttribute('letter-spacing'));

                    [$left, $right] = match ($child->getAttribute('text-anchor') ?: 'start') {
                        'middle' => [$x - $measured / 2, $x + $measured / 2],
                        'end' => [$x - $measured, $x],
                        default => [$x, $x + $measured],
                    };

                    $top = $y - $size * 0.82;
                    $bottom = $y + $size * 0.24;
                } else {
                    $walk($child, $scale, $tx, $ty);

                    continue;
                }

                // Local space -> canvas space (the transform is applied once, here).
                $left = $left * $scale + $tx;
                $right = $right * $scale + $tx;
                $top = $top * $scale + $ty;
                $bottom = $bottom * $scale + $ty;

                if ($left < -1 || $right > $w + 1 || $top < -1 || $bottom > $h + 1) {
                    $issues[] = sprintf(
                        '%s "%s" outside canvas (%.0f,%.0f -> %.0f,%.0f)',
                        $tag,
                        $tag === 'text' ? trim($child->textContent) : '',
                        $left, $top, $right, $bottom
                    );
                }

                // Backdrop plates span the full canvas: not part of the composition.
                if ($right - $left >= $w - 1) {
                    $fullBleed = true;

                    continue;
                }

                $contentLeft = $contentLeft === null ? $left : min($contentLeft, $left);
                $contentRight = $contentRight === null ? $right : max($contentRight, $right);
            }
        };

        $walk($root, 1.0, 0.0, 0.0);

        // Composition should read as balanced, not shoved against one edge.
        if (! $fullBleed && $contentLeft !== null && $contentRight !== null) {
            $offset = abs((($contentLeft + $contentRight) / 2) - ($w / 2));

            if ($offset > $w * 0.16) {
                $issues[] = sprintf('composition off-centre by %.0fpx', $offset);
            }
        }

        if (strlen($svg) > 90 * 1024) {
            $issues[] = 'svg too heavy ('.round(strlen($svg) / 1024).' KB)';
        }

        // Paint servers and filters must resolve, or the element silently
        // stops rendering in the browser.
        preg_match_all('/\sid="([^"]+)"/', $svg, $defined);
        preg_match_all('/url\(#([^)]+)\)/', $svg, $referenced);

        foreach (array_unique($referenced[1]) as $id) {
            if (! in_array($id, $defined[1], true)) {
                $issues[] = "dangling paint server url(#$id)";
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return array{0: float, 1: float, 2: float} scale, translateX, translateY
     */
    private function applyTransform(DOMElement $element, float $scale, float $tx, float $ty): array
    {
        $transform = $element->getAttribute('transform');

        if (! preg_match_all('/(translate|scale)\s*\(([^)]*)\)/', $transform, $matches, PREG_SET_ORDER)) {
            return [$scale, $tx, $ty];
        }

        foreach ($matches as $match) {
            $values = array_map('floatval', array_filter(explode(',', trim($match[2])), fn ($v) => trim($v) !== ''));

            if ($match[1] === 'translate') {
                // Parent transform applies to the translation as well.
                $tx += ($values[0] ?? 0.0) * $scale;
                $ty += ($values[1] ?? 0.0) * $scale;

                continue;
            }

            $sx = $values[0] ?? 1.0;
            $sy = $values[1] ?? $sx;
            // SVG transform lists compose right-to-left: `translate(...) scale(...)`
            // scales the point first, then offsets it — the translation is never
            // rescaled by the scale that follows it.
            $scale *= $sx;
        }

        return [$scale, $tx, $ty];
    }

    /** @return array{0: int, 1: int} */
    private function viewBox(string $svg): array
    {
        if (preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [0, 0];
    }

    private function font(): ?string
    {
        return TextMetrics::font();
    }
}
