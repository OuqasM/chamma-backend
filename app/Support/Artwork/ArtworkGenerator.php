<?php

namespace App\Support\Artwork;

use App\Support\Artwork\Palette;

/**
 * Procedural product artwork.
 *
 * The demo shop must look like a real boutique on first boot, without shipping
 * binary photography. Every image is a generated, resolution-independent SVG:
 * a studio backdrop, a soft contact shadow, a glass flacon with champagne-gold
 * detailing and a typographic label. Artwork is intentionally language neutral
 * (brand wordmark + volume only) so the same image is correct in FR, AR and EN.
 */
final class ArtworkGenerator
{
    /** Silhouette art bounds inside the canonical 1000×1250 box. */
    private const SHAPES = [
        'flacon' => [330, 1076],
        'jar' => [636, 1076],
        'mist' => [470, 1076],
        'tube' => [520, 1124],
        'dropper' => [452, 1078],
        'carton' => [520, 1080],
    ];

    private const SERIF = "'Cormorant Garamond','Playfair Display',Georgia,'Times New Roman',serif";

    private const SANS = "'Jost','Futura','Helvetica Neue',Helvetica,Arial,sans-serif";

    /**
     * Single product image, portrait 4:5.
     *
     * @param  array{label?:string,size?:string,shape?:string,tone?:string,variant?:int}  $options
     */
    public function product(array $options = []): string
    {
        $shape = $options['shape'] ?? 'flacon';
        $shape = isset(self::SHAPES[$shape]) ? $shape : 'flacon';
        $tone = $options['tone'] ?? 'ivory';
        $variant = (int) ($options['variant'] ?? 0);

        [$w, $h] = [1000, 1250];
        $floor = 1076;
        $id = $this->uid($shape.$tone.$variant);

        // Alternate compositions so a gallery never looks repetitive.
        [$targetHeight, $cx] = match ($variant % 3) {
            0 => [746, 500.0],
            1 => [690, 520.0],
            default => [790, 480.0],
        };

        $body = $this->shape($shape, $id, $tone, $options);

        [$top, $bottom] = self::SHAPES[$shape];
        $scale = $targetHeight / ($bottom - $top);
        $tx = $cx - 500 * $scale;
        $ty = $floor - $bottom * $scale;

        $art = $variant % 3 === 1
            ? sprintf('<g transform="translate(%.1f,%.1f) scale(%.3f)">%s</g>', $tx, $ty, $scale, $body)
            : $body;

        $label = trim((string) ($options['label'] ?? ''));
        $size = trim((string) ($options['size'] ?? ''));

        return $this->svg($w, $h, sprintf(
            '%s%s%s%s%s',
            $this->defs($id, $tone),
            $this->backdrop($id, $tone, $w, $h, $floor, $variant),
            $this->contactShadow($id, $cx, $floor, 210 * $scale),
            $art,
            $this->plate($id, $label, $size, $h - 62)
        ));
    }

