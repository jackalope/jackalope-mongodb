<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/AbstractLoader.php';

/**
 * Implementation loader for jackalope-mongodb
 */
class ImplementationLoader extends \PHPCR\Test\AbstractLoader
{
    private static $instance = null;

    private $necessaryConfigValues = array('phpcr.user', 'phpcr.pass', 'phpcr.workspace');

    protected function __construct()
    {
        // Make sure we have the necessary config
        foreach ($this->necessaryConfigValues as $val) {
            if (empty($GLOBALS[$val])) {
                die('Please set ' . $val . ' in your phpunit.xml.' . "\n");
            }
        }

        parent::__construct('Jackalope\RepositoryFactoryMongoDB');

        $this->unsupportedChapters = array(
            'Export',
            'Import',
            'NodeTypeManagement',
            'SameNameSiblings',
            'OrderableChildNodes',
            'Observation',         // TODO: Transport does not support observation
            'Versioning',          // TODO: Transport does not support versioning
            'Locking',             // TODO: Transport does not support locking
            'Transactions',        // TODO: Transport does not support transactions
        );

        $this->unsupportedCases = array(
            'Writing\\AddMethodsTest',
            'Writing\\CloneMethodsTest',
            'Writing\\CombinedManipulationsTest',
            'Writing\\CopyMethodsTest',
            'Writing\\SetPropertyTypesTest',
            'Writing\\SetPropertyDynamicRebindingTest',
            'Writing\\DeleteMethodsTest',
            'Writing\\SetPropertyMethodsTest',
            'Writing\\MoveMethodsTest',
            'Writing\\NodeTypeAssignementTest',
            'Writing\\EncodingTest',
            'Writing\\MixinReferenceableTest',
            'Writing\\ItemStateTest',
            'Writing\\LastModifiedTest',
            'Writing\\MixinCreatedTest',

            'Query\\XPath\QueryOperationsTest',
            'Query\\QuerySql2OperationsTest',
            'Query\\RowIteratorTest',
            'Query\\RowTest',
            'Query\\Sql1\QueryOperationsTest',
        );

        $this->unsupportedTests = array(
            'Connecting\\RepositoryTest::testLoginException',                 // TODO: figure out what would be invalid credentials
            'Connecting\\WorkspaceReadMethodsTest::testGetQueryManager',

            'Reading\\SessionReadMethodsTest::testImpersonate',               // TODO: Check if that's implemented in newer jackrabbit versions.
            'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix',
            'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced',     // TODO: should this be moved to 14_ShareableNodes

            'Reading\\BinaryReadMethodsTest::testReadBinaryValue',            // TODO MongoDB specific fixer-loading problem with binaries
            'Reading\\BinaryReadMethodsTest::testIterateBinaryValue',
            'Reading\\BinaryReadMethodsTest::testReadBinaryValueAsString',
            'Reading\\BinaryReadMethodsTest::testReadBinaryValues',
            'Reading\\BinaryReadMethodsTest::testReadBinaryValuesAsString',
            'Reading\\BinaryReadMethodsTest::testReadBinaryPathEncoding',
            'Reading\\BinaryReadMethodsTest::testReadBinaryPathTrailingQuestionmark',
            'Reading\\PropertyReadMethodsTest::testGetBinary',
            'Reading\\PropertyReadMethodsTest::testGetBinaryMulti',

            'Query\\QueryManagerTest::testGetQuery',
            'Query\\QueryManagerTest::testGetQueryInvalid',
            'Query\\NodeViewTest::testSeekable',
            'Query\\NodeViewTest::testSeekableOutOfBounds',
            'Query\\QueryObjectSql2Test::testGetStoredQueryPath',
            'Query\\QueryObjectSql2Test::testExecuteLimit',
            'Query\\QueryObjectSql2Test::testExecuteOffset',
            'Query\\QueryObjectSql2Test::testExecuteLimitAndOffset',
            'Query\\QueryResultsTest::testGetColumnNames',
            'Query\\QueryResultsTest::testGetAliasColumnNames',
            'Query\\QueryResultsTest::testGetSelectorNames',
            'Query\\QueryResultsTest::testGetNodes',
            'Query\\QueryResultsTest::testIterateOverQueryResult',
            'Query\\QueryResultsTest::testReadPropertyContentFromResults',
            'Query\\QueryResultsTest::testCompareNumberFields',
            'Query\\QueryResultsTest::testCompareStringFields',
            'Query\\QueryResultsTest::testBooleanField',
            'Query\\CharacterTest::testPropertyWithBackslash',
            'Query\\CharacterTest::testPropertyWithDoubleBackslash',
            'Query\\CharacterTest::testPropertyWithQuotes',
            'Query\\CharacterTest::testPropertyWithQuotesAndBackslash',
            'Query\\CharacterTest::testQueryWithAmpersand',
            'Query\\NodeViewTest::testIterator',
            'Query\\QueryObjectQOMTest::testExecute',
            'Query\\QueryObjectQOMTest::testExecuteInvalid',


            'Writing\\NamespaceRegistryTest::testRegisterUnregisterNamespace',

            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithInvalidSource',

            'PhpcrUtils\\PurgeTest::testPurge',

            'PermissionsAndCapabilities\\PermissionReadTest::testCheckPermissionAccessControlException',
        );
    }

    /**
     * Make the repository ready for login with null credentials, handling the
     * case where authentication is passed outside the login method.
     *
     * If the implementation does not support this feature, it must return
     * false for this method, otherwise true.
     *
     * @return boolean true if anonymous login is supposed to work
     */
    public function prepareAnonymousLogin()
    {
        return true;
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new ImplementationLoader();
        }

        return self::$instance;
    }

    public function getRepositoryFactoryParameters()
    {
        global $db; // initialized in bootstrap.php

        return array('jackalope.mongodb_database' => $db);
    }

    public function getCredentials()
    {
        return new \PHPCR\SimpleCredentials($GLOBALS['phpcr.user'], $GLOBALS['phpcr.pass']);
    }

    public function getInvalidCredentials()
    {
        return new \PHPCR\SimpleCredentials('nonexistinguser', '');
    }

    public function getRestrictedCredentials()
    {
        return new \PHPCR\SimpleCredentials('anonymous', 'abc');
    }

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    public function getFixtureLoader()
    {
        global $db; // initialized in bootstrap.php
        require_once 'MongoDBFixtureLoader.php';

        return new \MongoDBFixtureLoader($db, __DIR__ . "/../fixtures/mongodb/");
    }
}
