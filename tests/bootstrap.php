<?php

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {
    die('You must set up the project dependencies, run the following commands:' . PHP_EOL .
        'curl -s http://getcomposer.org/installer | php' . PHP_EOL .
        'php composer.phar install' . PHP_EOL .
        'php composer.phar install --dev' . PHP_EOL);
}

$loader->add('Jackalope', __DIR__.'/../vendor/jackalope/jackalope/tests');

/** make sure we get ALL infos from php */
error_reporting(E_ALL | E_STRICT);

### Load classes needed for jackalope unit tests ###
require 'Jackalope/Transport/MongoDB/MongoDBTestCase.php';

### Load the implementation loader class ###
require 'inc/MongoDBImplementationLoader.php';

$dbConn = new \Doctrine\MongoDB\Connection($GLOBALS['phpcr.doctrine.mongodb.server']);
$db = $dbConn->selectDatabase($GLOBALS['phpcr.doctrine.mongodb.dbname']);

/** some constants */

define('SPEC_VERSION_DESC', 'jcr.specification.version');
define('SPEC_NAME_DESC', 'jcr.specification.name');
define('REP_VENDOR_DESC', 'jcr.repository.vendor');
define('REP_VENDOR_URL_DESC', 'jcr.repository.vendor.url');
define('REP_NAME_DESC', 'jcr.repository.name');
define('REP_VERSION_DESC', 'jcr.repository.version');
define('OPTION_TRANSACTIONS_SUPPORTED', 'option.transactions.supported');
define('OPTION_VERSIONING_SUPPORTED', 'option.versioning.supported');
define('OPTION_OBSERVATION_SUPPORTED', 'option.observation.supported');
define('OPTION_LOCKING_SUPPORTED', 'option.locking.supported');