    /**
     * Category / collection card, landscape 4:3 — a family of three silhouettes.
     *
     * @param  array<int, array{shape?:string,tone?:string,size?:string}>  $items
     */
    public function collection(array $items = []): string
    {
        [$w, $h] = [1200, 900];
        $floor = 792;
        $id = $this->uid('collection'.count($items).($items[0]['tone'] ?? 'ivory'));

        $items = array_slice($items, 0, 3) ?: [['shape' => 'flacon', 'tone' => 'ivory']];

        // Layouts keyed by how many silhouettes are on the card.
        $layouts = [
            1 => [
                ['cx' => 600, 'scale' => 0.86, 'opacity' => 1.0],
            ],
            2 => [
                ['cx' => 350, 'scale' => 0.72, 'opacity' => 1.0],
                ['cx' => 760, 'scale' => 0.7, 'opacity' => 1.0],
            ],
            3 => [
                ['cx' => 300, 'scale' => 0.62, 'opacity' => 0.95],
                ['cx' => 600, 'scale' => 0.86, 'opacity' => 1.0],
                ['cx' => 900, 'scale' => 0.62, 'opacity' => 0.95],
            ],
        ];

        $layout = $layouts[count($items)] ?? $layouts[1];
        $placed = [];

        foreach ($items as $index => $item) {
            $shape = $item['shape'] ?? 'flacon';
            $shape = isset(self::SHAPES[$shape]) ? $shape : 'flacon';

            $placed[] = [
                'shape' => $shape,
                'tone' => $item['tone'] ?? ['ivory', 'blush', 'amber'][$index % 3],
                'size' => $item['size'] ?? '',
                'label' => $item['label'] ?? '',
                'cx' => $layout[$index]['cx'],
                'scale' => $layout[$index]['scale'],
                'opacity' => $layout[$index]['opacity'],
            ];
        }

        [$defs, $art] = $this->compose($placed, $floor);

        return $this->svg($w, $h, sprintf(
            '%s%s%s%s%s',
            $this->defs($id, 'ivory'),
            $defs,
            $this->backdrop($id, 'ivory', $w, $h, $floor, 0, true),
            $art,
            '<text x="600" y="862" text-anchor="middle" font-family="'.self::SANS.'" font-size="19" letter-spacing="9" fill="#8A7A64">CHAMMA PERFUMES</text>'
        ));
    }

    /**
     * Hero campaign image, square, designed to sit inside an arched frame.
     */
    public function hero(array $options = []): string
    {
        $size = (int) ($options['size'] ?? 1400);
        $tone = $options['tone'] ?? 'espresso';
        $id = $this->uid('hero'.$size);
        $centre = $size / 2;
        $floor = $size * 0.855;
        $scale = ($size * 0.62) / (self::SHAPES['flacon'][1] - self::SHAPES['flacon'][0]);

        [$defs, $art] = $this->compose([[
            'shape' => 'flacon',
            'tone' => $tone,
            'size' => $options['size_label'] ?? 'EAU DE PARFUM',
            'label' => $options['label'] ?? 'CHAMMA',
            'cx' => $centre,
            'scale' => $scale,
        ]], $floor);

        $arcs = '';
        foreach ([0.44, 0.36, 0.28] as $i => $ratio) {
            $arcs .= sprintf(
                '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="1.4" opacity="%.2f"/>',
                $centre,
                $centre,
                $size * $ratio,
                Palette::GOLD[2],
                0.5 - $i * 0.13
            );
        }

        return $this->svg($size, $size, sprintf(
            '%s%s%s%s%s',
            $this->defs($id, $tone),
            $defs,
            $this->backdrop($id, $tone, $size, $size, $floor, 0, false, false),
            $arcs,
            $art
        ));
    }

    /**
     * Places silhouettes on a shared floor line and returns both the art and
     * the gradient definitions those silhouettes reference — a scene without
     * its defs renders as nothing at all in the browser.
     *
     * @param  list<array<string, mixed>>  $placed
     * @return array{0: string, 1: string}
     */
    private function compose(array $placed, float $floor): array
    {
        $defs = [];
        $art = '';

        foreach ($placed as $item) {
            $shape = $item['shape'];
            $tone = $item['tone'];
            $id = $this->uid($shape.$tone);
            $scale = (float) $item['scale'];
            [$top, $bottom] = self::SHAPES[$shape];

            $defs[$id] ??= $this->defs($id, $tone);

            $art .= sprintf(
                '<g opacity="%.2f" transform="translate(%.1f,%.1f) scale(%.3f)">%s</g>',
                (float) ($item['opacity'] ?? 1),
                (float) $item['cx'] - 500 * $scale,
                $floor - $bottom * $scale,
                $scale,
                $this->shape($shape, $id, $tone, $item)
            );
        }

        return [implode('', $defs), $art];
    }

