<?php

/*
| Shipper-side spelling for the cities the carrier does not spell the way we
| do. Every other city falls back to the carrier's own name, so a key here must
| match config/shipping_zones.php exactly (including case).
*/

return [
    'cities' => [
        'CASABLANCA' => 'الدار البيضاء',
        'RABAT' => 'الرباط',
        'MARRAKECH' => 'مراكش',
        'FES' => 'فاس',
        'MEKNES' => 'مكناس',
        'TANGER' => 'طنجة',
        'AGADIR' => 'أكادير',
        'OUJDA' => 'وجدة',
        'KENITRA' => 'القنيطرة',
        'TEMARA' => 'تمارة',
        'SALE' => 'سلا',
        'EL JADIDA' => 'الجديدة',
        'Beni Mellal' => 'بني ملال',
        'Nador' => 'الناظور',
        'KHOURIBGA' => 'خريبكة',
        'Taza' => 'تازة',
        'taroudant' => 'تارودانت',
        'SAFI' => 'صفاقس',
        'ERRACHIDIA' => 'الرشيدية',
        'ESSAOUIRA' => 'الصويرة',
        'Laayoune' => 'العيون',
        'Dakhla' => 'الداخلة',
    ],
];
