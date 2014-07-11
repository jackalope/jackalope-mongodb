<?php

/**
 * Class to handle the communication between Jackalope and MongoDB.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0', January 2004
 *   Licensed under the Apache License, Version 2.0 (the "License") {}
 *   you may not use this file except in compliance with the License.
 *   You may obtain a copy of the License at
 *
 *       http://www.apache.org/licenses/LICENSE-2.0
 *
 *   Unless required by applicable law or agreed to in writing, software
 *   distributed under the License is distributed on an "AS IS" BASIS,
 *   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *   See the License for the specific language governing permissions and
 *   limitations under the License.
 *
 * @package jackalope
 * @subpackage transport
 */

namespace Jackalope\Transport\MongoDB;

use PHPCR\PropertyType;
use PHPCR\RepositoryException;
use PHPCR\Util\UUIDHelper;

use Jackalope\Node;
use Jackalope\Property;
use Jackalope\Transport\BaseTransport;
use Jackalope\Transport\TransportInterface;
use Jackalope\Transport\WritingInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\Transport\NodeTypeManagementInterface;
use Jackalope\Transport\StandardNodeTypes;
use Jackalope\NodeType\NodeType;
use Jackalope\NotImplementedException;

/**
 * @author Thomas Schedler <thomas@chirimoya.at>
 */
