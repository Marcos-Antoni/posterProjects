<?php

test('returns a successful response', function () {
    // The root route ("home") no longer renders a page directly — since
    // T-8.10 it redirects guests to /login and authenticated users to
    // /projects. See RootRedirectTest.php for the full behavior.
    $response = $this->get(route('home'));

    $response->assertRedirect('/login');
});
