<?php

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

defined('ROOT_PATH') || define('ROOT_PATH', realpath(__DIR__ . '/../'));
defined('APP_ENV') || define('APP_ENV', (getenv('APP_ENV') ?: 'production'));

// Cleanup after some Zend Server includes. Since I'm doing this, I'm probably
// doing something wrong. I'd like to use ZF2, but the components are broked.
if (false !== strpos('/zend/share/ZendFramework', get_include_path())) {
    $paths = explode(':', get_include_path());
    $paths = array_filter($paths, function($path) {
        return (false === strpos($path, '/zend/share/ZendFramework'));
    });
    $paths[] = ROOT_PATH . '/vendor/zf/library';
    set_include_path(implode(':', $paths));
}

require_once ROOT_PATH . '/silex.phar';

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
 * RSS feed
 */
$app->get('/feed', function () use ($app) {
    $request = $app['request'];

    $fuckups = ft_find_fuckups($app);

    require_once 'Zend/Feed/Writer/Feed.php';

    $feed = new Zend_Feed_Writer_Feed();
    $feed->setTitle('In FUCKTOWN');
    $feed->setDescription('Holy shit dude! A website to anonymously post other ' .
        'people&apos;s fuckups!');
    $feed->setDateModified(strtotime(current($fuckups)->time));

    $host = 'http://' . $request->getHost();
    $feed->setLink($host);
    $feed->setFeedLink($host . '/feed', 'rss');

    foreach ($fuckups as $fuckup) {
        $content = sprintf('%s %s in FUCKTOWN because %s.',
                           $fuckup->who,
                           $fuckup->verb,
                           $fuckup->fuckup);

        $entry = $feed->createEntry();
        $entry->setTitle(sprintf('%s is in FUCKTOWN', $fuckup->who));
        $entry->addAuthor('InFucktown');
        $entry->setContent($content);
        $entry->setDateCreated(strtotime($fuckup->time));
//        $entry->setLink('http://infucktown.com/fuckup/' . $fuckup['id']);

        $feed->addEntry($entry);
    }

    $markup = $feed->export('rss');

    return new Response($markup);
});

/**
 * Homepage
 */
$app->get('/{page}', function ($page = 1) use ($app) {
    $pages = ft_count_pages($app);

    $paginate = (int) $pages - 1;

    return $app['twig']->render('index.twig', array(
        'fuckups' => ft_find_fuckups($app, $page),
        'message' => ft_get_flash_message($app['request']->query->get('msg')),
        'pagination' => array(
            'pages' => $pages,
            'current' => $page,
        ),
        'paginate' => $pages - 1
    ));
})->value('page', 1)->bind('home');

/**
 * Add a new fuckup to the site.
 */
$app->post('/new', function () use ($app) {
    $request = $app['request'];

    $verb = ($request->get('verb') == 'custom')
        ? $request->get('custom_verb')
        : $request->get('verb');

    $entry = array(
        'time' => date(DateTime::RSS),
        'who' => $app->escape($request->get('who')),
        'verb' => $app->escape($verb),
        'fuckup' => $app->escape($request->get('fuckup'))
    );

    if (!empty($entry['who']) && !empty($entry['fuckup'])) {
        $entryId = $app['predis']->incr('global:nextEntryId');

        $app['predis']->set("fuckup:$entryId", json_encode($entry));

        $app['predis']->lpush('global:fuckups', $entryId);
        $app['predis']->ltrim('global:fuckups', 0, 1000);

        $redirect = '/';
    } else {
        $redirect = '/?msg=invalidFuckup';
    }

    return new Symfony\Component\HttpFoundation\RedirectResponse($redirect);

});

if ('development' != APP_ENV) {
    // @todo log errors
    // @todo make the error page prettier.
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
    $rangeEnd = ($page * 10) - 1;

    foreach ($app['predis']->lrange('global:fuckups', $rangeStart, $rangeEnd) as $fuckupId) {
        $fuckup = $app['predis']->get("fuckup:$fuckupId");
        $fuckup = json_decode($fuckup);
        $fuckup->id = $fuckupId;

        array_push($fuckups, $fuckup);
    }

    return $fuckups;
}

/**
 * Returns the total number of fuckups in the database.
 *
 * @param \Silex\Application $app
 * @return int
 */
function ft_count_fuckups($app)
{
    return $app['predis']->get('global:nextEntryId') - 1;
}

/**
 * Returns the total number of pages of fuckups in the database.
 *
 * @param \Silex\Application $app
 * @return int
 */
function ft_count_pages($app)
{
    return ceil(ft_count_fuckups($app) / 5);
}

/**
 * Get a fuckup flash message.
 *
 * @param string $key
 * @return string
 */
function ft_get_flash_message($key)
{
    $message = false;

    if ('invalidFuckup' == $key) {
        $message = 'You submitted an invalid fuckup. If you weren\'t already
            posting about yourself, you may as well do so now.';
    }

    return $message;
}