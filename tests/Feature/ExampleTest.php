<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path redirects to the customer-facing store (by design, see routes/web.php).
     */
    public function test_the_application_redirects_root_to_store(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('store'));
    }
}
