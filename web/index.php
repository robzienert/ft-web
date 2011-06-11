<?php

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

defined('ROOT_PATH') || define('ROOT_PATH', realpath(__DIR__ . '/../'));
defined('APP_ENV') || define('APP_ENV', (getenv('APP_ENV') ?: 'production'));

require_once ROOT_PATH . '/silex.phar';

//function id($object) {
//    return new $object;
//}

/**
 * Bootstrapping
 */
$app = new Silex\Application();

$app->register(new Silex\Extension\TwigExtension(), array(
    'twig.path' => ROOT_PATH . '/views',
    'twig.class_path' => ROOT_PATH . '/vendor/twig/lib',
));

$app['autoloader']->registerNamespace('SilexExtension', ROOT_PATH . '/vendor/silex-extensions/src');
$app->register(new SilexExtension\PredisExtension(), array(
    'predis.class_path' => ROOT_PATH . '/vendor/predis/lib',
    'predis.server' => array(
        'host' => '127.0.0.1',
        'port' => 6379
    ),
    'predis.config' => array(
        'prefix' => 'fucktown:'
    )
));

/**
 * Homepage
 *
 * @todo How do I handle optional arguments?
 */
$app->get('/', function () use ($app) {
    $fuckups = ft_find_fuckups($app);

    return $app['twig']->render('index.twig', array('fuckups' => $fuckups));

});
$app->get('/{page}', function ($page = 1) use ($app) {
    $fuckups = ft_find_fuckups($app);

    return $app['twig']->render('index.twig', array('fuckups' => $fuckups));
});

/**
 * Add a new fuckup to the site.
 */
$app->post('/new', function () use ($app) {
    $request = $app['request'];

    $entryId = $app['predis']->incr('global:nextEntryId');

    $verb = ($request->get('verb') == 'custom')
        ? $request->get('custom_verb')
        : $request->get('verb');

    // @todo Add data sanitization
    $entry = array(
        'time' => date(DateTime::ISO8601),
        'who' => $request->get('who'),
        'verb' => $verb,
        'fuckup' => $request->get('fuckup')
    );
    $app['predis']->set("fuckup:$entryId", json_encode($entry));

    $app['predis']->lpush('global:fuckups', $entryId);
    $app['predis']->ltrim('global:fuckups', 0, 1000);

    return new Symfony\Component\HttpFoundation\RedirectResponse('/');
});

/**
 * RSS feed
 */
$app->get('/feed', function () use ($app) {
    $fuckups = ft_find_fuckups($app);

    return $app['twig']->render('feed.twig');
});

if ('development' != APP_ENV) {
    $app->error(function (\Exception $e) {
        if ($e instanceof NotFoundHttpException) {
            return new Response('The requested page could not be found.', 404);
        }

        $code = ($e instanceof HttpException) ? $e->getStatusCode() : 500;
        return new Response('We are sorry, but something went terribly wrong.', $code);
    });
}

// And finally run.
$app->run();

/**
 * Get a list of fuckups.
 *
 * @param \Silex\Application $app
 * @param int $page
 * @return array
 */
function ft_find_fuckups($app, $page = 1) {
    $fuckups = array();

    $rangeStart = ($page - 1) * 10;
    $rangeEnd = ($page * 10) + 9;

    foreach ($app['predis']->lrange('global:fuckups', $rangeStart, $rangeEnd) as $fuckupId) {
        $fuckup = $app['predis']->get("fuckup:$fuckupId");
        $fuckup = json_decode($fuckup);

        array_push($fuckups, $fuckup);
    }

    return $fuckups;
}