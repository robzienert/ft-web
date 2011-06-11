<?php

require_once __DIR__ . 'silex.phar';

$app = new Silex\Application();

$app->get('/', function() {

});

// Add a new fucktowner
$app->post('/new', function() {

});

// Report abuse behavior.
$app->get('/report/{id}', function($id) use ($app) {
    $request = $app;
});