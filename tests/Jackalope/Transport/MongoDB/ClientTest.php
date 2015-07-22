<?php

namespace Jackalope\Transport\MongoDB;

class ClientTest extends MongoDBTestCase
{
    /**
     * @dataProvider getBooleanData
     */
    public function testFetchBooleanPropertyValue($boolean, $expectedBoolean)
    {
        $client = $this->getMockBuilder('Jackalope\Transport\MongoDB\Client')
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $reflMethod = new \ReflectionMethod($client, 'fetchBooleanPropertyValue');
        $reflMethod->setAccessible(true);

        $this->assertEquals($expectedBoolean, $reflMethod->invokeArgs($client, array($boolean)));
    }

    public function getBooleanData()
    {
        return array(
            array(true, true),
            array('true', true),
            array("true", true),
            array('  true  ', true),
            array('TRUE', true),
            array('tRuE', true),
            array(" tRuE   \n", true),
            array(1, true),
            array(array('some data'), true),

            array(false, false),
            array('false', false),
            array("false", false),
            array('  false  ', false),
            array('FALSE', false),
            array('fAlSe', false),
            array(' fAlSe ', false),
            array(" fAlSe  \n", false),
            array('', false),
            array(0, false),
            array(null, false),
            array(array(), false),
        );
    }
}
