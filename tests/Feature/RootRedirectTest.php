<?php

use App\Models\User;

test('a guest visiting the root is redirected to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});

test('an authenticated user visiting the root is redirected to their projects', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect('/projects');
});
