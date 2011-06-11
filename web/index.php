<?php

use Symfony\Component\HttpFoundation\Response;

defined('ROOT_PATH') || define('ROOT_PATH', realpath(__DIR__ . '/../'));

require_once ROOT_PATH . '/silex.phar';

$app = new Silex\Application();

$app->get('/', function() {
    return 'hi';
});

$app->post('/new', function() {

});

$app->get('/feed', function() {

});

$app->run();