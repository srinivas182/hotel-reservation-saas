<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_sends_guests_to_sign_in(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }
}
