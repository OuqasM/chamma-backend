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
        'MARRAKECH' => 'Marrakech',
        'FES' => 'Fès',
        'MEKNES' => 'Meknès',
        'TANGER' => 'Tanger',
        'AGADIR' => 'Agadir',
        'OUJDA' => 'Oujda',
        'KENITRA' => 'Kénitra',
        'TEMARA' => 'Témara',
        'SALE' => 'Salé',
        'EL JADIDA' => 'El Jadida',
        'Beni Mellal' => 'Béni Mellal',
        'Nador' => 'Nador',
        'KHOURIBGA' => 'Khouribga',
        'Taza' => 'Taza',
        'taroudant' => 'Taroudant',
        'SAFI' => 'Safi',
        'ERRACHIDIA' => 'Errachidia',
        'ESSAOUIRA' => 'Essaouira',
        'Laayoune' => 'Laâyoune',
        'Dakhla' => 'Dakhla',
    ],
];
