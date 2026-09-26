<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite renders Blade without a compiled asset bundle.
     *
     * Any screen test loads `components.layouts.app`, which calls `@vite`, and
     * that throws unless `public/build/manifest.json` exists. On a machine that
     * has run `npm run build` at some point the file is simply there, so the
     * dependency was invisible: the suite passed locally while failing on any
     * checkout that had never built the frontend, CI included.
     *
     * Stubbing Vite out here removes the coupling rather than hiding it. What
     * these tests assert is behaviour and markup, never the hashed filename of
     * a bundle, and the bundle still has to compile: that is a separate job in
     * the CI workflow, which is the right place to check it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