    /**
     * Wide editorial strip (newsletter band, brand page hero).
     */
    public function banner(array $options = []): string
    {
        [$w, $h] = [(int) ($options['width'] ?? 1600), (int) ($options['height'] ?? 900)];
        $tone = $options['tone'] ?? 'plum';
        $id = $this->uid('banner'.$w.$h.$tone);
        $floor = $h * 0.94;
        $shape = $options['shape'] ?? 'carton';
        $shape = isset(self::SHAPES[$shape]) ? $shape : 'carton';
        $scale = ($h * 0.72) / (self::SHAPES[$shape][1] - self::SHAPES[$shape][0]);

        [$defs, $art] = $this->compose([[
            'shape' => $shape,
            'tone' => $tone,
            'size' => $options['size_label'] ?? '',
            'label' => $options['label'] ?? '',
            'cx' => $w * 0.5,
            'scale' => $scale,
        ]], $floor);

        return $this->svg($w, $h, sprintf(
            '%s%s%s%s',
            $this->defs($id, $tone),
            $defs,
            $this->backdrop($id, $tone, $w, $h, $floor, 0, true),
            $art
        ));
    }

    /**
     * Brand wordmark on a transparent canvas.
     */
    public function brandLogo(string $name, string $tone = 'ivory'): string
    {
        $p = Palette::tone($tone);
        $id = $this->uid('logo'.preg_replace('/[^a-z0-9]+/i', '', $name));
        $w = 640;
        $h = 200;
        $length = mb_strlen($name);
        $fontSize = $length <= 6 ? 66 : ($length <= 12 ? 46 : 32);
        $tracking = $length <= 6 ? 14 : ($length <= 12 ? 8 : 5);
        $fit = TextMetrics::fit($name, $w - 80, (float) $fontSize, (float) $tracking, 16.0);

        return $this->svg($w, $h, sprintf(
            '<defs><linearGradient id="%sg" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient></defs>'
            .'<path d="M320 34 l9 9 -9 9 -9 -9 z" fill="%s"/>'
            .'<line x1="252" y1="46" x2="292" y2="46" stroke="%s" stroke-width="1.2"/>'
            .'<line x1="348" y1="46" x2="388" y2="46" stroke="%s" stroke-width="1.2"/>'
            .'<text x="320" y="128" text-anchor="middle" font-family="%s" font-size="%.1f" letter-spacing="%.1f" fill="url(#%sg)">%s</text>'
            .'<line x1="272" y1="152" x2="368" y2="152" stroke="%s" stroke-width="1"/>',
            $id,
            Palette::GOLD[0], Palette::GOLD[3],
            $p['accent'],
            $p['accent'],
            $p['accent'],
            self::SERIF,
            $fit['size'],
            $fit['tracking'],
            $id,
            $this->escape($name),
            $p['accent']
        ));
    }


    /* ------------------------------------------------------------------ */
    /* Scene layers                                                        */
    /* ------------------------------------------------------------------ */

    /** @param array<string, string> $p */
    private function defs(string $id, string $tone): string
    {
        $p = Palette::tone($tone);

        $stops = static fn (array $colors): string => implode('', array_map(
            static fn ($color, $i): string => sprintf('<stop offset="%.2f" stop-color="%s"/>', $i / max(1, count($colors) - 1), $color),
            $colors,
            array_keys($colors)
        ));

        return <<<SVG
        <defs>
          <linearGradient id="{$id}bg" x1="0" y1="0" x2="0.35" y2="1">
            <stop offset="0" stop-color="{$p['bg_top']}"/>
            <stop offset="1" stop-color="{$p['bg_bottom']}"/>
          </linearGradient>
          <radialGradient id="{$id}glow" cx="0.5" cy="0.32" r="0.62">
            <stop offset="0" stop-color="{$p['glow']}" stop-opacity="0.95"/>
            <stop offset="1" stop-color="{$p['glow']}" stop-opacity="0"/>
          </radialGradient>
          <linearGradient id="{$id}glass" x1="0" y1="0" x2="1" y2="0.2">
            <stop offset="0" stop-color="{$p['body_light']}"/>
            <stop offset="0.45" stop-color="{$p['body_mid']}"/>
            <stop offset="1" stop-color="{$p['body_dark']}"/>
          </linearGradient>
          <linearGradient id="{$id}floor" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="{$p['bg_bottom']}" stop-opacity="0"/>
            <stop offset="1" stop-color="{$p['bg_bottom']}" stop-opacity="0.85"/>
          </linearGradient>
          <linearGradient id="{$id}gold" x1="0" y1="0" x2="1" y2="1">
            {$stops(Palette::GOLD)}
          </linearGradient>
          <linearGradient id="{$id}spec" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#ffffff" stop-opacity="0"/>
            <stop offset="0.5" stop-color="#ffffff" stop-opacity="0.55"/>
            <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
          </linearGradient>
          <radialGradient id="{$id}shadow" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="{$p['body_dark']}" stop-opacity="0.42"/>
            <stop offset="1" stop-color="{$p['body_dark']}" stop-opacity="0"/>
          </radialGradient>
          <filter id="{$id}blur" x="-30%" y="-60%" width="160%" height="220%">
            <feGaussianBlur stdDeviation="18"/>
          </filter>
        </defs>
        SVG;
    }

