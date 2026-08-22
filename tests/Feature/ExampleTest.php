<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_serves_the_public_calendar(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Roemah Umara');
    }

    public function test_the_staff_panel_still_needs_a_login(): void
    {
        $this->get('/cms')->assertRedirect('/cms/login');
    }
}
