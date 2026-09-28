<?php

/*
| Shipper-side spelling for the cities the carrier does not spell the way we
| do. Every other city falls back to the carrier's own name, so a key here must
| match config/shipping_zones.php exactly (including case).
*/

return [
    'cities' => [
        'CASABLANCA' => 'Casablanca',
        'RABAT' => 'Rabat',
        'MARRAKECH' => 'Marrakesh',
        'FES' => 'Fez',
        'MEKNES' => 'Meknes',
        'TANGER' => 'Tangier',
        'AGADIR' => 'Agadir',
        'OUJDA' => 'Oujda',
        'KENITRA' => 'Kenitra',
        'TEMARA' => 'Temara',
        'SALE' => 'Salé',
        'EL JADIDA' => 'El Jadida',
        'Beni Mellal' => 'Beni Mellal',
        'Nador' => 'Nador',
        'KHOURIBGA' => 'Khouribga',
        'Taza' => 'Taza',
        'taroudant' => 'Taroudant',
        'SAFI' => 'Safi',
        'ERRACHIDIA' => 'Errachidia',
        'ESSAOUIRA' => 'Essaouira',
        'Laayoune' => 'Laayoune',
        'Dakhla' => 'Dakhla',
    ],
];
