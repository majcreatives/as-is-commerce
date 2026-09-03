<?php

declare(strict_types=1);

it('reports the application as healthy', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'database' => 'ok',
        ]);
});

/*
 * The endpoint is unauthenticated, so it must not become a source of
 * infrastructure detail for an attacker.
 */
it('does not leak infrastructure details', function (): void {
    $body = $this->getJson('/health')->getContent();

    expect($body)
        ->not->toContain(config('database.connections.mysql.database'))
        ->not->toContain(config('database.connections.mysql.username'))
        ->not->toContain('127.0.0.1')
        ->not->toContain(base_path());
});