class Client
    extends BaseTransport
    implements TransportInterface,
               WritingInterface,
               WorkspaceManagementInterface,
               NodeTypeManagementInterface
{
    /**
     * Moves a node from src to dst outside of a transaction
     *
     * @param string $srcAbsPath Absolute source path to the node
     * @param string $dstAbsPath Absolute destination path (must NOT include
     *                           the new node name)
     *
     * @link http://www.ietf.org/rfc/rfc2518.txt
     *
     * @see \Jackalope\Workspace::moveNode
     */
    public function moveNodeImmediately($srcAbsPath, $dstAbsPath)
    {
        // TODO: Implement moveNodeImmediately() method.
    }

    /**
     * Get the nodes from an array of uuid
     *
     * This is an optimization over getNodeByIdentifier to get many nodes in
     * one call. If the transport implementation does not optimize, it can just
     * loop over the uuids and call getNodeByIdentifier repeatedly
     *
     * @param array $identifiers list of uuid to retrieve
     *
     * @return array keys are the absolute paths, values is the node data as
     *               associative array (decoded from json with associative = true). they
     *               will have the identifier value set.
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNodesByIdentifier($identifiers)
    {
        $this->assertLoggedIn();

        $binaryIdentifiers = array();
        foreach ($identifiers as $identifier) {
            $binaryIdentifiers[] = new \MongoBinData($identifier, \MongoBinData::UUID);
        }

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll
            ->createQueryBuilder()
            ->field('w_id')->equals($this->workspaceId)
            ->field('_id')->in($binaryIdentifiers)
        ;
        $queryResult = $qb->getQuery()->execute();

        $nodes = array();
        foreach ($queryResult as $entry) {
            $nodes[$entry['path']] = $this->getNode($entry['path']);
        }

        return $nodes;
    }

    /**
     * Get the node from a uuid. Same data format as getNode, but additionally
     * must have the :jcr:path property
     *
     * @param string $uuid the id in JCR format
     *
     * @return array associative array for the node (decoded from json with
     *               associative = true)
     *
     * @throws \PHPCR\ItemNotFoundException    if the backend does not know the
     *                                         uuid
     * @throws \PHPCR\NoSuchWorkspaceException if workspace does not exist
     * @throws \LogicException                 if not logged in
     */
    public function getNodeByIdentifier($uuid)
    {
        $this->assertLoggedIn();

        if (!$this->isWorkspaceAvailable()) {
            throw new \PHPCR\NoSuchWorkspaceException();
        }

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll
            ->createQueryBuilder()
            ->field('w_id')->equals($this->workspaceId)
            ->field('_id')->equals(new \MongoBinData($uuid, \MongoBinData::UUID))
        ;
        $entry = $qb->getQuery()->getSingleResult();

        if (!$entry || !array_key_exists('path', $entry) || !$node = $this->getNode($entry['path'])) {
            throw new \PHPCR\ItemNotFoundException();
        }

        return $node;
    }

    /**
     * Deletes the workspace with the specified name from the repository,
     * deleting all content within it
     *
     * @param string $name The name of the workspace
     *
     * @throws \PHPCR\UnsupportedRepositoryOperationException if the repository
     *                                                        does not support the deletion of workspaces
     * @throws \PHPCR\RepositoryException                     if another error occurs
     * @throws \PHPCR\RepositoryException                     if not logged in
     */
    public function deleteWorkspace($name)
    {
        $this->assertLoggedIn();

        $descriptors = $this->getRepositoryDescriptors();
        $supports = array_key_exists('option.workspace.management.supported', $descriptors) &&
                    true === $descriptors['option.workspace.management.supported']
        ;
        if (!$supports) {
            throw new \PHPCR\UnsupportedRepositoryOperationException();
        }

        $workspaceColl = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        $nodeColl = $this->db->selectCollection(self::COLLNAME_NODES);

        $qb = $workspaceColl->createQueryBuilder()
            ->field('name')->equals($name)
        ;
        $workspace = $qb->getQuery()->getSingleResult();
        if (!$workspace) {
            throw new \PHPCR\RepositoryException(sprintf('Workspace "%s" cannot be deleted as it does not exist', $name));
        }

        $deleteNodesQuery = $nodeColl->createQueryBuilder()
            ->field('w_id')->equals($workspace['_id'])
            ->remove()
            ->multiple(true)
        ;
        try {
            $deleteNodesQuery->getQuery()->execute();
        } catch (\Exception $e) {
            throw new \PHPCR\RepositoryException(
                sprintf('Could not delete nodes in workspace "%s": "%s"', $name, $e->getMessage()),
                0,
                $e
            );
        }

        $deleteWorkspaceQuery = $workspaceColl->createQueryBuilder()
            ->field('name')->equals($name)
            ->remove()
        ;
        try {
            $deleteWorkspaceQuery->getQuery()->execute();
        } catch (\Exception $e) {
            throw new \PHPCR\RepositoryException(
                sprintf('Could not delete already empty workspace "%s": "%s"', $name, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Update a node and its children to match its corresponding node in the specified workspace
     *
     * @param Node   $node         the node to update
     * @param string $srcWorkspace The workspace where the corresponding source node can be found
     */
    public function updateNode(Node $node, $srcWorkspace)
    {
        // TODO: Implement updateNode() method.
    }

    /**
     * Perform a batch of move operations in the order of the passed array
     *
     * @param \Jackalope\Transport\MoveNodeOperation[] $operations
     */
    public function moveNodes(array $operations)
    {
        // TODO: Implement moveNodes() method.
    }

    /**
     * Reorder the children of $node as the node said it needs them reordered
     *
     * You can either get the reordering list with getOrderCommands or use
     * getNodeNames to get the absolute order
     *
     * @param Node $node the node to reorder its children
     */
    public function reorderChildren(Node $node)
    {
        // TODO: Implement reorderChildren() method.
    }

    /**
     * Perform a batch remove operation
     *
     * Take care that cyclic REFERENCE properties of to be deleted nodes do not
     * lead to errors
     *
     * @param \Jackalope\Transport\RemoveNodeOperation[] $operations
     */
    public function deleteNodes(array $operations)
    {
        // TODO: Implement deleteNodes() method.
    }

    /**
     * Perform a batch remove operation
     *
     * @param \Jackalope\Transport\RemovePropertyOperation[] $operations
     */
    public function deleteProperties(array $operations)
    {
        // TODO: Implement deleteProperties() method.
    }

    /**
     * Deletes a node and the whole subtree under it outside of a transaction
     *
     * @param string $path Absolute path to the node
     *
     * @see \Jackalope\Workspace::removeItem
     *
     * @throws \PHPCR\PathNotFoundException if the item is already deleted on
     *                                      the server. This should not happen if ObjectManager is correctly
     *                                      checking
     * @throws \PHPCR\RepositoryException   if not logged in or another error occurs
     */
    public function deleteNodeImmediately($path)
    {
        // TODO: Implement deleteNodeImmediately() method.
    }

    /**
     * Deletes a property outside of a transaction
     *
     * @param string $path Absolute path to the property
     *
     * @see \Jackalope\Workspace::removeItem
     *
     * @throws \PHPCR\PathNotFoundException if the item is already deleted on
     *                                      the server. This should not happen if ObjectManager is correctly
     *                                      checking
     * @throws \PHPCR\RepositoryException   if not logged in or another error occurs
     */
    public function deletePropertyImmediately($path)
    {
        // TODO: Implement deletePropertyImmediately() method.
    }

    /**
     * Store all nodes in the AddNodeOperations
     *
     * Transport stores the node at its path, with all properties (but do not
     * store children)
     *
     * The transport is responsible to ensure that the node is valid and
     * has to generate autocreated properties
     *
     * Note: Nodes in the log may be deleted if they are deleted. The delete
     * request will be passed later, according to the log. You should still
     * create it here as it might be used temporarily in move operations or
     * such. Use Node::getPropertiesForStoreDeletedNode in that case to avoid
     * a status check of the deleted node
     *
     * @see BaseTransport::validateNode
     *
     * @param \Jackalope\Transport\AddNodeOperation[] $operations the operations containing the nodes to store
     *
     * @throws \PHPCR\RepositoryException if not logged in or another error occurs
     */
    public function storeNodes(array $operations)
    {
        // TODO: Implement storeNodes() method.
    }

    /**
     * Update the properties of a node
     *
     * @param Node $node the node to update
     */
    public function updateProperties(Node $node)
    {
        // TODO: Implement updateProperties() method.
    }

    /**
     * Called before any data is written
     */
    public function prepareSave()
    {
        // TODO: Implement prepareSave() method.
    }

    /**
     * Called if a save operation caused an exception
     */
    public function rollbackSave()
    {
        // TODO: Implement rollbackSave() method.
    }

    /**
     * Name of MongoDB workspace collection
     *
     * @var string
     */
    const COLLNAME_WORKSPACES = 'phpcr_workspaces';

    /**
     * Name of MongoDB namespace collection
     *
     * @var string
     */
    const COLLNAME_NAMESPACES = 'phpcr_namespaces';

    /**
     * Name of MongoDB node collection
     *
     * @var string
     */
    const COLLNAME_NODES = 'phpcr_nodes';

    /**
     * @var \Jackalope\Factory
     */
    protected $factory;

    /**
     * @var bool
     */
    protected $loggedIn = false;

    /**
     * @var \PHPCR\SimpleCredentials
     */
    private $credentials;

    /**
     * Check if an initial request on login should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    private $checkLoginOnServer = true;

    /**
     * @var int|string
     */
    protected $workspaceId;

    /**
     * @var string
     */
    private $workspaceName;

    /**
     * @var \PHPCR\NodeType\NodeTypeManagerInterface
     */
    protected $nodeTypeManager = null;

    /**
     * @var array
     */
    protected $namespaces = null;

    /**
     * @var bool
     */
    private $fetchedNamespaces = false;

    /**
     * @var Doctrine\MongoDB\Database
     */
    private $db;

    /**
     * Create a MongoDB transport layer
     *
     * @param object                     $factory An object factory implementing "get" as described in \Jackalope\Factory
     * @param \Doctrine\MongoDB\Database $db
     */
    public function __construct($factory, \Doctrine\MongoDB\Database $db)
    {
        $this->factory = $factory;
        $this->db = $db;
    }

    // TransportInterface //

    /**
     * {@inheritDoc}
     */
    public function getRepositoryDescriptors()
    {
        return array(
            'identifier.stability'                                      => \PHPCR\RepositoryInterface::IDENTIFIER_STABILITY_INDEFINITE_DURATION,
            'jcr.repository.name'                                       => 'jackalope_mongodb',
            'jcr.repository.vendor'                                     => 'Jackalope Community',
            'jcr.repository.vendor.url'                                 => 'http://github.com/jackalope',
            'jcr.repository.version'                                    => '1.0.0-DEV',
            'jcr.specification.name'                                    => 'Content Repository for PHP',
            'jcr.specification.version'                                 => false,
            'level.1.supported'                                         => false,
            'level.2.supported'                                         => false,
            'node.type.management.autocreated.definitions.supported'    => true,
            'node.type.management.inheritance'                          => true,
            'node.type.management.multiple.binary.properties.supported' => true,
            'node.type.management.multivalued.properties.supported'     => true,
            'node.type.management.orderable.child.nodes.supported'      => false,
            'node.type.management.overrides.supported'                  => false,
            'node.type.management.primary.item.name.supported'          => true,
            'node.type.management.property.types'                       => true,
            'node.type.management.residual.definitions.supported'       => false,
            'node.type.management.same.name.siblings.supported'         => false,
            'node.type.management.update.in.use.suported'               => false,
            'node.type.management.value.constraints.supported'          => false,
            'option.access.control.supported'                           => false,
            'option.activities.supported'                               => false,
            'option.baselines.supported'                                => false,
            'option.journaled.observation.supported'                    => false,
            'option.lifecycle.supported'                                => false,
            'option.locking.supported'                                  => false,
            'option.node.and.property.with.same.name.supported'         => false,
            'option.node.type.management.supported'                     => true,
            'option.observation.supported'                              => false,
            'option.query.sql.supported'                                => false,
            'option.retention.supported'                                => false,
            'option.shareable.nodes.supported'                          => false,
            'option.simple.versioning.supported'                        => false,
            'option.transactions.supported'                             => true,
            'option.unfiled.content.supported'                          => true,
            'option.update.mixin.node.types.supported'                  => true,
            'option.update.primary.node.type.supported'                 => true,
            'option.versioning.supported'                               => false,
            'option.workspace.management.supported'                     => true,
            'option.xml.export.supported'                               => false,
            'option.xml.import.supported'                               => false,
            'query.full.text.search.supported'                          => false,
            'query.joins'                                               => false,
            'query.languages'                                           => '',
            'query.stored.queries.supported'                            => false,
            'query.xpath.doc.order'                                     => false,
            'query.xpath.pos.index'                                     => false,
            'write.supported'                                           => true,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);

        $workspaces = $coll->find();

        $names = array();
        foreach ($workspaces AS $workspace) {
            $names[] = $workspace['name'];
        }

        return $names;
    }

    /**
     * {@inheritDoc}
     */
    public function login(\PHPCR\CredentialsInterface $credentials = null, $workspaceNameRaw = 'default')
    {
        $workspaceName = null === $workspaceNameRaw ? 'default' : $workspaceNameRaw;

        $this->credentials = $credentials;
        $this->workspaceName = $workspaceName;

        if (!$this->checkLoginOnServer) {
            return true;
        }

        $this->workspaceId = $this->getWorkspaceId($workspaceName);

        // create default workspace if it not exists
        if (!$this->workspaceId && 'default' === $workspaceName) {
            $this->createWorkspace($workspaceName);
            $this->workspaceId = $this->getWorkspaceId($workspaceName);
        }

        if (!$this->workspaceId) {
            throw new \PHPCR\NoSuchWorkspaceException("Requested workspace: $workspaceName");
        }

        $this->loggedIn = true;

        return $workspaceName;
    }

    /**
     * Assert logged in
     *
     * @return void
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    protected function assertLoggedIn()
    {
        if (!$this->loggedIn) {
            throw new RepositoryException();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function logout()
    {
        $this->loggedIn = false;
    }

    /**
     * {@inheritDoc}
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if ($this->fetchedNamespaces === false) {
            $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
            $namespaces = $coll->find();

            $this->fetchedNamespaces = true;

            $this->namespaces = array(
                \PHPCR\NamespaceRegistryInterface::PREFIX_EMPTY => \PHPCR\NamespaceRegistryInterface::NAMESPACE_EMPTY,
                \PHPCR\NamespaceRegistryInterface::PREFIX_JCR => \PHPCR\NamespaceRegistryInterface::NAMESPACE_JCR,
                \PHPCR\NamespaceRegistryInterface::PREFIX_NT => \PHPCR\NamespaceRegistryInterface::NAMESPACE_NT,
                \PHPCR\NamespaceRegistryInterface::PREFIX_MIX => \PHPCR\NamespaceRegistryInterface::NAMESPACE_MIX,
                \PHPCR\NamespaceRegistryInterface::PREFIX_XML => \PHPCR\NamespaceRegistryInterface::NAMESPACE_XML,
                \PHPCR\NamespaceRegistryInterface::PREFIX_SV => \PHPCR\NamespaceRegistryInterface::NAMESPACE_SV,
                'phpcr' => 'http://github.com/jackalope/jackalope', // TODO: Namespace?
            );

            foreach ($namespaces as $namespace) {
                $this->namespaces[$namespace['prefix']] = $namespace['uri'];
            }
        }

        return $this->namespaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        $this->assertLoggedIn();
        $path = $this->validatePath($path);

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll->createQueryBuilder()
            ->field('path')->equals($path)
            ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();
        $node = $query->getSingleResult();

        if (!$node) {
            throw new \PHPCR\ItemNotFoundException('Item ' . $path . ' not found.');
        }

        $data = new \stdClass();

        $data->{'jcr:primaryType'} = $node['type'];

        foreach ($node['props'] as $prop) {
            $name = $prop['name'];
            $type = $prop['type'];

            if ($type == \PHPCR\PropertyType::TYPENAME_BINARY) {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $data->{":" . $name}[] = $value;
                    }
                } else {
                    $data->{":" . $name} = $prop['value'];
                }
            } elseif ($type == \PHPCR\PropertyType::TYPENAME_DATE) {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $date = new \DateTime(date('Y-m-d H:i:s', $value['date']->sec), new \DateTimeZone($value['timezone']));
                        $data->{$name}[] = $date->format('c');
                    }
                } else {
                    $date = new \DateTime(date('Y-m-d H:i:s', $prop['value']['date']->sec), new \DateTimeZone($prop['value']['timezone']));
                    $data->{$name} = $date->format('c');
                }

                $data->{":" . $name} = $type;
            } elseif ($type == \PHPCR\PropertyType::TYPENAME_BOOLEAN) {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    $booleanValue = array();
                    foreach ($prop['value'] as $booleanEntry)
                        $booleanValue[] = $this->fetchBooleanPropertyValue($booleanEntry);
                    ;
                } else {
                    $booleanValue = $this->fetchBooleanPropertyValue($prop['value']);
                }

                $data->{$name} = $booleanValue;
                $data->{":" . $name} = $type;
            } else {
                if (isset($prop['multi']) && $prop['multi'] == true) {
                    foreach ($prop['value'] as $value) {
                        $data->{$name}[] = $value;
                    }
                } else {
                    $data->{$name} = $prop['value'];
                }
                $data->{":" . $name} = $type;
            }
        }

        // If the node is referenceable, return jcr:uuid.
        if (isset($data->{"jcr:mixinTypes"})) {
            foreach ((array) $data->{"jcr:mixinTypes"} as $mixin) {
                if ($this->nodeTypeManager->getNodeType($mixin)->isNodeType('mix:referenceable')) {
                    if ($node['_id'] instanceof \MongoBinData) {
                        $data->{'jcr:uuid'} = $node['_id']->bin;
                        break;
                    }
                }
            }
        }

        $qb = $coll->createQueryBuilder()
            ->field('parent')->equals($path)
            ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();
        $children = $query->getIterator();

        foreach ($children AS $child) {
            $childName = explode('/', $child['path']);
            $childName = end($childName);
            $data->{$childName} = new \stdClass();
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        $nodes = array();
        foreach ($paths as $key => $path) {
            try {
                $nodes[$key] = $this->getNode($path);
            } catch (\PHPCR\ItemNotFoundException $e) {
                // ignore ??
            }
        }

        return $nodes;
    }

    /**
     * {@inheritDoc}
     */
    public function getProperty($path)
    {
        throw new NotImplementedException('Getting properties by path is implemented yet.');
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        $this->assertLoggedIn();

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);

        $qb = $coll->createQueryBuilder()
            ->field('_id')->equals(new \MongoBinData($uuid, \MongoBinData::UUID))
            ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();
        $node = $query->getSingleResult();

        if (empty($node)) {
            throw new \PHPCR\ItemNotFoundException('No item found with uuid ' . $uuid . '.');
        }

        return $node['path'];
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryStream($path)
    {
        $grid = $this->db->getGridFS();
        $binary = $grid->getMongoCollection()->findOne(
            array(
                 'path' => $path,
                 'w_id' => $this->workspaceId
            )
        );

        if (empty($binary)) {
            throw new \PHPCR\ItemNotFoundException('Binary ' . $path . ' not found.');
        }

        // TODO: OPTIMIZE stream handling!
        $stream = fopen('php://memory', 'rwb+');
        fwrite($stream, $binary->getBytes());
        rewind($stream);

        return $stream;
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, false);
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * Returns the path of all accessible reference properties in the workspace that point to the node
     * If $weakReference is false (default) only the REFERENCE properties are returned, if it is true, only WEAKREFERENCEs
     *
     * @param  string  $path
     * @param  string  $name          name of referring WEAKREFERENCE properties to be returned; if null then all referring WEAKREFERENCEs are returned
     * @param  boolean $weakReference If true return only WEAKREFERENCEs, otherwise only REFERENCEs
     * @return array
     *
     * @throws \PHPCR\ItemNotFoundException
     */
    private function getNodeReferences($path, $name = null, $weakReference = false)
    {
        $path = $this->validatePath($path);
        $type = $weakReference ? \PHPCR\PropertyType::TYPENAME_WEAKREFERENCE : \PHPCR\PropertyType::TYPENAME_REFERENCE;

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $qb = $coll->createQueryBuilder()
            ->select('_id')
            ->field('path')->equals($path)
            ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();
        $node = $query->getSingleResult();

        if (empty($node)) {
            throw new \PHPCR\ItemNotFoundException('Item ' . $path . ' not found.');
        }

        $qb = $coll->createQueryBuilder()
            ->field('props.type')->equals($type)
            ->field('props.value')->equals($node['_id']->bin)
            ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();

        $nodes = $query->getIterator();

        $references = array();
        foreach ($nodes as $node) {
            foreach ($node['props'] as $property) {
                if ($property['type'] == $type) {
                    if ($name === null || $property['name'] == $name) {
                        $references[] = $node['path'] . '/' . $property['name'];
                    }
                }
            }
        }

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $standardTypes = array();
        foreach (StandardNodeTypes::getNodeTypeData() as $nodeTypeData) {
            $standardTypes[$nodeTypeData['name']] = $nodeTypeData;
        }
        $userTypes = $this->fetchUserNodeTypes();

        if ($nodeTypes) {
            $nodeTypes = array_flip($nodeTypes);
            // TODO: check if user types can override standard types.
            return array_values(array_intersect_key($standardTypes, $nodeTypes) + array_intersect_key($userTypes, $nodeTypes));
        }

        return array_values($standardTypes + $userTypes);
    }

    /**
     * Fetch all user-defined node-type definition
     *
     * @return array
     */
    private function fetchUserNodeTypes()
    {
        // TODO
        return array();
    }

    // QueryInterface //

    /**
     * {@inheritDoc}
     */
    public function query(\PHPCR\Query\QueryInterface $query)
    {
        switch ($query->getLanguage()) {
            case \PHPCR\Query\QueryInterface::JCR_SQL2:
                throw new \Jackalope\NotImplementedException('JCQ-JCR_SQL2 not yet implemented.');
            case \PHPCR\Query\QueryInterface::JCR_JQOM:
                throw new \Jackalope\NotImplementedException('JCQ-JQOM not yet implemented.');
                break;
        }
    }

    // WritingInterface //

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        $this->assertLoggedIn();

        $srcAbsPath = $this->validatePath($srcAbsPath);
        $dstAbsPath = $this->validatePath($dstAbsPath);

        $workspaceId = $this->workspaceId;
        if (null !== $srcWorkspace) {
            $workspaceId = $this->getWorkspaceId($srcWorkspace);
            if ($workspaceId === false) {
                throw new \PHPCR\NoSuchWorkspaceException('Source workspace "' . $srcWorkspace . '" does not exist.');
            }
        }

        if (substr($dstAbsPath, -1, 1) == "]") {
            // TODO: Understand assumptions of CopyMethodsTest::testCopyInvalidDstPath more
            throw new \PHPCR\RepositoryException('Invalid destination path');
        }

        if (!$this->pathExists($srcAbsPath)) {
            throw new \PHPCR\PathNotFoundException('Source path "' . $srcAbsPath . '" not found');
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new \PHPCR\ItemExistsException('Cannot copy to destination path "' . $dstAbsPath . '" that already exists.');
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new \PHPCR\PathNotFoundException('Parent of the destination path "' . $this->getParentPath($dstAbsPath) . '" has to exist.');
        }

        try {

            $regex = new \MongoRegex('/^' . addcslashes($srcAbsPath, '/') . '/');

            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                ->field('path')->equals($regex)
                ->field('w_id')->equals($workspaceId);

            $query = $qb->getQuery();
            $nodes = $query->getIterator();

            foreach ($nodes as $node) {
                $newPath = str_replace($srcAbsPath, $dstAbsPath, $node['path']);
                $uuid = UUIDHelper::generateUUID();

                $node['_id'] = new \MongoBinData($uuid, \MongoBinData::UUID);
                $node['path'] = $newPath;
                $node['parent'] = $this->getParentPath($newPath);
                $node['w_id'] = $this->workspaceId;

                $coll->insert($node);
            }

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new \Jackalope\NotImplementedException('Not implemented yet.');
    }

    /**
     * {@inheritDoc}
     */
    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        $this->assertLoggedIn();

        $srcAbsPath = $this->validatePath($srcAbsPath);
        $dstAbsPath = $this->validatePath($dstAbsPath);

        if (!$this->pathExists($srcAbsPath)) {
            throw new \PHPCR\PathNotFoundException('Source path "' . $srcAbsPath . '" not found');
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new \PHPCR\ItemExistsException('Cannot copy to destination path "' . $dstAbsPath . '" that already exists.');
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new \PHPCR\PathNotFoundException('Parent of the destination path "' . $this->getParentPath($dstAbsPath) . '" has to exist.');
        }

        try {

            $regex = new \MongoRegex('/^' . addcslashes($srcAbsPath, '/') . '/');

            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                ->field('path')->equals($regex)
                ->field('w_id')->equals($this->workspaceId);

            $query = $qb->getQuery();
            $nodes = $query->getIterator();

            foreach ($nodes as $node) {
                $newPath = str_replace($srcAbsPath, $dstAbsPath, $node['path']);

                $node['path'] = $newPath;
                $node['parent'] = $this->getParentPath($newPath);

                $coll->save($node);
            }

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reorderNodes($absPath, $reorders)
    {
        $this->assertLoggedIn();

        throw new NotImplementedException("Moving nodes is not yet implemented");
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNode($path)
    {
        $path = $this->validatePath($path);
        $this->assertLoggedIn();

        if (!$this->pathExists($path)) {
            $this->deleteProperty($path);
        } else {

            // TODO check sub-node references!
            if (count($this->getReferences($path)) > 0) {
                throw new \PHPCR\ReferentialIntegrityException('Cannot delete item at path "' . $path . '", ' .
                    'there is at least one item with a reference to this or a subnode of the path.');

                return false;
            }

            try {

                $regex = new \MongoRegex('/^' . addcslashes($path, '/') . '/');

                $coll = $this->db->selectCollection(self::COLLNAME_NODES);
                $qb = $coll->createQueryBuilder()
                    ->remove()
                    ->field('path')->equals($regex)
                    ->field('w_id')->equals($this->workspaceId);
                $query = $qb->getQuery();

                return $query->execute();
            } catch (\Exception $e) {
                return false;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperty($path)
    {
        $this->assertLoggedIn();

        $path = $this->validatePath($path);
        $parentPath = $this->getParentPath($path);

        $name = trim(str_replace($parentPath, '', $path), '/');

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);

        $qb = $coll->createQueryBuilder()
            ->select('_id')
            ->field('props.name')->equals($name)
            ->field('path')->equals($parentPath)
            ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();

        $property = $query->getSingleResult();

        if (!$property) {
            throw new \PHPCR\ItemNotFoundException("Property " . $path . " not found.");
        }

        $qb = $coll->createQueryBuilder()
            ->update()
            ->field('props')->pull(array('name' => $name))
            ->field('path')->equals($parentPath)
            ->field('w_id')->equals($this->workspaceId);
        $query = $qb->getQuery();

        return $query->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function storeNode(Node $node, $saveChildren = true)
    {
        $this->assertLoggedIn();

        $nodeTypes = $this->getResponsibleNodeTypes($node);
        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            $this->validateNode($node, $nodeType);
        }

        $properties = $node->getProperties();

        $path = $node->getPath();
        if (isset($this->nodeIdentifiers[$path])) {
            $nodeIdentifier = $this->nodeIdentifiers[$path];
        } elseif (isset($properties['jcr:uuid'])) {
            $nodeIdentifier = $properties['jcr:uuid']->getValue();
        } else {
            // we always generate a uuid, even for non-referenceable nodes that have no automatic uuid
            $nodeIdentifier = UUIDHelper::generateUUID();
        }
        $type = isset($properties['jcr:primaryType']) ? $properties['jcr:primaryType']->getValue() : "nt:unstructured";

        try {

            $props = array();
            foreach ($properties AS $property) {
                $data = $this->decodeProperty($property);
                if (!empty($data)) {
                    $props[] = $data;
                }
            }

            $data = array(
                '_id'    => new \MongoBinData($nodeIdentifier, \MongoBinData::UUID),
                'path'   => $path,
                'parent' => $this->getParentPath($path),
                'w_id'   => $this->workspaceId,
                'type'   => $type,
                'props'  => $props
            );

            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            if (!$this->pathExists($path)) {
                $coll->insert($data);
            } else {
                $qb = $coll->createQueryBuilder()
                    ->update()
                    ->setNewObj($data)
                    ->field('path')->equals($path)
                    ->field('w_id')->equals($this->workspaceId);
                $query = $qb->getQuery();
                $query->execute(); // FIXME use _id for update?
            }

        } catch (\Exception $e) {
            throw new \PHPCR\RepositoryException('Storing node ' . $node->getPath() . ' failed: ' . $e->getMessage(), null, $e);
        }

        if (!$saveChildren) {
            return true;
        }

        foreach ($node as $child) {
            /** @var $child Node */
            if ($child->isNew()) {
                // recursively call ourselves
                $this->storeNode($child);
            }
            // else this is an existing node moved to this location
        }

        return true;


        $this->assertLoggedIn();

        $path = $node->getPath();
        $path = $this->validatePath($path);

        // getting the property definitions is/was a copy of DoctrineDBAL
        // implementation - maybe there is a better way?

        // This is very slow i believe :-(
        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);
        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            foreach ($nodeType->getDeclaredSupertypes() AS $superType) {
                $nodeTypes[] = $superType;
            }
        }

        $propertyDefinitions = array();
        foreach ($nodeTypes AS $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            foreach ($nodeType->getDeclaredPropertyDefinitions() AS $itemDef) {
                /* @var $itemDef \PHPCR\NodeType\ItemDefinitionInterface */
                if ($itemDef->getName() == '*') {
                    continue;
                }

                if (isset($propertyDefinitions[$itemDef->getName()])) {
                    throw new \PHPCR\RepositoryException('MongoDTransport does not support child/property definitions for the same subpath.');
                }
                $propertyDefinitions[$itemDef->getName()] = $itemDef;
            }
            $this->validateNode($node, $nodeType);
        }

        $properties = $node->getProperties();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function storeProperty(Property $property)
    {
        $this->assertLoggedIn();

        $path = $property->getPath();
        $path = $this->validatePath($path);

        $parent = $property->getParent();
        $parentPath = $this->validatePath($parent->getPath());

        try {
            $data = $this->decodeProperty($property);

            $coll = $this->db->selectCollection(self::COLLNAME_NODES);
            $qb = $coll->createQueryBuilder()
                ->select('_id')
                ->findAndUpdate()
                ->field('props.name')->equals($property->getName())
                ->field('path')->equals($parentPath)
                ->field('w_id')->equals($this->workspaceId)
                ->field('props.$')->set($data);
            $query = $qb->getQuery();

            $node = $query->execute();

            if (empty($node)) {
                $qb = $coll->createQueryBuilder()
                    ->update()
                    ->field('path')->equals($parentPath)
                    ->field('w_id')->equals($this->workspaceId)
                    ->field('props')->push($data);
                $query = $qb->getQuery();
                $query->execute();
            }

        } catch (\Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Validation if all the data is correct before writing it into the database
     *
     * @param \PHPCR\PropertyInterface $property
     *
     * @throws \PHPCR\ValueFormatException
     *
     * @return void
     */
    private function assertValidProperty($property)
    {
        $type = $property->getType();
        switch ($type) {
            case PropertyType::NAME:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    $pos = strpos($value, ':');
                    if (false !== $pos) {
                        $prefix = substr($value, 0, $pos);

                        $this->getNamespaces();
                        if (!isset($this->namespaces[$prefix])) {
                            throw new \PHPCR\ValueFormatException("Invalid PHPCR NAME at '" . $property->getPath() . "': The namespace prefix " . $prefix . " does not exist.");
                        }
                    }
                }
                break;
            case PropertyType::PATH:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match('(((/|..)?[-a-zA-Z0-9:_]+)+)', $value)) {
                        throw new \PHPCR\ValueFormatException("Invalid PATH '$value' at '" . $property->getPath() . "': Segments are separated by / and allowed chars are -a-zA-Z0-9:_");
                    }
                }
                break;
            case PropertyType::URI:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match(self::VALIDATE_URI_RFC3986, $value)) {
                        throw new \PHPCR\ValueFormatException("Invalid URI '$value' at '" . $property->getPath() . "': Has to follow RFC 3986.");
                    }
                }
                break;
        }
    }

    /**
     * "Decode" PHPCR property to MongoDB property
     *
     * @param $property
     * @return array|null
     *
     * @throws \Exception
     */
    private function decodeProperty(\PHPCR\PropertyInterface $property)
    {
        $path = $property->getPath();
        $path = $this->validatePath($path);
        $name = explode('/', $path);
        $name = end($name);

        if ($name == 'jcr:uuid' || $name == 'jcr:primaryType') {
            return null;
        }

        if (!$property->isModified() && !$property->isNew()) {
            return null;
        }

        if (($property->getType() == PropertyType::REFERENCE || $property->getType() == PropertyType::WEAKREFERENCE) &&
            ($property->getNode() instanceof \PHPCR\NodeInterface && !$property->getNode()->isNodeType('mix:referenceable'))
        ) {
            throw new \PHPCR\ValueFormatException('Node ' . $property->getNode()->getPath() . ' is not referenceable.');
        }

        $isMultiple = $property->isMultiple();
        $typeId = $property->getType();
        $type = PropertyType::nameFromValue($typeId);

        $data = array(
            'multi' => $isMultiple,
            'name'  => $property->getName(),
            'type'  => $type,
        );

        $binaryData = null;
        switch ($typeId) {
            case \PHPCR\PropertyType::NAME:
            case \PHPCR\PropertyType::URI:
            case \PHPCR\PropertyType::WEAKREFERENCE:
            case \PHPCR\PropertyType::REFERENCE:
            case \PHPCR\PropertyType::PATH:
                $values = $property->getString();
                break;
            case \PHPCR\PropertyType::DECIMAL:
                $values = $property->getDecimal();
                break;
            case \PHPCR\PropertyType::STRING:
                $values = $property->getString();
                break;
            case \PHPCR\PropertyType::BOOLEAN:
                $values = $property->getBoolean();
                break;
            case \PHPCR\PropertyType::LONG:
                $values = $property->getLong();
                break;
            case \PHPCR\PropertyType::BINARY:
                if ($property->isMultiple()) {
                    foreach ((array) $property->getBinary() AS $binary) {
                        $binary = stream_get_contents($binary);
                        $binaryData[] = $binary;
                        $values[] = strlen($binary);
                    }
                } else {
                    $binary = stream_get_contents($property->getBinary());
                    $binaryData[] = $binary;
                    $values = strlen($binary);
                }
                break;
            case \PHPCR\PropertyType::DATE:
                if ($property->isMultiple()) {
                    $dates = $property->getDate() ? : new \DateTime('now');
                    foreach ((array) $dates AS $date) {
                        $value = array(
                            'date'     => new \MongoDate($date->getTimestamp()),
                            'timezone' => $date->getTimezone()->getName()
                        );
                        $values[] = $value;
                    }
                } else {
                    $date = $property->getDate() ? : new \DateTime('now');
                    $values = array(
                        'date'     => new \MongoDate($date->getTimestamp()),
                        'timezone' => $date->getTimezone()->getName()
                    );
                }
                break;
            case \PHPCR\PropertyType::DOUBLE:
                $values = $property->getDouble();
                break;
        }

        if ($isMultiple) {
            $data['value'] = array();
            foreach ((array) $values AS $value) {
                $this->assertValidPropertyValue($data['type'], $value, $path);
                $data['value'][] = $value;
            }
        } else {
            $this->assertValidPropertyValue($data['type'], $values, $path);
            $data['value'] = $values;
        }

        if ($binaryData) {
            try {
                foreach ($binaryData AS $idx => $binary) {
                    $grid = $this->db->getGridFS();
                    $grid->getMongoCollection()->storeBytes($binary, array(
                                                                          'path' => $path,
                                                                          'w_id' => $this->workspaceId,
                                                                          'idx'  => $idx
                                                                     ));
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
        $namespace = array(
            'prefix' => $prefix,
            'uri'    => $uri,
        );
        $coll->insert($namespace);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NAMESPACES);
        $qb = $coll->createQueryBuilder()
            ->field('prefix')->equals($prefix);

        $query = $qb->getQuery();
        $coll->remove($query);
    }

    // WorkspaceManagementInterface //

    /**
     * {@inheritDoc}
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        if ($srcWorkspace !== null) {
            throw new \Jackalope\NotImplementedException();
        }

        $workspaceId = $this->getWorkspaceId($name);
        if ($workspaceId !== false) {
            throw new \PHPCR\RepositoryException("Workspace '" . $name . "' already exists");
        }

        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        $workspace = array(
            'name' => $name
        );
        $coll->insert($workspace);

        $coll = $this->db->selectCollection(self::COLLNAME_NODES);
        $rootNode = array(
            '_id'    => new \MongoBinData(UUIDHelper::generateUUID(), \MongoBinData::UUID),
            'path'   => '/',
            'parent' => '-1',
            'w_id'   => $workspace['_id'],
            'type'   => 'nt:unstructured',
            'props'  => array()
        );
        $coll->insert($rootNode);
    }

    // NodeTypeManagementInterface //

    public function registerNodeTypes($types, $allowUpdate)
    {
        throw new \Jackalope\NotImplementedException('Not implemented yet.');
    }

    // private|protected helper methods //

    /**
     * Get workspace Id
     *
     * @param  string      $workspaceName
     * @return string|bool
     */
    private function getWorkspaceId($workspaceName)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_WORKSPACES);

        $qb = $coll->createQueryBuilder()
            ->field('name')->equals($workspaceName);

        $query = $qb->getQuery();
        $workspace = $query->getSingleResult();

        return ($workspace != null) ? $workspace['_id'] : false;
    }

    /**
     * Checks if path exists
     *
     * @param  string $path
     * @return bool
     */
    private function pathExists($path)
    {
        $coll = $this->db->selectCollection(self::COLLNAME_NODES);

        $qb = $coll->createQueryBuilder()
            ->field('path')->equals($path)
            ->field('w_id')->equals($this->workspaceId);

        $query = $qb->getQuery();

        if (!$query->getSingleResult()) {
            return false;
        }

        return true;
    }

    /**
     * TODO: we should move that into the common Jackalope BaseTransport or as new method of NodeType
     * it will be helpful for other implementations
     *
     * Validate this node with the nodetype and generate not yet existing
     * autogenerated properties as necessary
     *
     * @param Node     $node
     * @param NodeType $def
     */
    private function validateNode(Node $node, NodeType $def)
    {
        foreach ($def->getDeclaredChildNodeDefinitions() as $childDef) {
            /* @var $childDef \PHPCR\NodeType\NodeDefinitionInterface */
            if (!$node->hasNode($childDef->getName())) {
                if ('*' === $childDef->getName()) {
                    continue;
                }

                if ($childDef->isMandatory() && !$childDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Child " . $childDef->getName() . " is mandatory, but is not present while " .
                            "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }

                if ($childDef->isAutoCreated()) {
                    throw new NotImplementedException("Auto-creation of child node '" . $def->getName() . "#" . $childDef->getName() . "' is not yet supported in DoctrineDBAL transport.");
                }
            }
        }

        foreach ($def->getDeclaredPropertyDefinitions() as $propertyDef) {
            /* @var $propertyDef \PHPCR\NodeType\PropertyDefinitionInterface */
            if ('*' == $propertyDef->getName()) {
                continue;
            }

            if (!$node->hasProperty($propertyDef->getName())) {
                if ($propertyDef->isMandatory() && !$propertyDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Property " . $propertyDef->getName() . " is mandatory, but is not present while " .
                            "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }
                if ($propertyDef->isAutoCreated()) {
                    switch ($propertyDef->getName()) {
                        case 'jcr:uuid':
                            $value = UUIDHelper::generateUUID();
                            break;
                        case 'jcr:createdBy':
                        case 'jcr:lastModifiedBy':
                            $value = $this->credentials->getUserID();
                            break;
                        case 'jcr:created':
                        case 'jcr:lastModified':
                            $value = new \DateTime();
                            break;
                        case 'jcr:etag':
                            // TODO: http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.7.12.1%20mix:etag
                            $value = 'TODO: generate from binary properties of this node';
                            break;

                        default:
                            $defaultValues = $propertyDef->getDefaultValues();
                            if ($propertyDef->isMultiple()) {
                                $value = $defaultValues;
                            } elseif (isset($defaultValues[0])) {
                                $value = $defaultValues[0];
                            } else {
                                // When implementing versionable or activity, we need to handle more properties explicitly
                                throw new RepositoryException('No default value for autocreated property ' .
                                    $propertyDef->getName() . ' at ' . $node->getPath());
                            }
                    }

                    $node->setProperty(
                        $propertyDef->getName(),
                        $value,
                        $propertyDef->getRequiredType()
                    );
                }
            }
        }

        foreach ($node->getProperties() as $property) {
            $this->assertValidProperty($property);
        }
    }

    private function getResponsibleNodeTypes(Node $node)
    {
        // This is very slow i believe :-(
        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);

        return $nodeTypes;
    }

    /**
     * Validation if all the data is correct before writing it into the database
     *
     * @param  int    $type
     * @param  mixed  $value
     * @param  string $path
     * @return void
     *
     * @throws \PHPCR\ValueFormatException
     */
    protected function assertValidPropertyValue($type, $value, $path)
    {
        if ($type === \PHPCR\PropertyType::NAME) {
            if (strpos($value, ":") !== false) {
                list($prefix, $localName) = explode(":", $value);

                $this->getNamespaces();
                if (!isset($this->namespaces[$prefix])) {
                    throw new \PHPCR\ValueFormatException("Invalid JCR NAME at " . $path . ": The namespace prefix " . $prefix . " does not exist.");
                }
            }
        } elseif ($type === \PHPCR\PropertyType::PATH) {
            if (!preg_match('((/[a-zA-Z0-9:_-]+)+)', $value)) {
                throw new \PHPCR\ValueFormatException("Invalid PATH at " . $path . ": Segments are seperated by / and allowed chars are a-zA-Z0-9:_-");
            }
        } elseif ($type === \PHPCR\PropertyType::URI) {
            if (!preg_match(self::VALIDATE_URI_RFC3986, $value)) {
                throw new \PHPCR\ValueFormatException("Invalid URI at " . $path . ": Has to follow RFC 3986.");
            }
        }
    }

    /**
     * Get parent path of a path
     *
     * @param  string $path
     * @return string
     */
    protected function getParentPath($path)
    {
        $parentPath = implode('/', array_slice(explode('/', $path), 0, -1));

        return ($parentPath != '') ? $parentPath : '/';
    }

    /**
     * Validate path
     *
     * @param $path
     * @return string
     */
    protected function validatePath($path)
    {
        $this->ensureValidPath($path);

        return $path;
    }

    /**
     * Ensure path is valid
     *
     * @param $path
     *
     * @throws \PHPCR\RepositoryException if path is not well-formed or contains invalid characters
     */
    protected function ensureValidPath($path)
    {
        if (!(strpos($path, '//') === false
            && strpos($path, '/../') === false
            && preg_match('/^[\w{}\/#%&;:^+~*\[\]\. -]*$/iu', $path))
        ) {
            throw new \PHPCR\RepositoryException('Path is not well-formed or contains invalid characters: ' . $path);
        }
    }

    /**
     * Return the permissions of the current session on the node given by path
     * The result of this function is an array of zero, one or more strings from add_node, read, remove, set_property
     *
     * @param  string $path the path to the node we want to check
     * @return array  of string
     */
    public function getPermissions($path)
    {
        return array(
            \PHPCR\SessionInterface::ACTION_ADD_NODE,
            \PHPCR\SessionInterface::ACTION_READ,
            \PHPCR\SessionInterface::ACTION_REMOVE,
            \PHPCR\SessionInterface::ACTION_SET_PROPERTY
        );
    }


    /**
     * {@inheritDoc}
     */
    public function finishSave()
    {

    }

    protected function isWorkspaceAvailable()
    {
        $workspaceColl = $this->db->selectCollection(self::COLLNAME_WORKSPACES);
        $qb = $workspaceColl->createQueryBuilder()
            ->field('_id')->equals($this->workspaceId)
        ;
        $workspace = $qb->getQuery()->getSingleResult();

        return null !== $workspace;
    }

    protected function fetchBooleanPropertyValue($source)
    {
        $booleanValue = $source;

        if (is_bool($booleanValue)) {
            return $booleanValue;
        }

        if (is_string($booleanValue)) {
            $booleanValue = strtolower(trim($booleanValue));
            if ('true' === $booleanValue) {
                $booleanValue = true;
            } elseif ('false' === $booleanValue) {
                $booleanValue = false;
            }
        }

        return (boolean) $booleanValue;
    }
}
