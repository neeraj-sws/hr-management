<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // A frozen clock must never leak into the next test.
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }
}
