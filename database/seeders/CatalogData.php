<?php

namespace Database\Seeders;

use Illuminate\Support\Str;

/**
 * Single source of truth for the demo catalogue.
 *
 * Every record is written once in French (the store's default locale) and
 * translated, so adding a language never means rewriting the catalogue.
 */
final class CatalogData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function brands(): array
    {
        return [
            [
                'key' => 'rituals',
                'name' => 'Rituals',
                'slug' => 'rituals',
                'origin' => 'Amsterdam, Netherlands',
                'position' => 1,
                'tone' => 'jade',
                'translations' => [
                    'fr' => [
                        'name' => 'Rituals',
                        'tagline' => 'Maison néerlandaise du rituel',
                        'description' => 'Des parfums et des soins inspirés des rituels du monde, dans des flacons réutilisables et des compositions sobres.',
                    ],
                    'ar' => [
                        'name' => 'ريشوالز',
                        'tagline' => 'دار هولندية للطقوس الجمالية',
                        'description' => 'عطور ومنتجات عناية مستوحاة من طقوس العالم، في عبوات قابلة لإعادة الاستخدام وبتركيبات راقية.',
                    ],
                    'en' => [
                        'name' => 'Rituals',
                        'tagline' => 'The Dutch ritual house',
                        'description' => 'Fragrances and body care inspired by rituals around the world, in refillable flacons and understated compositions.',
                    ],
                ],
            ],
            [
                'key' => 'ibraq',
                'name' => 'Ibraq',
                'slug' => 'ibraq',
                'origin' => 'Marrakesh, Morocco',
                'position' => 2,
                'tone' => 'amber',
                'translations' => [
                    'fr' => [
                        'name' => 'Ibraq',
                        'tagline' => 'Oud, musc et attars de Marrakech',
                        'description' => 'Maison marrakchia spécialisée dans l’oud, le musc blanc et les attars traditionnels, travaillés en petites séries.',
                    ],
                    'ar' => [
                        'name' => 'إبراق',
                        'tagline' => 'عود ومسك وعودات من مراكش',
                        'description' => 'دار مراكشية متخصصة في العود والمسك الأبيض والعودات التقليدية، تُصنع على دفعات صغيرة.',
                    ],
                    'en' => [
                        'name' => 'Ibraq',
                        'tagline' => 'Oud, musk and attars from Marrakech',
                        'description' => 'A Marrakech house specialised in oud, white musk and traditional attars, produced in small batches.',
                    ],
                ],
            ],
            [
                'key' => 'vs',
                'name' => "Victoria's Secret",
                'slug' => 'victorias-secret',
                'origin' => 'New York, USA',
                'position' => 3,
                'tone' => 'rose',
                'translations' => [
                    'fr' => [
                        'name' => "Victoria's Secret",
                        'tagline' => 'Le parfum américain de la séduction',
                        'description' => 'Senteurs gourmandes et sensuelles, parades à la mode et	body care parfumé, cultes depuis les années 90.',
                    ],
                    'ar' => [
                        'name' => 'فيكتوريا سيكريت',
                        'tagline' => 'العطر الأمريكي للجاذبية',
                        'description' => 'عطور حلوة وجريئة، عروض مميزة وعناية معطرة، cultivée منذ تسعينيات القرن الماضي.',
                    ],
                    'en' => [
                        'name' => "Victoria's Secret",
                        'tagline' => 'The American scent of seduction',
                        'description' => 'Gourmand and sensual fragrances, fashion events and fragranced body care, cult favourites since the 1990s.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function categories(): array
    {
        return [
            [
                'key' => 'perfumes',
                'slug' => 'perfumes',
                'position' => 1,
                'art' => [
                    ['shape' => 'flacon', 'tone' => 'amber', 'size' => '100 ML', 'label' => 'EAU DE PARFUM'],
                    ['shape' => 'dropper', 'tone' => 'espresso', 'size' => '12 ML', 'label' => 'OUD'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Parfums', 'description' => 'Eaux de parfum, eaux de toilette et extraits.'],
                    'ar' => ['name' => 'عطور', 'description' => 'عطور ماء ودي toilet ومستخلصات.'],
                    'en' => ['name' => 'Perfumes', 'description' => 'Eau de parfum, eau de toilette and extracts.'],
                ],
            ],
            [
                'key' => 'body-mist',
                'slug' => 'body-mist',
                'position' => 2,
                'art' => [
                    ['shape' => 'mist', 'tone' => 'rose', 'size' => '250 ML', 'label' => 'BODY MIST'],
                    ['shape' => 'flacon', 'tone' => 'blush', 'size' => '50 ML', 'label' => 'EAU'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Brume/parfumée', 'description' => 'Brumes légères pour le corps et les cheveux.'],
                    'ar' => ['name' => 'معطّر جسم', 'description' => 'رذاذ خفيف للجسم والشعر.'],
                    'en' => ['name' => 'Body mist', 'description' => 'Lightweight sprays for body and hair.'],
                ],
            ],
            [
                'key' => 'body-care',
                'slug' => 'body-care',
                'position' => 3,
                'art' => [
                    ['shape' => 'jar', 'tone' => 'jade', 'size' => '200 ML', 'label' => 'BODY CREAM'],
                    ['shape' => 'tube', 'tone' => 'ivory', 'size' => '200 ML', 'label' => 'HAND CREAM'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Soins du corps', 'description' => 'Crèmes, laits et huiles pour la peau.'],
                    'ar' => ['name' => 'العناية بالجسم', 'description' => 'كريمات وأدهان وزيت للبشرة.'],
                    'en' => ['name' => 'Body care', 'description' => 'Creams, lotions and oils for skin.'],
                ],
            ],
            [
                'key' => 'shower-bath',
                'slug' => 'shower-bath',
                'position' => 4,
                'art' => [
                    ['shape' => 'tube', 'tone' => 'espresso', 'size' => '200 ML', 'label' => 'SHOWER GEL'],
                    ['shape' => 'mist', 'tone' => 'ivory', 'size' => '250 ML', 'label' => 'BATH'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Douche & bain', 'description' => 'Gels douche, bains et parures de hammam.'],
                    'ar' => ['name' => 'الاستحمام والحمّام', 'description' => ' gels douche، أحواض استحمام ومستلزمات الحمام.'],
                    'en' => ['name' => 'Shower & bath', 'description' => 'Shower gels, bath soaks and hammam accessories.'],
                ],
            ],
            [
                'key' => 'gift-sets',
                'slug' => 'gift-sets',
                'position' => 5,
                'art' => [
                    ['shape' => 'carton', 'tone' => 'plum', 'label' => 'GIFT SET'],
                    ['shape' => 'flacon', 'tone' => 'amber', 'size' => '100 ML'],
                    ['shape' => 'dropper', 'tone' => 'espresso', 'size' => '3 ML'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Coffrets cadeaux', 'description' => 'Idéaux à offrir, en boîte ou en étui.'],
                    'ar' => ['name' => 'أطقم الهدايا', 'description' => 'مثالية للإهداء، داخل علب وعلب هدايا.'],
                    'en' => ['name' => 'Gift sets', 'description' => 'Ready to give, boxed and ready to wrap.'],
                ],
            ],
            [
                'key' => 'women',
                'slug' => 'women',
                'position' => 6,
                'art' => [
                    ['shape' => 'flacon', 'tone' => 'rose', 'size' => '50 ML', 'label' => 'EAU DE PARFUM'],
                    ['shape' => 'mist', 'tone' => 'plum', 'size' => '250 ML', 'label' => 'MIST'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Femme', 'description' => 'La sélection pense pour elle.'],
                    'ar' => ['name' => 'نسائي', 'description' => 'مجموعة مختارة لها.'],
                    'en' => ['name' => 'Women', 'description' => 'The selection curated for her.'],
                ],
            ],
            [
                'key' => 'men',
                'slug' => 'men',
                'position' => 7,
                'art' => [
                    ['shape' => 'carton', 'tone' => 'noir', 'label' => 'EAU DE TOILETTE'],
                    ['shape' => 'flacon', 'tone' => 'espresso', 'size' => '100 ML'],
                ],
                'translations' => [
                    'fr' => ['name' => 'Homme', 'description' => 'Bois, cuir et épices pour lui.'],
                    'ar' => ['name' => 'رجالي', 'description' => 'خشب وجلد وبهارات له.'],
                    'en' => ['name' => 'Men', 'description' => 'Woods, leather and spice for him.'],
                ],
            ],
        ];
    }

    /**
     * 21 products spread across brands, categories, genders and price bands, with
     * a realistic mix of discounts, new arrivals and sold-out items.
     *
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            // ---------------------------------------------------------------- Rituals
            [
                'brand' => 'rituals', 'category' => 'gift-sets', 'gender' => 'unisex',
                'sku' => 'RIT-SAM-100', 'size' => '3x100 ML', 'price' => 899, 'compare_at' => 1150,
                'stock' => 14, 'featured' => true, 'rating' => 4.8, 'rating_count' => 213,
                'sales' => 640, 'shape' => 'carton', 'tone' => 'espresso',
                'names' => [
                    'fr' => 'Coffret Samovar Rituals',
                    'ar' => 'طقم ساموفار ريچوالز',
                    'en' => 'Rituals Samovar Gift Set',
                ],
                'short' => [
                    'fr' => 'Le rituel complet : eau de parfum, crème et douche dans un coffret doré.',
                    'ar' => 'الطقوس الكامل: عطر ماء، كريم وغسول في علبة ذهبية.',
                    'en' => 'The complete ritual: eau de parfum, cream and shower gel in a gold box.',
                ],
                'description' => [
                    'fr' => 'Le coffret Samovar réunit l’eau de parfum, la crème corps et le gel douche de la maison dans un écrin doré. Le format ideal pour offrir, avec trois parfums au choix et une carte nominale.',
                    'ar' => 'يجمع طقم ساموفار عطر الماء وكريم الجسم وgel الاستحمام من الدار في علبة ذهبية. الخيار الأمثل للإهداء، بثلاثة عطور للاختيار وبطاقة إهداء.',
                    'en' => 'The Samovar set brings together the house eau de parfum, body cream and shower gel in a gold case. The ideal gift, with three fragrances to choose from and a gift card.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'perfumes', 'gender' => 'unisex',
                'sku' => 'RIT-AGUA-100', 'size' => '100 ML', 'price' => 349, 'compare_at' => null,
                'stock' => 38, 'featured' => true, 'rating' => 4.6, 'rating_count' => 154,
                'sales' => 512, 'shape' => 'flacon', 'tone' => 'jade',
                'names' => [
                    'fr' => 'Agua de Rituals',
                    'ar' => 'أغوا دي ريچوالز',
                    'en' => 'Agua de Rituals',
                ],
                'short' => [
                    'fr' => 'Le parfum iconique de la maison : boisé, frais, ilas et musc blanc.',
                    'ar' => 'عطر الدار الأيقوني: خشبي منعش، إيلاس ومسك أبيض.',
                    'en' => 'The house icon: woody, fresh, lilac and white musk.',
                ],
                'description' => [
                    'fr' => 'Une eau de parfum fraîche et boisée, construite sur l’accord ilas–bois de cèdre. Elle porte le nom de la maison depuis 1990 et reste le premier choix pour qui découvre la marque.',
                    'ar' => 'عطر ماء منعش وخشبي، مبني على اتفاق الإيلاس وخشب الأرز. يحمل اسم الدار منذ 1990 ويظل الخيار الأول لمن يكتشف العلامة.',
                    'en' => 'A fresh, woody eau de parfum built around a lilac–cedar accord. It has carried the house name since 1990 and remains the first choice for anyone discovering the brand.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'body-care', 'gender' => 'women',
                'sku' => 'RIT-SAK-CRM', 'size' => '200 ML', 'price' => 249, 'compare_at' => 299,
                'stock' => 56, 'featured' => false, 'rating' => 4.5, 'rating_count' => 98,
                'sales' => 388, 'shape' => 'jar', 'tone' => 'blush',
                'names' => [
                    'fr' => 'Crème corps Sakura',
                    'ar' => 'كريم جسم ساكورا',
                    'en' => 'Sakura Body Cream',
                ],
                'short' => [
                    'fr' => 'Crème riche au fleur de cerisier, fondante et non grasse.',
                    'ar' => 'كريم غني بزهرة الكرز، يُذوب بسرعة ودون ملمس دهني.',
                    'en' => 'Rich cherry-blossom cream that melts in and never feels greasy.',
                ],
                'description' => [
                    'fr' => 'Une crème corps à la texture fondante, enrichie en huile de cerisier et en glycérine végétale. Elle absorbe en quelques secondes et laisse sur la peau un parfum discret de floraison printanière.',
                    'ar' => 'كريم جسم بقوام يذوب، غني بزيت الكرز والجلسرين النباتي. يُمتص خلال ثوانٍ ويترك على البشرة عطرًا خفيفًا لأزهار الربيع.',
                    'en' => 'A melting body cream enriched with cherry-blossom oil and vegetable glycerin. It absorbs in seconds and leaves a discreet scent of spring blossom on the skin.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'perfumes', 'gender' => 'unisex',
                'sku' => 'RIT-PRV-100', 'size' => '100 ML', 'price' => 649, 'compare_at' => 749,
                'stock' => 22, 'featured' => true, 'rating' => 4.7, 'rating_count' => 61,
                'sales' => 176, 'shape' => 'flacon', 'tone' => 'plum',
                'names' => [
                    'fr' => 'Private Collection',
                    'ar' => 'برايفت كولكشن',
                    'en' => 'Private Collection',
                ],
                'short' => [
                    'fr' => 'Cuir sombre, iris et benjoin. Un parfum de soirée, discret et tenace.',
                    'ar' => 'جلد داكن، سوسن ولبان. عطر مسائي هادئ وثابت.',
                    'en' => 'Dark leather, iris and benzoin. A quiet, long-lasting evening scent.',
                ],
                'description' => [
                    'fr' => 'Une composition sombre et enveloppante : cuir suédé en ouverture, cœur d’iris et fond de benjoin et de Patchouli. Le flacon habillé d’un métal brossé, , on le garde pour les soirées d’hiver.',
                    'ar' => 'تركيبة داكنة ومحيطة: جلد سويدي في المقدمة، قلب من السوسن وقاعدة من اللبان والباتشولي. تُوضع في قنينة معدنية مصقولة، وتُحفظ لسهرات الشتاء.',
                    'en' => 'A dark, enveloping composition: suede leather up top, an iris heart, and a base of benzoin and patchouli. In a brushed metal flacon, it is the one to keep for winter evenings.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'shower-bath', 'gender' => 'unisex',
                'sku' => 'RIT-HAM-200', 'size' => '200 ML', 'price' => 149, 'compare_at' => null,
                'stock' => 74, 'featured' => false, 'rating' => 4.4, 'rating_count' => 76,
                'sales' => 301, 'shape' => 'tube', 'tone' => 'jade',
                'names' => [
                    'fr' => 'Gel douche Hammam',
                    'ar' => 'gel الاستحمام حمّام',
                    'en' => 'Hammam Shower Gel',
                ],
                'short' => [
                    'fr' => 'Gel lavant surgras à la mousse de hammam et à l’huile d’argan.',
                    'ar' => 'gel تنظيف مغذّي برغوة الحمّام وزيت أركان.',
                    'en' => 'A nourishing wash with hammam foam and argan oil.',
                ],
                'description' => [
                    'fr' => 'Un gel douche surgras qui ne dessert pas la peau, enrichi en huile d’argan et en mousse de hammam. La mousse est dense et le rinçage est net.',
                    'ar' => 'gel دوش مغذٍّ لا يجفّف البشرة، غني بزيت أركان ورغوة الحمّام. الرغوة كثيفة والشطف نظيف.',
                    'en' => 'A nourishing shower gel that does not dry the skin, enriched with argan oil and hammam foam. The lather is dense and it rinses clean.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'perfumes', 'gender' => 'men',
                'sku' => 'RIT-DES-50', 'size' => '50 ML', 'price' => 699, 'compare_at' => null,
                'stock' => 17, 'featured' => false, 'rating' => 4.9, 'rating_count' => 34,
                'sales' => 92, 'shape' => 'flacon', 'tone' => 'amber',
                'names' => [
                    'fr' => 'Desert Bloom',
                    'ar' => 'ديزيرت بلوم',
                    'en' => 'Desert Bloom',
                ],
                'short' => [
                    'fr' => 'Oud, safran et.date de palmier : chaud, crémeux, tenace.',
                    'ar' => 'عود، زعفران وتمور: عطر دافئ وكريمي وثابت.',
                    'en' => 'Oud, saffron and date: warm, creamy and long-lasting.',
                ],
                'description' => [
                    'fr' => 'Un oriental moderne où l’oud est adouci par une note lactée de dattes. Le safran apporte l’éclat, la résine de frankincense tient la profondeur. Concentration 22 %.',
                    'ar' => 'شرقي حديث يُلطَّف فيه العود بلمسة حليبية من التمر. يضيف الزعفران بريقًا، ويحفظ اللبان العمق. تركيز 22٪.',
                    'en' => 'A modern oriental where oud is softened by a milky date note. Saffron brings the sparkle, frankincense resin holds the depth. 22% concentration.',
                ],
            ],
            [
                'brand' => 'rituals', 'category' => 'shower-bath', 'gender' => 'unisex',
                'sku' => 'RIT-MIL-200', 'size' => '200 ML', 'price' => 139, 'compare_at' => 169,
                'stock' => 0, 'featured' => false, 'rating' => 4.3, 'rating_count' => 45,
                'sales' => 262, 'shape' => 'tube', 'tone' => 'ivory',
                'names' => [
                    'fr' => 'Gel douche Milky',
                    'ar' => 'gel الاستحمام milky',
                    'en' => 'Milky Shower Gel',
                ],
                'short' => [
                    'fr' => 'Lait de coco et vanille : une douche douce qui sent bon toute la journée.',
                    'ar' => 'حليب جوز الهند والفانيليا: استحمام لطيف برائحة تدوم طوال اليوم.',
                    'en' => 'Coconut milk and vanilla: a gentle wash that still smells good all day.',
                ],
                'description' => [
                    'fr' => 'Un gel douche crémeux au lait de coco, à la vanille bourbon et à l’amande douce. Sa mousse onctueuse convient aux peaux sèches.',
                    'ar' => 'gel دوش كريمي بحليب جوز الهند والفانيليا والكراميل اللوز. رغوته الكريمة تناسب البشرة الجافة.',
                    'en' => 'A creamy shower gel with coconut milk, bourbon vanilla and sweet almond. Its silky lather suits dry skin.',
                ],
            ],

            // ---------------------------------------------------------------- Ibraq
            [
                'brand' => 'ibraq', 'category' => 'perfumes', 'gender' => 'unisex',
                'sku' => 'IBR-OUD-12', 'size' => '12 ML', 'price' => 1290, 'compare_at' => 1490,
                'stock' => 9, 'featured' => true, 'rating' => 4.9, 'rating_count' => 128,
                'sales' => 214, 'shape' => 'dropper', 'tone' => 'espresso',
                'names' => [
                    'fr' => 'Oud Royale',
                    'ar' => 'عود رويال',
                    'en' => 'Oud Royale',
                ],
                'short' => [
                    'fr' => 'Oud du Cambodge et musc de Dänemark, 30 % d’huile essentielle.',
                    'ar' => 'عود كمبودي ومسك معروف، بزيت أساسي 30٪.',
                    'en' => 'Cambodian oud and musk, 30% essential oil.',
                ],
                'description' => [
                    'fr' => 'Un oud concentré à base de bois de agar du Cambodge, macéré et assemblé au musc de Dänemark. La concentration — 30 % d’huile essentielle — en fait un parfum de soirée plutôt qu’un sillage de jour.',
                    'ar' => 'عود مركّز من خشب الصندل الكمبودي، مغلي ومخلوط بمسك الدنمارك. تركيزه 30٪ من الزيت الأساسي، ما يجعله عطر مسائي أكثر منه عطر نهاري.',
                    'en' => 'A concentrated oud built on Cambodian agarwood, macerated and blended with white musk. At 30% essential oil it reads as an evening scent rather than a daytime trail.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'perfumes', 'gender' => 'unisex',
                'sku' => 'IBR-MUS-12', 'size' => '12 ML', 'price' => 749, 'compare_at' => null,
                'stock' => 26, 'featured' => false, 'rating' => 4.7, 'rating_count' => 87,
                'sales' => 198, 'shape' => 'dropper', 'tone' => 'ivory',
                'names' => [
                    'fr' => 'Musk Al Tahara',
                    'ar' => 'مسك الطهرة',
                    'en' => 'Musk Al Tahara',
                ],
                'short' => [
                    'fr' => 'Musc blanc de Barbarie : propre, doux, porté toute la journée.',
                    'ar' => 'مسك أبيض بربري: نقي، ناعم، يُلبس طوال اليوم.',
                    'en' => 'Barbary white musk: clean, soft, wearable all day.',
                ],
                'description' => [
                    'fr' => 'Un musc blanc sans douceur, enrichi d’une pointe de fleur d’oranger amère. C’est le parfum le plus facile à porter au bureau : il reste proche de la peau et ne gêne personne.',
                    'ar' => 'مسك أبيض بلا حلاوة، مع لمسة من زهر البرتقال المر. أسهل عطر يُلبس في العمل: يبقى قريبًا من البشرة ولا يزعج أحدًا.',
                    'en' => 'A white musk without sweetness, sharpened with a little bitter orange blossom. It is the easiest scent to wear at work: it stays close to the skin and bothers nobody.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'body-mist', 'gender' => 'unisex',
                'sku' => 'IBR-BAK-100', 'size' => '100 ML', 'price' => 229, 'compare_at' => null,
                'stock' => 43, 'featured' => false, 'rating' => 4.5, 'rating_count' => 52,
                'sales' => 176, 'shape' => 'mist', 'tone' => 'amber',
                'names' => [
                    'fr' => 'Brume Bakhoor',
                    'ar' => 'معطّر بخور',
                    'en' => 'Bakhoor Mist',
                ],
                'short' => [
                    'fr' => 'Brume brune et enveloppante, à porter sur le vêtement ou le corps.',
                    'ar' => 'رذاذ دافئ ومحيط، يُرش على الملابس أو الجسم.',
                    'en' => 'A warm, enveloping spray for clothing or skin.',
                ],
                'description' => [
                    'fr' => 'Une brume concentrée qui reproduit l’odeur d’une pièce où l’on brûle du bakhoor. À vaporiser sur l’écharpe, les manches ou la peau après la douche.',
                    'ar' => 'رذاذ مركز يعيد رائحة الغرفة التي يُحرق فيها البخور. يُرش على الوشاح أو الأكمام أو البشرة بعد الاستحمام.',
                    'en' => 'A concentrated mist that recreates the smell of a room where bakhoor is burning. Spray it on a scarf, on sleeves, or on skin after the shower.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'body-care', 'gender' => 'women',
                'sku' => 'IBR-LOT-200', 'size' => '200 ML', 'price' => 199, 'compare_at' => 239,
                'stock' => 61, 'featured' => false, 'rating' => 4.4, 'rating_count' => 71,
                'sales' => 231, 'shape' => 'tube', 'tone' => 'plum',
                'names' => [
                    'fr' => 'Lait corporel Ambre',
                    'ar' => 'لبن جسم عنبر',
                    'en' => 'Amber Body Lotion',
                ],
                'short' => [
                    'fr' => 'Lait fluide à l’ambre et à la fleur de coton, absorption immédiate.',
                    'ar' => 'لبن سائل بالعنبر وزهرة القطن، يُمتص فورًا.',
                    'en' => 'A fast-absorbing lotion with amber and cotton blossom.',
                ],
                'description' => [
                    'fr' => 'Un lait corporel léger, sans film gras, qui laisse la peau douce et durablement parfumée. Formulé pour un usage quotidien, matin et soir.',
                    'ar' => 'لبن جسم خفيف بلا طبقة دهنية، يترك البشرة ناعمة وعطرة برائحة تدوم. مُعد للاستخدام اليومي صباحًا ومساءً.',
                    'en' => 'A light body lotion with no greasy film that leaves skin soft and subtly scented. Formulated for daily use, morning and evening.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'perfumes', 'gender' => 'unisex',
                'sku' => 'IBR-ATT-3', 'size' => '3 ML', 'price' => 1990, 'compare_at' => null,
                'stock' => 5, 'featured' => true, 'rating' => 5.0, 'rating_count' => 42,
                'sales' => 118, 'shape' => 'dropper', 'tone' => 'amber',
                'names' => [
                    'fr' => 'Attar royal 3 ml',
                    'ar' => 'عطر ملكي 3 مل',
                    'en' => 'Royal Attar 3 ml',
                ],
                'short' => [
                    'fr' => 'Huile d’attar pure, sans alcool. Le sommet de la maison.',
                    'ar' => 'زيت عود خالص بلا كحول. قمة الدار.',
                    'en' => 'Pure attar oil, alcohol-free. The house at its peak.',
                ],
                'description' => [
                    'fr' => 'L’attar le plus concentré de la maison : huile distillée à la rose de Damas, sans alcool, sans dilution. Trois millilitres, et c’est tout. Un objet que l’on garde.',
                    'ar' => 'أقوى عطر في الدار: زيت مكرر من الورد الدمشقي، بلا كحول وبلا تخفيف. ثلاثة ملليلترات فقط. شيء يُحتفظ به.',
                    'en' => 'The most concentrated scent in the house: damask rose oil, distilled, alcohol-free, undiluted. Three millilitres, and that is all. An object worth keeping.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'body-mist', 'gender' => 'women',
                'sku' => 'IBR-ROS-250', 'size' => '250 ML', 'price' => 129, 'compare_at' => null,
                'stock' => 88, 'featured' => false, 'rating' => 4.6, 'rating_count' => 93,
                'sales' => 402, 'shape' => 'mist', 'tone' => 'rose',
                'names' => [
                    'fr' => 'Eau de rose',
                    'ar' => 'ماء الورد',
                    'en' => 'Rose Water',
                ],
                'short' => [
                    'fr' => 'Eau de rose de Damas distillée, sans alcool ni conservateur.',
                    'ar' => 'ماء ورد دمشقي مقطر، بلا كحول ولا مواد حافظة.',
                    'en' => 'Distilled damask rose water, no alcohol, no preservative.',
                ],
                'description' => [
                    'fr' => 'Une eau de rose de Damas distillée, obtenue par entraînement des pétales et embouteillée sans alcool. Elle tonifie après le gommage et rafraîchit le visage en journée.',
                    'ar' => 'ماء ورد دمشقي مقطر بالنشر البخاري للبتلات، معبأ بلا كحول. ينشّ البشرة بعد التقشير وينعش الوجه في النهار.',
                    'en' => 'Damask rose water obtained by distilling petals, bottled without alcohol. It tones the skin after exfoliation and refreshes the face through the day.',
                ],
            ],
            [
                'brand' => 'ibraq', 'category' => 'gift-sets', 'gender' => 'unisex',
                'sku' => 'IBR-DIS-4', 'size' => '4 pièces', 'price' => 549, 'compare_at' => 699,
                'stock' => 31, 'featured' => false, 'rating' => 4.8, 'rating_count' => 66,
                'sales' => 155, 'shape' => 'carton', 'tone' => 'noir',
                'names' => [
                    'fr' => 'Coffret découverte Ibraq',
                    'ar' => 'طقم اكتشاف إبراق',
                    'en' => 'Ibraq Discovery Set',
                ],
                'short' => [
                    'fr' => 'Quatre attars de 2 ml : la façon la plus simple de trouver son parfum.',
                    'ar' => 'أربعة عودات 2 مل: أسهل طريقة للعثور على عطرك.',
                    'en' => 'Four 2 ml attars: the easiest way to find your scent.',
                ],
                'description' => [
                    'fr' => 'Quatre attars de 2 ml dans un étui en bois gravé : oud, musc, attar à la rose et musc blond. Le cadeau sûr, à petit prix, pour quelqu’un qui hésite encore.',
                    'ar' => 'أربعة عودات 2 مل في علبة خشبية محفورة: عود، مسك، عود بالورد، ومسك خلوي. هدية مضمونة وبسعر معقول لمن ما زال مترددًا.',
                    'en' => 'Four 2 ml attars in an engraved wooden case: oud, musk, rose attar and musk. The safe gift, at a friendly price, for someone still undecided.',
                ],
            ],

            // ---------------------------------------------------------------- Victoria's Secret
            [
                'brand' => 'vs', 'category' => 'perfumes', 'gender' => 'women',
                'sku' => 'VSC-BOM-50', 'size' => '50 ML', 'price' => 679, 'compare_at' => 799,
                'stock' => 29, 'featured' => true, 'rating' => 4.7, 'rating_count' => 302,
                'sales' => 871, 'shape' => 'flacon', 'tone' => 'rose',
                'names' => [
                    'fr' => 'Bombshell Intense',
                    'ar' => 'بومبشي إنتنس',
                    'en' => 'Bombshell Intense',
                ],
                'short' => [
                    'fr' => 'La signature de la maison : fruit rouge, vanilla et musc chaud.',
                    'ar' => 'توقيع الدار: فواكه حمراء، فانيليا ومسك دافئ.',
                    'en' => 'The house signature: red fruit, vanilla and warm musk.',
                ],
                'description' => [
                    'fr' => 'Bombshell Intense reprend le cœur fruité du Bombshell d’origine en le poussant vers le musc chaud et la vanille. Plus dense, plus sombre, et toujours immédiat.',
                    'ar' => 'يعيد بومبشي إنتنس اعتماد القلب الفاكهي للبومبشي الأصلي مع دفعة من المسك الدافئ والفانيليا. أكثر كثافة وأغمق، ويبقى فوريًا.',
                    'en' => 'Bombshell Intense takes the fruity heart of the original Bombshell and pushes it towards warm musk and vanilla. Denser, darker, and still immediate.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'perfumes', 'gender' => 'women',
                'sku' => 'VSC-VSX-50', 'size' => '50 ML', 'price' => 649, 'compare_at' => null,
                'stock' => 21, 'featured' => false, 'rating' => 4.5, 'rating_count' => 188,
                'sales' => 604, 'shape' => 'flacon', 'tone' => 'blush',
                'names' => [
                    'fr' => 'Very Sexy',
                    'ar' => 'فيري سيكسي',
                    'en' => 'Very Sexy',
                ],
                'short' => [
                    'fr' => 'Cèdre, gingembre et musc de miel.',
                    'ar' => 'أرز وجينجبر فوق قاعدة من مسك العسل.',
                    'en' => 'Cedar and ginger over a honeyed musk base.',
                ],
                'description' => [
                    'fr' => 'Un Oriental boisé construit sur le musc de miel : girofle, cannelle et musc de miel. Il a vingt ans et ne se fatigue pas.',
                    'ar' => 'شرقي خشبي مبني على مسك العسل: قرنفل وقرفة ومسك العسل. عمره عشرون عامًا ولا يتقادم.',
                    'en' => 'A woody oriental built on honeyed musk: clove, cinnamon and honeyed musk. Twenty years old and it has not dated.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'body-mist', 'gender' => 'women',
                'sku' => 'VSC-PUR-250', 'size' => '250 ML', 'price' => 249, 'compare_at' => null,
                'stock' => 67, 'featured' => false, 'rating' => 4.6, 'rating_count' => 145,
                'sales' => 522, 'shape' => 'mist', 'tone' => 'ivory',
                'names' => [
                    'fr' => 'PURE',
                    'ar' => 'بيور',
                    'en' => 'PURE',
                ],
                'short' => [
                    'fr' => 'Musc blanc et brise fraîche : le parfum du matin, discret et net.',
                    'ar' => 'مسك أبيض ونظارة باردة: عطر الصباح، هادئ ونقي.',
                    'en' => 'White musk and a fresh breeze: the morning scent, quiet and clean.',
                ],
                'description' => [
                    'fr' => 'Le parfum le plus simple de la maison : du musc blanc sur un accord frais de fleur d’agrumes. Une brume légère qui se porte sur tout, toute la journée.',
                    'ar' => 'أبسط عطور الدار: مسك أبيض فوق اتفاق منعش من أزهار الحمضيات. رذاذ خفيف يُلبس في كل وقت طوال اليوم.',
                    'en' => 'The simplest scent in the house: white musk over a fresh citrus-blossom accord. A light mist you can wear on everything, all day.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'perfumes', 'gender' => 'women',
                'sku' => 'VSC-VSW-50', 'size' => '50 ML', 'price' => 599, 'compare_at' => 749,
                'stock' => 34, 'featured' => true, 'rating' => 4.8, 'rating_count' => 119,
                'sales' => 287, 'shape' => 'flacon', 'tone' => 'blush',
                'names' => [
                    'fr' => 'Vanilla Swirl',
                    'ar' => 'فانيليا سويل',
                    'en' => 'Vanilla Swirl',
                ],
                'short' => [
                    'fr' => 'La vanille, la dose, woods and a praline that lingers.',
                    'ar' => 'فانيليا، خشب وبرالين يدوم.',
                    'en' => 'Vanilla, woods and a praline note that lingers.',
                ],
                'description' => [
                    'fr' => 'Un gourmand de fin de soirée : vanille de Madagascar sur un fond de bois de santal et de praliné. La présence est forte, la tenue dépasse huit heures.',
                    'ar' => 'عطر حلو لنهاية الأمسية: فانيليا مدغشقرية على قاعدة من خشب الصندول والبرالين. حضور قوي يدوم أكثر من ثماني ساعات.',
                    'en' => 'An after-dinner gourmand: Madagascan vanilla over sandalwood and praline. Strong in projection, lasting beyond eight hours.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'perfumes', 'gender' => 'women',
                'sku' => 'VSC-LOV-100', 'size' => '100 ML', 'price' => 699, 'compare_at' => null,
                'stock' => 18, 'featured' => false, 'rating' => 4.4, 'rating_count' => 97,
                'sales' => 243, 'shape' => 'flacon', 'tone' => 'plum',
                'names' => [
                    'fr' => 'Love Addict',
                    'ar' => 'لاف أدِكت',
                    'en' => 'Love Addict',
                ],
                'short' => [
                    'fr' => 'Thé noir, framboise et patchouli : le parfum qui tient la soirée.',
                    'ar' => 'شاي أسود، توت علطي وباتشولي: عطر يُمسك السهرة.',
                    'en' => 'Black tea, raspberry and patchouli: the scent that holds the evening.',
                ],
                'description' => [
                    'fr' => 'Un oriental fruité où le thé noir fumé rencontre la framboise. Le patchouli lui donne une gravité warmly sensuelle, et le fond de cuir le fixe sur la peau.',
                    'ar' => 'شرقي فاكهي يلتقي فيه الشاي الأسود المدخّن بالتوت العليقي. يمنحه الباتشولي ثقلًا دافئًا وحسّيًا، ويثبّته جلد على البشرة.',
                    'en' => 'A fruity oriental where smoked black tea meets raspberry. Patchouli gives it warm, sensual weight, and leather fixes it to the skin.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'body-mist', 'gender' => 'women',
                'sku' => 'VSC-MID-250', 'size' => '250 ML', 'price' => 289, 'compare_at' => 349,
                'stock' => 0, 'featured' => false, 'rating' => 4.5, 'rating_count' => 84,
                'sales' => 319, 'shape' => 'mist', 'tone' => 'plum',
                'names' => [
                    'fr' => 'Midnight Ambrosia',
                    'ar' => 'ميدنايت أمبروسيا',
                    'en' => 'Midnight Ambrosia',
                ],
                'short' => [
                    'fr' => 'Prune, ambre et musc : un soir d’hiver dans un flacon.',
                    'ar' => 'برقوق، عنبر ومسك: أمسي شتوية في قنينة.',
                    'en' => 'Plum, amber and musk: a winter evening in a bottle.',
                ],
                'description' => [
                    'fr' => 'Un oriental de nuit où la prune rencontre l’ambre gris. Très smooth, il s’ouvre mieux à la_HEATc qu’après une journée passée dehors.',
                    'ar' => 'شرقي ليلي تلتقي فيه البرقوق بالعنبر الرمادي. ناعم جدًا، وينفتح بشكل أفضل مع الدفء من بعد يوم في الخارج.',
                    'en' => 'A nocturnal oriental where plum meets grey amber. Very smooth; it opens better with warmth than after a day spent outside.',
                ],
            ],
            [
                'brand' => 'vs', 'category' => 'body-care', 'gender' => 'women',
                'sku' => 'VSC-PIN-300', 'size' => '300 ML', 'price' => 379, 'compare_at' => null,
                'stock' => 47, 'featured' => false, 'rating' => 4.7, 'rating_count' => 76,
                'sales' => 258, 'shape' => 'jar', 'tone' => 'rose',
                'names' => [
                    'fr' => 'Crème PINK Satin',
                    'ar' => 'كريم بينك ساتان',
                    'en' => 'PINK Satin Cream',
                ],
                'short' => [
                    'fr' => 'Crème satinée au lait et à la pêche, fini velours non gras.',
                    'ar' => 'كريم ساتاني بالحليب والخوخ، ملمس مخملي بلا دهون.',
                    'en' => 'Satin cream with milk and peach, a non-greasy velvet finish.',
                ],
                'description' => [
                    'fr' => 'Une crème riche en en cocoa butter, à la texture satinée et à la finition non grasse. Le parfum PINK reste discret sur la peau.',
                    'ar' => 'كريم غني بزبدة الكاكاو، بقوام ساتاني ونهاية بلا دهون. يبقى عطر بينك خفيفًا على البشرة.',
                    'en' => 'A cream rich in cocoa butter, with a satin texture and a non-greasy finish. The PINK scent stays quiet on skin.',
                ],
            ],
        ];
    }

    /**
     * Localised slug: Arabic names keep their own script so /ar/products/عطر
     * reads naturally, while French and English stay ASCII for clean URLs.
     */
    public static function slugify(string $name, string $locale): string
    {
        $slug = Str::slug($name);

        if ($slug !== '') {
            return $slug;
        }

        // Str::slug() strips Arabic; rebuild it from the unicode letters.
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_strtolower($slug, 'UTF-8');

        return $slug !== '' ? $slug : $locale.'-product';
    }
}
