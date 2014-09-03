<?php
/**
 * Nk-DbTableNestedSet
 *
 * LICENSE
 *
 * This source file is subject to private license
 */

//require_once('Zend/Db/Table.php');

/**
 * Class that extends capabilities of Zend_Db_Table class,
 * providing API for managing some Nested set table in
 * database.
 *
 * @author jean-luc BARAT <jl.barat@gmail.com>
 *
 * @Based on http://devzone.zend.com/article/11886
 * @license Private License
 * @Date 12/08/2011
 */
class Nk_Db_Table_NestedTree extends Zend_Db_Table //_Abstract
{
    const LEFT_COL  = 'left';
    const RIGHT_COL = 'right';

    const FIRST_CHILD  = 'firstChild';
    const LAST_CHILD   = 'lastChild';
    const NEXT_SIBLING = 'nextSibling';
    const PREV_SIBLING = 'prevSibling';

    const LEFT_TBL_ALIAS  = 'node';
    const RIGHT_TBL_ALIAS = 'parent';

    /**
     * Valid objective node positions.
     *
     * @var array
     */
    protected static $_validPositions = array(
        self::FIRST_CHILD,
        self::LAST_CHILD,
        self::NEXT_SIBLING,
        self::PREV_SIBLING
    );

    /**
     * Left column name in nested table.
     *
     * @var string
     */
    protected $_left;

    /**
     * Right column name in nested table.
     *
     * @var string
     */
    protected $_right;

    /**
     * Level in nested table.
     *
     * @var string
     */
    protected $_level;

    /**
     * Node path_name
     *
     * @var string
     */
    protected $_path_name;

    /**
     * Current Id
     *
     * @var int
     */
    protected $_treeId;

    /**
     * Current Node
     *
     * @var array
     */
    protected $_currentNode = null;

    /**
     * Enum path to item
     *
     * @var string
     */
    protected $_enum_path;

    /**
     * Internal cache of nested data (left, right, width)
     * retrieved from some nodes.
     *
     * @var $this
     */
    protected $_nestedDataCache = array();


    /**
     * __construct() - For concrete implementation of Nk_Db_Table_NestedSet
     *
     * @param string|array $config string can reference a Zend_Registry key for a db adapter
     *                             OR it can reference the name of a table
     * @param array|Zend_Db_Table_Definition $definition
     * @return void
     */
    public function __construct()
    {
        parent::__construct(array(), null);

        $this->_setupPrimaryKey();
        $this->_setupLftRgt();

        return $this;
    }


    /**
     * Defined by Zend_Db_Table_Abstract.
     *
     * @return void
     */
    protected function _setupPrimaryKey()
    {
        parent::_setupPrimaryKey();

        if(count($this->_primary) > 1) { //Compound key?
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Tables with compound primary key are not currently supported.');
        }
    }


    /**
     * Validating supplied "left" and "right" columns.
     *
     * @return void
     */
    protected function _setupLftRgt()
    {
        if(!$this->_left || !$this->_right) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Both "left" and "right" column names must be supplied.');
        }

        $this->_setupMetadata();