    private function backdrop(string $id, string $tone, int $w, int $h, int $floor, int $variant = 0, bool $horizon = false, bool $decor = true): string
    {
        $p = Palette::tone($tone);

        $ring = '';
        if ($decor && $variant % 2 === 0) {
            $ring = sprintf(
                '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="1.3" opacity="0.22"/>'
                .'<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="1" opacity="0.14"/>',
                $w * 0.76, $h * 0.2, $this->maxRadius($w * 0.76, $h * 0.2, $w, $h) * 0.9, $p['accent'],
                $w * 0.22, $h * 0.13, $this->maxRadius($w * 0.22, $h * 0.13, $w, $h) * 0.8, $p['accent']
            );
        }

        $horizonY = (int) ($horizon ? $floor * 0.82 : $floor - 90);
        $line = $horizon
            ? sprintf(
                '<line x1="0" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1" opacity="0.18"/>',
                $horizonY, $w, $horizonY, $p['accent']
            )
            : '';

        return sprintf(
            '<rect x="0" y="0" width="%1$d" height="%2$d" fill="url(#%3$sbg)"/>'
            .'<rect x="0" y="0" width="%1$d" height="%2$d" fill="url(#%3$sglow)"/>'
            .'%4$s%5$s'
            .'<rect x="0" y="%6$d" width="%1$d" height="%7$d" fill="url(#%3$sfloor)"/>',
            $w,
            $h,
            $id,
            $ring,
            $line,
            $horizonY,
            max(0, $h - $horizonY)
        );
    }

    /**
     * Largest radius that keeps a circle centred at ($cx, $cy) fully inside the
     * canvas — decorative rings must never bleed past the frame.
     */
    private function maxRadius(float $cx, float $cy, int $w, int $h): float
    {
        return max(24.0, min($cx, $w - $cx, $cy, $h - $cy));
    }

    private function contactShadow(string $id, float $cx, float $cy, float $rx): string
    {
        return sprintf(
            '<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%.1f" fill="url(#%sshadow)"/>',
            $cx,
            $cy + 8,
            $rx,
            max(18.0, $rx * 0.13),
            $id
        );
    }

    /** Small typographic plate under the product: brand wordmark + volume. */
    private function plate(string $id, string $label, string $size, int $y, float $canvasWidth = 1000.0): string
    {
        if ($label === '' && $size === '') {
            return '';
        }

        $cx = $canvasWidth / 2;
        $out = '';

        if ($label !== '') {
            $fit = TextMetrics::fit($this->upper($label), $canvasWidth * 0.52, 30, 10.0, 12.0);
            $out .= sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-family="%s" font-size="%.1f" letter-spacing="%.1f" fill="#6B5B49">%s</text>',
                $cx,
                $y,
                self::SANS,
                $fit['size'],
                $fit['tracking'],
                $this->escape($this->upper($label))
            );
        }

