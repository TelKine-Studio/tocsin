<?php

namespace Tests;

use App\Support\Installation;
use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Installation::flush();
        $this->app->make(Settings::class)->refresh();
    }
}
