<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/FixtureLoaderInterface.php';

/**
 * Import fixtures into the MongoDB backend of jackalope
 */
class MongoDBFixtureLoader implements \PHPCR\Test\FixtureLoaderInterface
{
    private $dbname;
    private $fixturePath;

    public function __construct(\Doctrine\MongoDB\Database $db, $fixturePath)
    {
        $this->db = $db;
        $this->dbname = $this->db->getName();
        $this->fixturePath = $fixturePath;
    }

    public function import($file, $workspaceKey = 'workspace')
    {
        // FIXME
        $this->resetDb();

        $file = $this->fixturePath . $file . '.json';

        // FIXME
        exec('mongoimport --db ' . $this->dbname . ' --collection ' . \Jackalope\Transport\MongoDB\Client::COLLNAME_NODES . ' --type json --file ' . $file . ' --jsonArray 2>&1', $out);
    }

    private function resetDb()
    {
        $this->db->drop();

        $coll = $this->db->selectCollection(\Jackalope\Transport\MongoDB\Client::COLLNAME_WORKSPACES);
        $workspace = array(
            'name' => 'default'
        );
        $coll->insert($workspace);

        $coll = $this->db->selectCollection(\Jackalope\Transport\MongoDB\Client::COLLNAME_WORKSPACES);
        $workspace = array(
            '_id'  => new \MongoId('4e00e8fea381601b08000000'),
            'name' => $GLOBALS['phpcr.workspace']
        );
        $coll->insert($workspace);

        $coll = $this->db->selectCollection(\Jackalope\Transport\MongoDB\Client::COLLNAME_NODES);
        $node = array(
            'path'   => '/',
            'parent' => '-1',
            'w_id'   => new \MongoId('4e00e8fea381601b08000000'),
            'type'   => 'nt:unstructured',
            'props'  => array()
        );
        $coll->insert($node);
    }
}