        if(count(array_intersect(array($this->_left, $this->_right), array_keys($this->_metadata))) < 2) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Supplied "left" and "right" were not found.');
        }
    }


    /**
     * Check row exist
     *
     * @param string $row row name
     * @return string
     */
    private function _checkKey($row)
    {
        if(!array_key_exists($row, $this->_metadata)) {
            // include_once('Nk/Db/Table/NestedSet/Exception.php');
            // throw new Nk_Db_Table_NestedSet_Exception('Supplied key "' . $row . '" was not found.');
            return false;
        }

        return true;
    }


    /**
     * Checks whether valid node position is supplied.
     *
     * @param string $position Position regarding on objective node.
     * @return bool
     */
    private function _checkNodePosition($position)
    {
        if(!in_array($position, self::$_validPositions)) {
            return false;
        }

        return true;
    }


    /**
     * Generates left and right column value, based on id of a
     * objective node.
     *
     * @param int|null $objectiveNodeId Id of a objective node.
     * @param string $position Position in tree.
     * @param int|null $id Id of a node for which left and right column values are being generated (optional).
     * @return array
     */
    protected function _getLftRgt($objectiveNodeId, $position, $id = null)
    {
        $lftRgt = array();

        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $left = null;
        $right = null;

        if($objectiveNodeId) { //User selected some objective node?
            $objectiveNodeId = (int)$objectiveNodeId;
            $result = $this->getNestedSetData($objectiveNodeId);
            if($result) {
                $left = (int)$result['left'];
                $right = (int)$result['right'];
            }
        }

        if($left !== null && $right !== null) { //Existing objective id?
            switch ($position) {
                case self::FIRST_CHILD :
                    $lftRgt[$this->_left] = $left + 1;
                    $lftRgt[$this->_right] = $left + 2;

                    $this->update(array($this->_right=>new Zend_Db_Expr("$rightCol + 2")), "$rightCol > $left");
                    $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol + 2")), "$leftCol > $left");

                    break;
                case self::LAST_CHILD :
                    $lftRgt[$this->_left] = $right;
                    $lftRgt[$this->_right] = $right + 1;

                    $this->update(array($this->_right=>new Zend_Db_Expr("$rightCol + 2")), "$rightCol >= $right");
                    $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol + 2")), "$leftCol > $right");

                    break;
                case self::NEXT_SIBLING :
                    $lftRgt[$this->_left] = $right + 1;
                    $lftRgt[$this->_right] = $right + 2;

                    $this->update(array($this->_right=>new Zend_Db_Expr("$rightCol + 2")), "$rightCol > $right");
                    $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol + 2")), "$leftCol > $right");

                    break;
                case self::PREV_SIBLING :
                    $lftRgt[$this->_left] = $left;
                    $lftRgt[$this->_right] = $left + 1;

                    $this->update(array($this->_right=>new Zend_Db_Expr("$rightCol + 2")), "$rightCol > $left");
                    $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol + 2")), "$leftCol >= $left");

                    break;
            }
        }
        else {
            $sql = $this->select()->from($this->_name,array('max_rgt'=>new Zend_Db_Expr("MAX($rightCol)")));
            if($id !== null) {
               $id = (int)$id;
               $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
               $sql->where("$primary != ?", $id, Zend_Db::INT_TYPE);
            }
            $result = $this->_db->fetchRow($sql);

            if(!$result) { //No data? First node...
                $lftRgt[$this->_left] = 1;
            }
            else {
                $lftRgt[$this->_left] = (int)$result['max_rgt'] + 1;
            }

            $lftRgt[$this->_right] = $lftRgt[$this->_left] + 1;
        }

        return $lftRgt;
    }


    /**
     * Reduces lft and rgt values of some nodes, on which some
     * node that is changing position in tree, or being deleted,
     * has effect.
     *
     * @param mixed $id Id of a node.
     * @return void
     */
    protected function _reduceWidth($id)
    {
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $result = $this->getNestedSetData($id);

        if($result) {
            $left = (int)$result['left'];
            $right = (int)$result['right'];
            $width = (int)$result['width'];

            if($width > 2) { //Some node that has children.
                //Updating child nodes.
                $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol - 1"), $this->_right=>new Zend_Db_Expr("$rightCol - 1")), "$leftCol BETWEEN $left AND $right");
            }

            //Updating parent nodes and nodes on higher levels.
            $this->update(array($this->_left=>new Zend_Db_Expr("$leftCol - 2")), "$leftCol > $left AND $rightCol > $right");
            $this->update(array($this->_right=>new Zend_Db_Expr("$rightCol - 2")), "$rightCol > $right");
        }
    }


    /**
     * Gets id of some node's current objective node.
     *
     * @param mixed $nodeId Node id.
     * @param string $position Position in tree.
     * @return int|null
     */
    protected function _getCurrentObjectiveId($nodeId, $position)
    {
        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $sql = $this->select()
            ->from(
                array('node' => $this->_name),
                array($this->_primary[1])
            )
            ->join(array('current'=>$this->_name), '', array());

        switch ($position) {
            case self::FIRST_CHILD :
                $sql->where("current.$leftCol BETWEEN node.$leftCol+1 AND node.$rightCol AND current.$leftCol - node.$leftCol = 1")
                    ->order('node.' . $this->_left . ' DESC');

                break;
            case self::LAST_CHILD :
                $sql->where("current.$leftCol BETWEEN node.$leftCol+1 AND node.$rightCol AND node.$rightCol - current.$rightCol = 1")
                    ->order('node.' . $this->_left . ' DESC');

                break;
            case self::NEXT_SIBLING :
                $sql->where("current.$leftCol - node.$rightCol = 1");

                break;
            case self::PREV_SIBLING :
                $sql->where("node.$leftCol - current.$rightCol = 1");

                break;
        }

        $sql->where("current.$primary = ?", $nodeId, Zend_Db::INT_TYPE);

        $result = $this->_db->fetchRow($sql);
        if($result) {
            return (int)$result[$this->_primary[1]];
        }
        else {
            return null;
        }
    }


    /**
     * Generates and returns SQL query that is used for fetchAll() and
     * fetchRow() methods, in case $parentAlias param is supplied.
     *
     * @param string|array|Zend_Db_Table_Select|null $where       An SQL WHERE clause or Zend_Db_Table_Select object.
     * @param string|null                            $parentAlias Additional column, named after value of this argument, will be returned, containing id of a parent node will be included in result set.
     * @param string|array|null                      $order       An SQL ORDER clause.
     * @param int|null                               $count       OPTIONAL An SQL LIMIT count.
     * @param int|null                               $offset      OPTIONAL An SQL LIMIT offset.
     * @return Zend_Db_Table_Select
     */
    protected function _getSelectWithParent($where, $parentAlias, $order, $count = null, $offset = null)
    {
        $parentAlias = (string)$parentAlias;

        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $parentSelect = $this->select()
            ->from($this->_name, array($this->_primary[1]))
            ->where(self::LEFT_TBL_ALIAS . '.' . $leftCol . ' BETWEEN ' . $leftCol . '+1 AND ' . $rightCol)
            ->order($this->_left . ' DESC')
            ->limit(1);

        $select = $this->select()->from(array(self::LEFT_TBL_ALIAS => $this->_name), array('*', $parentAlias => "($parentSelect)"));

        if($where !== null) {
            $this->_where($select, $where);
        }

        if($order !== null) {
            $this->_order($select, $order);
        }

        if($count !== null || $offset !== null) {
            $select->limit($count, $offset);
        }

        return $select;
    }


    /**
     * Defined by Zend_Db_Table_Abstract.
     *
     * @param array $options
     * @return Zend_Db_Table_Abstract
     */
    public function setOptions(Array $options)
    {
        if(isset($options[self::LEFT_COL])) {
            $this->_left = (string)$options[self::LEFT_COL];
        }
        if(isset($options[self::RIGHT_COL])) {
            $this->_right = (string)$options[self::RIGHT_COL];
        }

        return parent::setOptions($options);
    }


    /**
     * Retrieve the current node
     *
     * @param int|string $nodeId identifier of node to retrieve
     * @param string $rowName row name where search
     * @return object $this
     */
    public function setNodeId($nodeId, $rowName = null)
    {
        // verifying existing row name and assign, or use foreign key
        $rowName    = (!is_null($rowName) && $this->_checkKey($rowName))
            ? $this->getAdapter()->quoteIdentifier($rowName)
            : $this->_primary[1];

        // querying table
        // $rowset     = parent::fetchRow($rowName . ' = ' . $this->getAdapter()->quoteIdentifier($nodeId));
        $rowset     = parent::fetchRow($rowName . ' = ' . $nodeId);

        // verifying row is correctly set, otherwise trown exectption
        if(!$rowset || empty($rowset)) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Not defined node');
        }

        $this->_node = $rowset;

        return $this;
    }


    /**
     * Return the current node Id
     *
     * @return int
     */
    public function getNodeId()
    {
        $nodeId = $this->_node[$this->_primary[1]];

        if(!$nodeId) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Not defined node');
        }

        return $nodeId;
    }


    /**
     * Return the current node
     *
     * @return array
     */
    public function getNode()
    {
        if(!$this->_node || empty($this->_node)) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Not defined node');
        }

        return $this->_node;
    }


    /**
     * Gets whole tree, including depth information.
     *
     * @param mixed $where An SQL WHERE clause or Zend_Db_Table_Select object.
     * @return array
     */
    public function getTree($where = null)
    {
        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $select = $this->select()->setIntegrityCheck(false)
            ->from(
                array(self::LEFT_TBL_ALIAS => $this->_name),
                array(
                      self::LEFT_TBL_ALIAS . '.*',
                      // 'depth' => new Zend_Db_Expr('COUNT(' . self::RIGHT_TBL_ALIAS . '.' . $primary . ') - 1')
                )
            )
            ->join(
                array(self::RIGHT_TBL_ALIAS => $this->_name),
                '(' . self::LEFT_TBL_ALIAS . '.' . $leftCol . ' BETWEEN ' . self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' AND ' . self::RIGHT_TBL_ALIAS . '.' . $rightCol . ')',
                array()
            )
            ->group(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1])
            ->order(self::LEFT_TBL_ALIAS . '.' . $this->_left);

        if($where !== null) {
            $this->_where($select, $where);
        }

        return parent::fetchAll($select);
    }


    /**
     * Gets parentsNode, including informations.
     *
     * @param bool $withCurrent Include current node.
     * @return array
     */
    public function getParents($withCurrent = false)
    {
        $withCurrent = (int) !$withCurrent;
        $nodeId      = $this->getNodeId();
        $node        = $this->getNode();

        $primary     = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol     = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol    = $this->getAdapter()->quoteIdentifier($this->_right);

        $select = $this->select()
            ->from(
                   array(self::LEFT_TBL_ALIAS => $this->_name),
                   array(self::RIGHT_TBL_ALIAS . '.*')
               )
               ->join(
                   array(self::RIGHT_TBL_ALIAS => $this->_name),
                   self::LEFT_TBL_ALIAS . '.' . $leftCol .' BETWEEN (' . self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' + '. $withCurrent .')
                                                            AND (' . self::RIGHT_TBL_ALIAS . '.' . $rightCol . ' - '. $withCurrent .')',
                   array()
               )
               ->where(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId)
               ->order(self::RIGHT_TBL_ALIAS . '.' . $this->_left);

        // print $select . '<br>';

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Gets parent Node
     *
     * @return array
     */
    public function getParent()
    {
        $nodeId = $this->getNodeId();

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $select = $this->select()
            ->from(
                   array(self::LEFT_TBL_ALIAS => $this->_name),
                   array(self::RIGHT_TBL_ALIAS . '.*')
               )
               ->join(
                   array(self::RIGHT_TBL_ALIAS => $this->_name),
                   self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' < ' . self::LEFT_TBL_ALIAS . '.' . $leftCol . ' AND ' . self::RIGHT_TBL_ALIAS . '.' . $rightCol . ' > '. self::LEFT_TBL_ALIAS . '.' . $rightCol,
                   array()
               )
               ->where(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId)
               ->order(self::RIGHT_TBL_ALIAS . '.' . $this->_left . ' DESC')
               ->limit(1);

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Gets one previous sibling node, including informations.
     *
     * @return array
     */
    public function getPrevNode()
    {
        $nodeId = $this->getNodeId();

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $select = $this->select()
            ->from(
                   array(self::LEFT_TBL_ALIAS => $this->_name),
                   array()
               )
               ->join(
                   array(self::RIGHT_TBL_ALIAS => $this->_name),
                   self::RIGHT_TBL_ALIAS . '.' . $rightCol . ' = ' . self::LEFT_TBL_ALIAS . '.' . $leftCol . ' - 1',
                   array(self::RIGHT_TBL_ALIAS . '.*')
               )
               ->where(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId)
               ->order(self::RIGHT_TBL_ALIAS . '.' . $this->_left . ' DESC')
               ->limit(1);

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Gets one next sibling node, including informations.
     *
     * @return array
     */
    public function getNextNode()
    {
        $nodeId = $this->getNodeId();

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $select = $this->select()
            ->from(
                   array(self::LEFT_TBL_ALIAS => $this->_name),
                   array()
               )
               ->join(
                   array(self::RIGHT_TBL_ALIAS => $this->_name),
                   self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' = ' . self::LEFT_TBL_ALIAS . '.' . $rightCol . ' + 1',
                   array(self::RIGHT_TBL_ALIAS . '.*')
               )
               ->where(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId)
               ->order(self::RIGHT_TBL_ALIAS . '.' . $this->_left . ' DESC')
               ->limit(1);

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Gets brother nodes
     *
     * @param int Id of a node.
     * @return array
     */
    public function getBrothers()
    {
        $nodeId = $this->getNodeId();

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $subQuery = $this->select()
            ->from(array(self::LEFT_TBL_ALIAS => $this->_name),
                   array(self::RIGHT_TBL_ALIAS . '.*'))
            ->join(array(self::RIGHT_TBL_ALIAS => $this->_name),
                   self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' < ' . self::LEFT_TBL_ALIAS . ' . ' . $leftCol . ' AND ' . self::RIGHT_TBL_ALIAS . '.' . $rightCol . ' > ' . self::LEFT_TBL_ALIAS . '.' . $rightCol,
                   array())
            ->where(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId)
            ->group(self::RIGHT_TBL_ALIAS . '.' . $this->_primary[1])
            ->order(self::LEFT_TBL_ALIAS . '.' . $this->_left . ' DESC')
            ->limit(1);

        $select = $this->select()
            ->setIntegrityCheck(false)
            ->from(
                   array(self::LEFT_TBL_ALIAS => $this->_name),
                   array(self::LEFT_TBL_ALIAS . '.*', 'parentId' => self::RIGHT_TBL_ALIAS . '.' . $this->_primary[1])
               )
               ->join(
                   array(self::RIGHT_TBL_ALIAS => $subQuery),
                   self::LEFT_TBL_ALIAS . '.' . $leftCol . ' BETWEEN ' . self::RIGHT_TBL_ALIAS . '.' . $leftCol . ' AND ' . self::RIGHT_TBL_ALIAS . '.' . $rightCol,
                   array()
               )
               ->having(self::LEFT_TBL_ALIAS . '.' . $this->_primary[1] != 'parentId');

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Gets children nodes, including informations.
     *
     * @param int $depth (optional) глубина выборки детей. Null - все уровни, 0 - текущий, +$depth level
     * @param bool $withCurrent (optional) return current nodeId too. Ставится в true при $depth = 0
     * @param string $order (optional) order using order table key.
     * @return array
     */
    public function getChildren($depth = null, $withCurrent = null, $order = null)
    {
        $nodeId = $this->getNodeId();
        $order = (string) $order;
        if($depth === 0) {
            $withCurrent = true;
        }

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);
        $levelCol = $this->getAdapter()->quoteIdentifier($this->_level);

        $select = $this->select()
            ->from(array(self::LEFT_TBL_ALIAS => $this->_name))
            ->from(array(self::RIGHT_TBL_ALIAS => $this->_name), array());

        if(!is_null($depth)) {
            $select
                ->where(self::LEFT_TBL_ALIAS . '.' . $levelCol . ' <= ' . self::RIGHT_TBL_ALIAS . '.' . $levelCol . ' + ' . $depth);
        }

        $select
            ->where(self::LEFT_TBL_ALIAS . '.' . $leftCol  . ($withCurrent ? " >= " : " > ") . self::RIGHT_TBL_ALIAS . '.' . $leftCol)
            ->where(self::LEFT_TBL_ALIAS . '.' . $rightCol . ($withCurrent ? " <= " : " < ") . self::RIGHT_TBL_ALIAS . '.' . $rightCol)
            ->where(self::RIGHT_TBL_ALIAS . '.' . $this->_primary[1] . ' = ?', $nodeId);

        // If order not null, define order
        if(!is_null($order) && $this->_checkKey($order)) {
            $select->order($order);
        }
        else {
            $select
                ->order($this->_left);
                // ->order($this->_level);
        }

        return parent::fetchAll($select)->toArray();
    }


    /**
     * Return all children ids of current node (all level)
     *
     * @param int $depth (optional) глубина выборки детей. Null - все уровни, 0 - текущий, +$depth level
     * @param bool $withCurrent (optional) return current nodeId too.
     * @param string $order (optional) order using order table key.
     * @return array
     */
    public function getChildrenId($depth = null, $withCurrent = null, $order = null)
    {
        $nodeId = $this->getNodeId();
        $node = $this->getNode();
        $order = (string) $order;
        if($depth === 0) {
            $withCurrent = true;
        }

        $primary = $this->_primary[1];
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);
        $levelCol = $this->getAdapter()->quoteIdentifier($this->_level);

        $select = $this->select()
            ->from(array(self::LEFT_TBL_ALIAS => $this->_name), $primary)
            ->where(self::LEFT_TBL_ALIAS . '.' . $leftCol  . ($withCurrent ? " >= " : " > ") . $node[$this->_left])
            ->where(self::LEFT_TBL_ALIAS . '.' . $rightCol . ($withCurrent ? " <= " : " < ") . $node[$this->_right]);

        if(!is_null($depth)) {
            $select
                ->where(self::LEFT_TBL_ALIAS . '.' . $levelCol . ' <= ' . $node[$this->_level] . ' + ' . $depth);
        }


        // If order not null, define order
        if(!is_null($order) && $this->_checkKey($order)) {
            $select->order($order);
        }
        else {
            $select
                ->order($this->_left);
                // ->order($this->_level);
        }

        // print $select . '<br>';

        $array = parent::fetchAll($select)->toArray();

        // applatit le tableau
        $ids = array();
        foreach((array) $array as $key => $val) {
            array_push($ids, $val[$primary]);
        }

        return $ids;
    }


    /**
     * Overriding insert() method defined by Zend_Db_Table_Abstract.
     *
     * @param array $data Submitted data.
     * @param int|null $objectiveNodeId Objective node id (optional).
     * @param string $position Position regarding on objective node (optional). [firstChild | lastChild | nextSibling | prevSibling]
     * @return mixed
     */
    public function insert($data, $objectiveNodeId = null, $position = self::LAST_CHILD)
    {
        if(!$this->_checkNodePosition($position)) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Invalid node position is supplied.');
        }

        $this->_db->beginTransaction();

        try {
            $data = array_merge($data, $this->_getLftRgt($objectiveNodeId, $position));

            $result = parent::insert($data);
            $this->_db->commit();
        }
        catch(Exception $e) {
            $this->_db->rollBack();
            echo $e->getMessage();
        }

        return $result;
    }


    /**
     * Updates info of some node.
     *
     * @param array $data Submitted data.
     * @param int $id Id of a node that is being updated.
     * @param int $objectiveNodeId Objective node id.
     * @param string $position Position regarding on objective node (optional). [firstChild | lastChild | nextSibling | prevSibling]
     * @return mixed
     */
    public function updateNode($data, $id, $objectiveNodeId, $position = self::LAST_CHILD)
    {
        $id = (int)$id;
        $objectiveNodeId = (int)$objectiveNodeId;

        if(!$this->_checkNodePosition($position)) {
            include_once('Nk/Db/Table/NestedSet/Exception.php');
            throw new Nk_Db_Table_NestedSet_Exception('Invalid node position is supplied.');
        }

        $this->_db->beginTransaction();

        try {
            if($objectiveNodeId != $this->_getCurrentObjectiveId($id, $position)) { //Objective node differs?
                $this->_reduceWidth($id);

                $data = array_merge($data, $this->_getLftRgt($objectiveNodeId, $position, $id));
            }

            $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
            $where = $this->getAdapter()->quoteInto($primary . ' = ?', $id, Zend_Db::INT_TYPE);

            $result = $this->update($data, $where);

            $this->_db->commit();
        }
        catch(Exception $e) {
            $this->_db->rollBack();
            echo $e->getMessage();
        }

        return $result;
    }


    /**
     * Deletes some node(s) and returns ids of deleted nodes.
     *
     * @param mixed $id Id of a node.
     * @param bool $cascade Whether to delete child nodes, too.
     * @return int The number of affected rows.
     */
    public function deleteNode($id, $cascade = false)
    {
        $retval = 0;

        $id = (int)$id;

        $this->_db->beginTransaction();

        try {
            $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);

            if(!$cascade) {
                $this->_reduceWidth($id);

                //Deleting node.
                $retval = $this->delete(array($primary . ' = ?'=>$id));
            }
            else {
                $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
                $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

                $result = $this->getNestedSetData($id);

                $lft = (int)$result['left'];
                $rgt = (int)$result['right'];
                $width = (int)$result['width'];

                //Deleting items.
                $retval = $this->delete("$leftCol BETWEEN $lft AND $rgt");

                $this->update(array($this->_left => new Zend_Db_Expr("$leftCol - $width")), "$leftCol > $lft");

                $this->update(array($this->_right => new Zend_Db_Expr("$rightCol - $width")), "$rightCol > $rgt");
            }

            $this->_db->commit();
        }
        catch(Exception $e) {
            $this->_db->rollBack();
            echo $e->getMessage();
        }

        return $retval;
    }


    /**
     * Gets nested set data (left, right, width) for some node.
     *
     * @param int $id Id of a node.
     * @return array|null
     */
    public function getNestedSetData($id)
    {
        if(array_key_exists($id, $this->_nestedDataCache)) {
            return $this->_nestedDataCache[$id];
        }

        $primary = $this->getAdapter()->quoteIdentifier($this->_primary[1]);
        $leftCol = $this->getAdapter()->quoteIdentifier($this->_left);
        $rightCol = $this->getAdapter()->quoteIdentifier($this->_right);

        $sql = $this->select()
            ->from(
                $this->_name,
                array(
                    'left'=>$this->_left,
                    'right'=>$this->_right,
                    'width' => new Zend_Db_Expr("$rightCol - $leftCol + 1")
                )
            )
            ->where($primary . ' = ?', (int)$id, Zend_Db::INT_TYPE);

        $result = $this->_db->fetchRow($sql);
        if($result) {
            $this->_nestedDataCache[$id] = $result; //Storing result in cache.
            return $result;
        }
        else {
            return null;
        }
    }


    /**
     * Overriding fetchAll() method defined by Zend_Db_Table_Abstract.
     *
     * @param string|array|Zend_Db_Table_Select $where       OPTIONAL An SQL WHERE clause or Zend_Db_Table_Select object.
     * @param bool                              $getAsTree   OPTIONAL Whether to retrieve nodes as tree.
     * @param string                            $parentAlias OPTIONAL If this argument is supplied, additional column,
     *                                                      named after value of this argument, will be returned,
     *                                                      containing id of a parent node will be included in result set.
     * @param string|array                      $order       OPTIONAL An SQL ORDER clause.
     * @param int                               $count       OPTIONAL An SQL LIMIT count.
     * @param int                               $offset      OPTIONAL An SQL LIMIT offset.
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function fetchAll($where = null, $getAsTree = false, $parentAlias = null, $order = null, $count = null, $offset = null)
    {
        if($getAsTree == true) { //If geeting nodes as tree, other arguments are omitted.
            return $this->getTree($where);
        }
        elseif($parentAlias != null) {
            return parent::fetchAll($this->_getSelectWithParent($where, $parentAlias, $order, $count, $offset));
        }
        else {
            return parent::fetchAll($where, $order, $count, $offset);
        }
    }


    /**
     * Overriding fetchRow() method defined by Zend_Db_Table_Abstract.
     *
     * @param string|array|Zend_Db_Table_Select $where       OPTIONAL An SQL WHERE clause or Zend_Db_Table_Select object.
     * @param string                            $parentAlias OPTIONAL If this argument is supplied, additional column,
     *                                                      named after value of this argument, will be returned,
     *                                                      containing id of a parent node will be included in result set.
     * @param string|array                      $order       OPTIONAL An SQL ORDER clause.
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function fetchRow($where = null, $parentAlias = null, $order = null)
    {
        if($parentAlias != null) {
            return parent::fetchRow($this->_getSelectWithParent($where, $parentAlias, $order));
        }
        else {
            return parent::fetchRow($where, $order);
        }
    }


    /**
     * Defined by Zend_Db_Table_Abstract.
     *
     * @param string $key The specific info part to return OPTIONAL
     * @return mixed
     */
    public function info($key = null)
    {
        $nestedSetInfo = array(
            self::LEFT_COL  =>  $this->_left,
            self::RIGHT_COL =>  $this->_right
        );

        if($key === null) {
            return array_merge(parent::info(), $nestedSetInfo);
        }
        else {
            if(array_key_exists($key, $nestedSetInfo)) {
                return $nestedSetInfo[$key];
            }
            else {
                return parent::info($key);
            }
        }
    }


    /**
     * Enum path to node from root. No opening and closing slashes.
     * Repeated call gets value from cache
     *
     * @param  boolean $rescan force rescan path from db
     *
     * @return string
     */
    public function enumPath($rescan = false)
    {
        if(empty($this->_enum_path) || $rescan) {
            $this->_enum_path = $this->_enumPath();
        }

        return $this->_enum_path;
    }



    /**
     * Enum path to node from root. No opening and closing slashes.
     *
     * @return string
     */
    protected function _enumPath()
    {
        $path = array();

        $parents = $this->getParents(true);
        if(!empty($parents)) {
            foreach($parents as $k => $parent) {
                if($k === 0 && empty($parent[$this->_path_name])) {

                }
                else {
                    $path[] = $parent[$this->_path_name];
                }
            }
        }

        $path = implode('/', $path);

        return $path;
    }

}
