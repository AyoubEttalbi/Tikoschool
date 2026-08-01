<?php

test('the root url sends guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('the login page renders', function () {
    $this->get('/login')->assertStatus(200);
});

test('the health check endpoint responds', function () {
    $this->get('/up')->assertStatus(200);
});
