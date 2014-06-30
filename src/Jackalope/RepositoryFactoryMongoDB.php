<?php

namespace Jackalope;

use PHPCR\RepositoryFactoryInterface;

/**
 * This factory creates repositories with the MongoDB transport
 *
 * Use repository factory based on parameters (the parameters below are examples):
 *
 *    $factory = new \Jackalope\RepositoryFactoryMongoDB;
 *
 *    $parameters = array('jackalope.mongodb_database' => $db);
 *    $repo = $factory->getRepository($parameters);
 *
 * @api
 */
class RepositoryFactoryMongoDB implements RepositoryFactoryInterface
{
    static private $required = array(
        'jackalope.mongodb_database' => '\Doctrine\MongoDB\Database (required): mongodb database instance',
    );

    static private $optional = array(
        'jackalope.factory'                => 'string or object: Use a custom factory class for Jackalope objects',
        'jackalope.disable_transactions'   => 'boolean: If set and not empty, transactions are disabled, otherwise transactions are enabled',
        'jackalope.disable_stream_wrapper' => 'boolean: If set and not empty, stream wrapper is disabled, otherwise the stream wrapper is enabled',
    );

    /**
     * Attempts to establish a connection to a repository using the given
     * parameters.
     *
     *
     *
     * @param array|null $parameters string key/value pairs as repository arguments or null if a client wishes
     *                               to connect to a default repository.
     * @return \PHPCR\RepositoryInterface a repository instance or null if this implementation does
     *                                    not understand the passed parameters
     * @throws \PHPCR\RepositoryException if no suitable repository is found or another error occurs.
     * @api
     */
    public function getRepository(array $parameters = null)
    {
        if (null == $parameters) {
            return null;
        }

        // check if we have all required keys
        $present = array_intersect_key(self::$required, $parameters);
        if (count(array_diff_key(self::$required, $present))) {
            return null;
        }
        $defined = array_intersect_key(array_merge(self::$required, self::$optional), $parameters);
        if (count(array_diff_key($defined, $parameters))) {
            return null;
        }

        if (isset($parameters['jackalope.factory'])) {
            $factory = is_object($parameters['jackalope.factory']) ?
                $parameters['jackalope.factory'] :
                new $parameters['jackalope.factory'];
        } else {
            $factory = new Factory();
        }

        $db = $parameters['jackalope.mongodb_database'];

        $transport = $factory->get('Transport\MongoDB\Client', array($db));

        $options['transactions'] = empty($parameters['jackalope.disable_transactions']);
        $options['stream_wrapper'] = empty($parameters['jackalope.disable_stream_wrapper']);
        return new Repository($factory, $transport, $options);
    }

    /**
     * Get the list of configuration options that can be passed to getRepository
     *
     * The description string should include whether the key is mandatory or
     * optional.
     *
     * @return array hash map of configuration key => english description
     */
    public function getConfigurationKeys()
    {
        return array_merge(self::$required, self::$optional);
    }
}
