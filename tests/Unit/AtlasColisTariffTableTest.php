<?php

namespace Tests\Unit;

use App\Support\Shipping\AtlasColisTariffTable;
use PHPUnit\Framework\TestCase;

/**
 * The tariff table is a third-party page, so the parser is the piece that must
 * not be trusted: these lock down the reading of the carrier's markup and,
 * more importantly, that a page which is *not* the tariff table yields nothing.
 */
class AtlasColisTariffTableTest extends TestCase
{
    private AtlasColisTariffTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table = new AtlasColisTariffTable;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $rows
     */
    private function page(array ...$rows): string
    {
        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr  id="city-'.$row[0].'">'
                .'<td>'.$row[1].'</td>'
                .'<td>'.$row[2].'</td>'
                .'<td> '.$row[3].'</td>'
                .'<td>'.$row[4].'</td>'
                .'<td>'.$row[5].'</td>'
                .'</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Tarifs</title></head><body>'
            .'<table><thead><tr><th>Villes</th><th>Délai</th><th>Frais De Livraison</th>'
            .'<th>Frais De Refuse</th><th>Frais De Retour</th></tr></thead>'
            .'<tbody>'.$body.'</tbody></table></body></html>';
    }

    public function test_it_reads_city_delay_and_delivery_fee(): void
    {
        $zones = $this->table->parse($this->page(
            ['BML', 'Beni Mellal', '8h', '20 DH', 'Gratuit', 'Gratuit'],
            ['CAS', 'Casablanca', '24H', '35 DH', '10 DH', 'Gratuit'],
        ));

        $this->assertCount(2, $zones);
        $this->assertSame([
            'code' => 'BML',
            'city' => 'Beni Mellal',
            'fee' => 20.0,
            'delay' => '8h',
            'refusal_fee' => 0.0,
            'return_fee' => 0.0,
        ], $zones[0]);
        $this->assertSame('Casablanca', $zones[1]['city']);
        $this->assertSame(35.0, $zones[1]['fee']);
        $this->assertSame('24H', $zones[1]['delay'], 'the carrier capitalises some delays; keep them verbatim');
        $this->assertSame(10.0, $zones[1]['refusal_fee']);
    }

    public function test_the_header_row_is_not_a_zone(): void
    {
        // The fixture always carries the `th` header row; it must not appear.
        $zones = $this->table->parse($this->page(['BML', 'Beni Mellal', '8h', '20 DH', 'Gratuit', 'Gratuit']));

        $this->assertSame(['Beni Mellal'], array_column($zones, 'city'));
    }

    public function test_a_missing_delay_is_unknown_not_zero(): void
    {
        $zones = $this->table->parse($this->page(['AFR', 'Afra', '', '45 DH', 'Gratuit', 'Gratuit']));

        $this->assertNull($zones[0]['delay']);
    }

    public function test_comma_decimal_fees_are_understood(): void
    {
        $zones = $this->table->parse($this->page(['XXX', 'Test', '', '20,50 DH', 'Gratuit', 'Gratuit']));

        $this->assertSame(20.5, $zones[0]['fee']);
    }

    public function test_rows_without_five_columns_are_skipped(): void
    {
        $html = '<table><tbody>'
            .'<tr id="city-OK"><td>Casablanca</td><td>24H</td><td>35 DH</td><td>Gratuit</td><td>Gratuit</td></tr>'
            .'<tr id="city-BAD"><td>Nowhere</td><td>24H</td><td>35 DH</td></tr>'
            .'</tbody></table>';

        $zones = $this->table->parse($html);

        $this->assertCount(1, $zones);
        $this->assertSame('Casablanca', $zones[0]['city']);
    }

    public function test_a_cityless_row_is_skipped(): void
    {
        $html = '<table><tbody><tr id="city-X"><td>  </td><td>24H</td><td>35 DH</td><td>Gratuit</td><td>Gratuit</td></tr></tbody></table>';

        $this->assertSame([], $this->table->parse($html));
    }

    public function test_a_row_without_an_id_falls_back_to_the_city_name(): void
    {
        $html = '<table><tbody><tr><td>regagada</td><td></td><td>40 DH</td><td>10 DH</td><td>Gratuit</td></tr></tbody></table>';

        $this->assertSame('regagada', $this->table->parse($html)[0]['code']);
    }

    /**
     * The dangerous case: the carrier page errors out and the import would
     * happily write an empty delivery map, closing the store.
     */
    public function test_a_page_that_is_not_the_tariff_table_yields_nothing(): void
    {
        $this->assertSame([], $this->table->parse(''));
        $this->assertSame([], $this->table->parse('   '));
        $this->assertSame([], $this->table->parse('not html at all'));
        $this->assertSame([], $this->table->parse('<html><body><h1>502 Bad Gateway</h1></body></html>'));
        $this->assertSame([], $this->table->parse('<html><body><table><tr><td>Panier</td></tr></table></body></html>'));
    }

    public function test_it_reads_the_shipped_tariff_file_shape(): void
    {
        // Sanity check on the real page's markup: every data row has an id and
        // five cells, which is what the parser keys off.
        $zones = $this->table->parse($this->page(
            ['BML', 'Beni Mellal', '8h', '20 DH', 'Gratuit', 'Gratuit'],
            ['OMS', 'OULAD MOUSSA', '8h', '25 DH', 'Gratuit', 'Gratuit'],
        ));

        foreach ($zones as $zone) {
            $this->assertNotSame('', $zone['code']);
            $this->assertGreaterThan(0, $zone['fee']);
        }
    }

    public function test_the_diff_reports_added_removed_and_repriced_cities(): void
    {
        $current = [
            ['code' => 'CAS', 'city' => 'Casablanca', 'fee' => 35.0, 'delay' => '24H', 'refusal_fee' => 0.0, 'return_fee' => 0.0],
            ['code' => 'LAA', 'city' => 'Laayoune', 'fee' => 50.0, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ];

        $incoming = [
            ['code' => 'CAS', 'city' => 'casablanca', 'fee' => 40.0, 'delay' => '24H', 'refusal_fee' => 0.0, 'return_fee' => 0.0],
            ['code' => 'BML', 'city' => 'Beni Mellal', 'fee' => 20.0, 'delay' => '8h', 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ];

        $diff = $this->table->diff($current, $incoming);

        $this->assertSame(['Beni Mellal'], $diff['added'], 'case differences are the same city');
        $this->assertSame(['Laayoune'], $diff['removed']);
        $this->assertSame([['city' => 'casablanca', 'from' => 35.0, 'to' => 40.0]], $diff['changed']);
        $this->assertSame(2, $diff['total']);
    }

    public function test_the_diff_keeps_the_cheapest_duplicate(): void
    {
        $current = [
            ['code' => 'A', 'city' => 'Casablanca', 'fee' => 35.0, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ];

        $incoming = [
            ['code' => 'A', 'city' => 'Casablanca', 'fee' => 50.0, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
            ['code' => 'B', 'city' => 'CASABLANCA ', 'fee' => 40.0, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ];

        $diff = $this->table->diff($current, $incoming);

        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        // Reported under the incoming spelling, since that is what will ship.
        $this->assertSame([['city' => 'CASABLANCA', 'from' => 35.0, 'to' => 40.0]], $diff['changed']);
    }
}