        if ($size !== '') {
            $fit = TextMetrics::fit($this->upper($size), $canvasWidth * 0.46, 21, 7.0, 9.0);
            $out .= sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-family="%s" font-size="%.1f" letter-spacing="%.1f" fill="#9A8974">%s</text>',
                $cx,
                $y + 34,
                self::SANS,
                $fit['size'],
                $fit['tracking'],
                $this->escape($this->upper($size))
            );
        }

        $out .= sprintf(
            '<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#C8A96A" stroke-width="1.2" opacity="0.75"/>',
            $cx - 60, $y - 34, $cx + 60, $y - 34
        );

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Silhouettes                                                         */
    /* ------------------------------------------------------------------ */

    private function shape(string $shape, string $id, string $tone, array $options): string
    {
        $p = Palette::tone($tone);
        $label = trim((string) ($options['label'] ?? ''));
        $size = trim((string) ($options['size'] ?? ''));

        return match ($shape) {
            'jar' => $this->jar($id, $p, $label, $size),
            'mist' => $this->mist($id, $p, $label, $size),
            'tube' => $this->tube($id, $p, $label, $size),
            'dropper' => $this->dropper($id, $p, $label, $size),
            'carton' => $this->carton($id, $p, $label, $size),
            default => $this->flacon($id, $p, $label, $size),
        };
    }

    /** @param array<string, string> $p */
    private function flacon(string $id, array $p, string $label, string $size): string
    {
        return <<<SVG
        <g>
          <rect x="456" y="516" width="88" height="76" rx="6" fill="{$p['body_dark']}" opacity="0.9"/>
          <path d="M352 646c0-24 20-43 47-51l54-19v-46h96v46l54 19c27 8 47 27 47 51v392c0 26-19 42-45 42H397c-26 0-45-16-45-42z" fill="url(#{$id}glass)"/>
          <path d="M352 646c0-24 20-43 47-51l54-19v-46h96v46l54 19c27 8 47 27 47 51v392c0 26-19 42-45 42H397c-26 0-45-16-45-42z" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.45"/>
          <rect x="382" y="628" width="46" height="404" fill="url(#{$id}spec)" opacity="0.5"/>
          <path d="M600 620c14 4 24 12 24 26v388c0 18-9 28-22 32z" fill="#ffffff" opacity="0.16"/>
          {$this->label($id, 392, 758, 216, 196, $label, $size, $p)}
          <g>
            <rect x="428" y="326" width="144" height="200" rx="12" fill="url(#{$id}gold)"/>
            <rect x="428" y="326" width="144" height="200" rx="12" fill="none" stroke="#8E6E3C" stroke-width="1" opacity="0.5"/>
            {$this->ribs(444, 342, 16, 168, '#8E6E3C', 0.22)}
            <rect x="428" y="326" width="42" height="200" fill="#ffffff" opacity="0.2"/>
          </g>
        </g>
        SVG;
    }

    /** @param array<string, string> $p */
    private function jar(string $id, array $p, string $label, string $size): string
    {
        return <<<SVG
        <g>
          <rect x="306" y="632" width="388" height="104" rx="28" fill="url(#{$id}gold)"/>
          <rect x="306" y="632" width="388" height="104" rx="28" fill="none" stroke="#8E6E3C" stroke-width="1" opacity="0.45"/>
          <rect x="306" y="632" width="120" height="104" fill="#ffffff" opacity="0.2"/>
          <path d="M330 736h340a22 22 0 0 1 22 22v300a26 26 0 0 1-26 26H334a26 26 0 0 1-26-26V758a22 22 0 0 1 22-22z" fill="url(#{$id}glass)"/>
          <path d="M330 736h340a22 22 0 0 1 22 22v300a26 26 0 0 1-26 26H334a26 26 0 0 1-26-26V758a22 22 0 0 1 22-22z" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.4"/>
          <rect x="352" y="772" width="54" height="268" fill="url(#{$id}spec)" opacity="0.45"/>
          {$this->label($id, 350, 806, 300, 176, $label, $size, $p)}
        </g>
        SVG;
    }

    /** @param array<string, string> $p */
    private function mist(string $id, array $p, string $label, string $size): string
    {
        $drops = '';
        foreach ([[650, 500, 3.4], [676, 486, 2.6], [700, 500, 2], [664, 462, 1.6], [694, 466, 1.2]] as [$cx, $cy, $r]) {
            $drops .= sprintf('<circle cx="%d" cy="%d" r="%.1f" fill="#ffffff" opacity="0.5"/>', $cx, $cy, $r);
        }

        return <<<SVG
        <g>
          <rect x="452" y="590" width="96" height="54" rx="10" fill="{$p['body_dark']}" opacity="0.9"/>
          <rect x="426" y="466" width="148" height="134" rx="14" fill="url(#{$id}gold)"/>
          <rect x="426" y="466" width="148" height="134" rx="14" fill="none" stroke="#8E6E3C" stroke-width="1" opacity="0.45"/>
          <rect x="426" y="466" width="46" height="134" fill="#ffffff" opacity="0.2"/>
          <rect x="566" y="498" width="66" height="34" rx="9" fill="#2E2A26"/>
          {$drops}
          <path d="M404 644h192a20 20 0 0 1 20 20v390a26 26 0 0 1-26 26H410a26 26 0 0 1-26-26V664a20 20 0 0 1 20-20z" fill="url(#{$id}glass)"/>
          <path d="M404 644h192a20 20 0 0 1 20 20v390a26 26 0 0 1-26 26H410a26 26 0 0 1-26-26V664a20 20 0 0 1 20-20z" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.42"/>
          <rect x="424" y="676" width="46" height="344" fill="url(#{$id}spec)" opacity="0.5"/>
          {$this->label($id, 424, 800, 152, 156, $label, $size, $p)}
        </g>
        SVG;
    }

    /** @param array<string, string> $p */
    private function tube(string $id, array $p, string $label, string $size): string
    {
        return <<<SVG
        <g>
          <path d="M384 528h232a16 16 0 0 1 16 16v478h-40v34H408v-34h-40V544a16 16 0 0 1 16-16z" fill="url(#{$id}glass)"/>
          <path d="M384 528h232a16 16 0 0 1 16 16v478h-40v34H408v-34h-40V544a16 16 0 0 1 16-16z" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.42"/>
          <rect x="404" y="1046" width="192" height="80" rx="16" fill="url(#{$id}gold)"/>
          <rect x="404" y="1046" width="192" height="80" rx="16" fill="none" stroke="#8E6E3C" stroke-width="1" opacity="0.45"/>
          <rect x="404" y="1046" width="58" height="80" fill="#ffffff" opacity="0.2"/>
          <rect x="414" y="576" width="44" height="404" fill="url(#{$id}spec)" opacity="0.45"/>
          {$this->label($id, 406, 676, 188, 208, $label, $size, $p)}
        </g>
        SVG;
    }

    /** @param array<string, string> $p */
    private function dropper(string $id, array $p, string $label, string $size): string
    {
        return <<<SVG
        <g>
          <rect x="446" y="448" width="108" height="212" rx="10" fill="url(#{$id}gold)"/>
          <rect x="446" y="448" width="108" height="212" rx="10" fill="none" stroke="#8E6E3C" stroke-width="1" opacity="0.45"/>
          {$this->ribs(462, 464, 15, 180, '#8E6E3C', 0.2)}
          <rect x="446" y="448" width="34" height="212" fill="#ffffff" opacity="0.2"/>
          <rect x="440" y="654" width="120" height="38" rx="8" fill="{$p['body_dark']}" opacity="0.9"/>
          <path d="M430 722c0-20 14-34 34-34h72c20 0 34 14 34 34v330c0 16-12 26-28 26H458c-16 0-28-10-28-26z" fill="url(#{$id}glass)"/>
          <path d="M430 722c0-20 14-34 34-34h72c20 0 34 14 34 34v330c0 16-12 26-28 26H458c-16 0-28-10-28-26z" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.45"/>
          <rect x="450" y="756" width="30" height="266" fill="url(#{$id}spec)" opacity="0.5"/>
          {$this->label($id, 444, 828, 112, 128, $label, $size, $p)}
        </g>
        SVG;
    }

    /** @param array<string, string> $p */
    private function carton(string $id, array $p, string $label, string $size): string
    {
        return <<<SVG
        <g>
          <path d="M688 522l118 46v472l-118 44z" fill="{$p['body_dark']}" opacity="0.92"/>
          <rect x="290" y="522" width="398" height="562" rx="8" fill="url(#{$id}glass)"/>
          <rect x="290" y="522" width="398" height="562" rx="8" fill="none" stroke="{$p['body_dark']}" stroke-width="1.6" opacity="0.4"/>
          <rect x="290" y="522" width="398" height="562" rx="8" fill="none" stroke="#ffffff" stroke-width="1" opacity="0.25"/>
          <rect x="290" y="522" width="120" height="562" fill="#ffffff" opacity="0.14"/>
          <rect x="452" y="522" width="74" height="562" fill="url(#{$id}gold)" opacity="0.95"/>
          <path d="M452 522h74l-96-38h-74z" fill="#E7D3A6" opacity="0.9"/>
          <path d="M526 522h74l96-38h-74z" fill="#C7A97F" opacity="0.9"/>
          {$this->label($id, 330, 700, 318, 196, $label, $size, $p)}
        </g>
        SVG;
    }

    /**
     * Ivory label panel with a champagne hairline border.
     *
     * @param  array<string, string>  $p
     */
    private function label(string $id, float $x, float $y, float $w, float $h, string $label, string $size, array $p): string
    {
        $cx = $x + $w / 2;
        $out = sprintf(
            '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="8" fill="#FDFBF7" opacity="0.93"/>'
            .'<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="8" fill="none" stroke="%s" stroke-width="1.1" opacity="0.65"/>',
            $x, $y, $w, $h,
            $x + 8, $y + 8, $w - 16, $h - 16,
            $p['accent']
        );

        $word = $this->upper($label);
        if ($word !== '') {
            $fit = TextMetrics::fit($word, $w - 34, 27, 6.0, 8.0);
            $out .= sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" font-family="%s" font-size="%.1f" letter-spacing="%.1f" fill="%s">%s</text>',
                $cx,
                $y + $h * 0.44,
                self::SANS,
                $fit['size'],
                $fit['tracking'],
                $p['ink'],
                $this->escape($word)
            );
        }

        $out .= sprintf(
            '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1" opacity="0.8"/>',
            $cx - $w * 0.16, $y + $h * 0.56, $cx + $w * 0.16, $y + $h * 0.56,
            $p['accent']
        );

        if ($size !== '') {
            $out .= sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" font-family="%s" font-size="18" letter-spacing="4" fill="%s" opacity="0.78">%s</text>',
                $cx,
                $y + $h * 0.78,
                self::SANS,
                $p['ink'],
                $this->escape($this->upper($size))
            );
        }

        return $out;
    }

    private function ribs(int $x, int $y, int $step, int $height, string $color, float $opacity): string
    {
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1.6" opacity="%.2f"/>',
                $x + $i * $step,
                $y,
                $x + $i * $step,
                $y + $height,
                $color,
                $opacity
            );
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing                                                            */
    /* ------------------------------------------------------------------ */

    private function svg(int $w, int $h, string $inner): string
    {
        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img">',
                $w, $h, $w, $h
            ),
            $inner,
            '</svg>',
            '',
        ]);
    }

    private function uid(string $seed): string
    {
        return 'a'.substr(md5($seed), 0, 8);
    }

    private function upper(string $value): string
    {
        return mb_strtoupper($this->ascii($value));
    }

    private function ascii(string $value): string
    {
        return strtr($value, [
            '’' => "'", '‘' => "'", '–' => '-', '—' => '-',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
