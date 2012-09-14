<?php

namespace Jackalope\Transport\MongoDB;

use Jackalope\TestCase;
use Doctrine\MongoDB;

abstract class MongoDBTestCase extends TestCase
{
    protected $conn;

    protected function getConnection()
    {
        if ($this->conn === null) {
            $this->conn = new Connection($GLOBALS['phpcr.doctrine.mongodb.server']);
        }
        return $this->conn;
    }
}