<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A real (1×1) JPEG upload. Unlike UploadedFile::fake()->image() it needs no GD extension,
     * so the tests run on any PHP install.
     */
    protected function fakeImage(string $name = 'photo.jpg'): \Illuminate\Http\UploadedFile
    {
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $jpeg);
    }

    protected function tearDown(): void
    {
        // A frozen clock must never leak into the next test.
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }
}
