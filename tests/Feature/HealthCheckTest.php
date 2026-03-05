<?php

test('health check returns ok status', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
        ])
        ->assertJsonStructure([
            'status',
            'database',
        ]);
});

test('health check reports database connectivity', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJsonPath('database', 'ok');
});
