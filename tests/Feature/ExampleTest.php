<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_the_website_to_guests_and_the_app_to_signed_in_users(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('/login');
        $this->actingAs(\App\Models\User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
    }
}
