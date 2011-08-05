<?php

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

defined('ROOT_PATH') || define('ROOT_PATH', realpath(__DIR__ . '/../'));
defined('APP_ENV') || define('APP_ENV', (getenv('APP_ENV') ?: 'production'));

require_once ROOT_PATH . '/config.php';

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

if ('development' == APP_ENV) {
    $app['debug'] = true;
}

$app['autoloader']->registerNamespace('SilexExtension', ROOT_PATH . '/vendor/silex-extensions/src');
$app->register(new Silex\Extension\TwigExtension(), array(
    'twig.path' => ROOT_PATH . '/views',
    'twig.class_path' => ROOT_PATH . '/vendor/twig/lib',
));
$app->register(new Silex\Extension\DoctrineExtension(), array(
    'db.options' => array(
        'driver' => 'pdo_mysql',
        'dbname' => DB_NAME,
        'host' => DB_HOST,
        'user' => DB_USER,
        'password' => DB_PASS
    ),
    'db.dbal.class_path' => ROOT_PATH . '/vendor/doctrine-dbal/lib',
    'db.common.class_path' => ROOT_PATH . '/vendor/doctrine-common/lib'
));

/**
 * View an individual fuckup.
 */
$app->get('/view/{id}', function ($id) use ($app) {
    $fuckup = ft_find_fuckup($app, $id);

    return $app['twig']->render('view.twig', array(
        'fuckup' => $fuckup,
    ));
})->bind('view');

/**
 * LOLOL!!!1 Retweets a fuckup.
 */
$app->get('/view/{id}/retweet', function ($id) use ($app) {
    session_start();

    $fuckup = ft_find_fuckup($app, $id);

    $config = array(
        'callbackUrl' => sprintf('http://%s/view/%d/retweet',
            $app['request']->getHost(),
            $id),
        'siteUrl' => 'http://twitter.com/oauth',
        'consumerKey' => FT_TWITTER_KEY,
        'consumerSecret' => FT_TWITTER_SECRET,
    );

    require_once 'Zend/Oauth/Consumer.php';

    $consumer = new Zend_Oauth_Consumer($config);

    if (!isset($_SESSION['TWITTER_REQUEST_TOKEN'])
        && !isset($_SESSION['TWITTER_ACCESS_TOKEN'])
    ) {
        $token = $consumer->getRequestToken();
        $_SESSION['TWITTER_REQUEST_TOKEN'] = serialize($token);

        $consumer->redirect();
    } else if (!empty($_GET) && isset($_SESSION['TWITTER_REQUEST_TOKEN'])) {
        $token = $consumer->getAccessToken(
            $_GET,
            unserialize($_SESSION['TWITTER_REQUEST_TOKEN']));

        $_SESSION['TWITTER_ACCESS_TOKEN'] = serialize($token);
        $_SESSION['TWITTER_REQUEST_TOKEN'] = null;
    } else if (!isset($_SESSION['TWITTER_ACCESS_TOKEN'])) {
        throw new \Exception('Twitter access token was not present in retweet.');
    }

    $thisPage = 'http://infucktown.robzienert.com/view/' . $id;

    require_once 'Zend/Service/ShortUrl/TinyUrlCom.php';
    $tinyurl = new Zend_Service_ShortUrl_TinyUrlCom();

    // @todo Add support for making the "who" twitter-linkable.
    $statusMessage = sprintf(
        '%s %s in #FUCKTOWN %s %s',
        $fuckup['who'],
        $fuckup['verb'],
        $fuckup['fuckup'],
        $tinyurl->shorten($thisPage));

    $token = unserialize($_SESSION['TWITTER_ACCESS_TOKEN']);
    $client = $token->getHttpClient($config);
    $client->setUri('http://twitter.com/statuses/update.json');
    $client->setMethod(Zend_Http_Client::POST);
    $client->setParameterPost('status', $statusMessage);

    $response = $client->request();

    return new Symfony\Component\HttpFoundation\RedirectResponse(
        $thisPage . '?msg=retweet');
});

/**
 * RSS feed
 */
