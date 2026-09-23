<?php

namespace Tests\Feature;

// 此段說明相鄰程式碼的用途與預期行為。
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
