<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * Start every test with an empty cache.
     *
     * The suite runs with the `array` cache store, which lives for the length
     * of the process rather than the length of the test, so a cached answer
     * from one test would still be there for the next one — while the
     * database underneath it has been rolled back and rebuilt by
     * `RefreshDatabase`. A test that read a listing and then created a product
     * would get the previous test's listing back and see a product that does
     * not exist.
     *
     * Flushing here is the same bargain `RefreshDatabase` makes: the database
     * is rebuilt for every test, and so is the cache.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }
}