$app->get('/feed', function () use ($app) {
    $request = $app['request'];

    $fuckups = ft_find_fuckups($app);

    require_once 'Zend/Feed/Writer/Feed.php';

    $firstFuckup = current($fuckups);

    $feed = new Zend_Feed_Writer_Feed();
    $feed->setTitle('In FUCKTOWN');
    $feed->setDescription('Holy shit dude! A website to anonymously post other ' .
        'people&apos;s fuckups!');
    $feed->setDateModified(strtotime($firstFuckup['date_created']));

    $host = 'http://' . $request->getHost();
    $feed->setLink($host);
    $feed->setFeedLink($host . '/feed', 'rss');

    foreach ($fuckups as $fuckup) {
        $content = sprintf('%s %s in FUCKTOWN because %s',
                           $fuckup['who'],
                           $fuckup['verb'],
                           $fuckup['fuckup']);

        $entry = $feed->createEntry();
        $entry->setTitle(sprintf('%s is in FUCKTOWN', $fuckup['who']));
        $entry->addAuthor('InFucktown');
        $entry->setContent($content);
        $entry->setDateCreated(strtotime($fuckup['date_created']));
        $entry->setLink('http://infucktown.com/fuckup/' . $fuckup['fuckup_id']);

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

    return $app['twig']->render('index.twig', array(
        'fuckups' => ft_find_fuckups($app, $page),
        'message' => ft_get_flash_message($app['request']->query->get('msg')),
        'pagination' => array(
            'pages' => $pages,
            'current' => $page,
        ),
        'paginate' => ($pages > 1)
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
        'who' => $app->escape($request->get('who')),
        'verb' => $app->escape($verb),
        'fuckup' => $app->escape($request->get('fuckup')),
        'date_created' => date('Y-m-d H:i:s')
    );

    if (!empty($entry['who']) && !empty($entry['fuckup'])) {
        $app['db']->insert('fuckups', $entry);

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
        return new Response('<h2>I N F U C K C E P T I O N</h2>We are sorry, but
            something went terribly wrong and now InFucktown is InFucktown.', $code);
    });
}

// And finally run.
$app->run();

/**
 * Get a list of fuckups.
 *
 * @todo Add auto-pruning of ids in the list that do not reference a fuckup.
 *
 * @param \Silex\Application $app
 * @param int $page
 * @return array
 */
function ft_find_fuckups($app, $page = 1) {
    $fuckups = array();

    $rangeStart = ($page - 1) * PAGINATION_COUNT;
    $rangeEnd = ($page * PAGINATION_COUNT) - 1;
    
    $fuckups = $app['db']->fetchAll(
        "SELECT * FROM fuckups
        ORDER BY date_created DESC
        LIMIT $rangeStart, $rangeEnd
        ");

    return $fuckups;
}

/**
 * Find a single fuckup.
 *
 * @param \Silex\Application $app
 * @param int $id
 */
function ft_find_fuckup($app, $id)
{
    $sql = 'SELECT * FROM fuckups WHERE fuckup_id = ?';
    $fuckup = $app['db']->fetchAssoc($sql, array($id));

    if (!$fuckup) {
        throw new NotFoundHttpException('Could not find fuckup ' . $id);
    }

    return $fuckup;
}

/**
 * Returns the total number of fuckups in the database.
 *
 * @param \Silex\Application $app
 * @return int
 */
function ft_count_fuckups($app)
{
    return (int) $app['db']->fetchColumn('SELECT COUNT(1) FROM fuckups');
}

/**
 * Returns the total number of pages of fuckups in the database.
 *
 * @param \Silex\Application $app
 * @return int
 */
function ft_count_pages($app)
{
    return ceil(ft_count_fuckups($app) / PAGINATION_COUNT);
}

/**
 * Get a fuckup flash message.
 *
 * @param string $key
 * @return string
 */
function ft_get_flash_message($key)
{
    switch ($key) {
        case 'invalidFuckup':
            return 'You submitted an invalid fuckup. If you weren\'t already
                posting about yourself, you may as well do so now.';

        case 'retweet':
            return 'You have just brought more shame to this fuckup by
                retweeting it. Well done.';

        default:
            return false;
    }

    return $message;
}