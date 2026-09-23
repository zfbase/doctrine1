<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

/**
 * Doctrine_Record_SyncLinks_TestCase
 *
 * @package     Doctrine
 * @category    Object Relational Mapping
 * @link        www.doctrine-project.org
 */
class Doctrine_Record_SyncLinks_TestCase extends Doctrine_UnitTestCase
{
    protected $tagIds = array();

    public function prepareTables()
    {
        $this->tables = array('SyncLinks_Owner', 'SyncLinks_Tag', 'SyncLinks_OwnerTag', 'SyncLinks_Child');
        parent::prepareTables();
    }

    public function prepareData()
    {
        foreach (array('a', 'b', 'c', 'd') as $name) {
            $tag = new SyncLinks_Tag();
            $tag->name = $name;
            $tag->save();
            $this->tagIds[$name] = $tag->id;
        }
    }

    protected function createOwner(array $tags = array())
    {
        $owner = new SyncLinks_Owner();
        $owner->name = 'owner';
        $owner->save();

        foreach ($tags as $name => $role) {
            $link = new SyncLinks_OwnerTag();
            $link->owner_id = $owner->id;
            $link->tag_id = $this->tagIds[$name];
            $link->role = $role;
            $link->save();
        }

        return $owner;
    }

    protected function createChild($ownerId = null)
    {
        $child = new SyncLinks_Child();
        $child->name = 'child';
        $child->owner_id = $ownerId;
        $child->save();

        return $child;
    }

    protected function linkedRoles($ownerId)
    {
        $rows = Doctrine_Query::create()
            ->from('SyncLinks_OwnerTag l')
            ->where('l.owner_id = ?', $ownerId)
            ->orderBy('l.tag_id')
            ->execute(array(), Doctrine_Core::HYDRATE_ARRAY);

        $roles = array();
        foreach ($rows as $row) {
            $roles[$row['tag_id']] = $row['role'];
        }

        return $roles;
    }

    protected function childOwner($childId)
    {
        return Doctrine_Query::create()
            ->select('c.owner_id')
            ->from('SyncLinks_Child c')
            ->where('c.id = ?', $childId)
            ->execute(array(), Doctrine_Core::HYDRATE_SINGLE_SCALAR);
    }

    public function testListFormIsAppliedOnSaveOnly()
    {
        $t = $this->tagIds;
        $owner = $this->createOwner(array('a' => null, 'b' => null));

        $owner->syncLinks('Tags', array($t['b'], $t['c']));

        $this->assertEqual(array_keys($this->linkedRoles($owner->id)), array($t['a'], $t['b']));

        $owner->save();

        $this->assertEqual(array_keys($this->linkedRoles($owner->id)), array($t['b'], $t['c']));
        $this->assertEqual($owner->getPendingSyncs(), array());
    }

    public function testPayloadInsertsUpdatesAndKeepsUnchangedLinks()
    {
        $t = $this->tagIds;
        $owner = $this->createOwner(array('a' => 'reader', 'b' => 'writer', 'd' => 'reader'));

        SyncLinks_OwnerTag::$writes = array();

        $owner->syncLinks('Tags', array(
            $t['a'] => array('role' => 'admin'),
            $t['b'] => array('role' => 'writer'),
            $t['c'] => array('role' => 'reader'),
        ));
        $owner->save();

        $this->assertEqual($this->linkedRoles($owner->id), array(
            $t['a'] => 'admin',
            $t['b'] => 'writer',
            $t['c'] => 'reader',
        ));
        $writes = SyncLinks_OwnerTag::$writes;
        sort($writes);
        $this->assertEqual($writes, array(
            'delete ' . $t['d'],
            'insert ' . $t['c'],
            'update ' . $t['a'],
        ));
    }

    public function testSyncOnNewOwner()
    {
        $t = $this->tagIds;
        $owner = new SyncLinks_Owner();
        $owner->name = 'new owner';
        $owner->syncLinks('Tags', array($t['a'] => array('role' => 'admin'), $t['c'] => array()));
        $owner->save();

        $this->assertEqual($this->linkedRoles($owner->id), array($t['a'] => 'admin', $t['c'] => null));
    }

    public function testEmptyArrayRemovesAllLinks()
    {
        $owner = $this->createOwner(array('a' => null, 'b' => null));

        $owner->syncLinks('Tags', array());
        $owner->save();

        $this->assertEqual($this->linkedRoles($owner->id), array());
    }

    public function testRelationReflectsNewSetAfterSave()
    {
        $t = $this->tagIds;
        $owner = $this->createOwner(array('a' => null));
        $this->assertEqual(count($owner->Tags), 1);

        $owner->syncLinks('Tags', array($t['b'], $t['c']));
        $owner->save();

        $names = array();
        foreach ($owner->Tags as $tag) {
            $names[] = $tag->name;
        }
        sort($names);
        $this->assertEqual($names, array('b', 'c'));
    }

    public function testLinkAndUnlinkAmendPendingSync()
    {
        $t = $this->tagIds;
        $owner = $this->createOwner(array('a' => null));

        $owner->syncLinks('Tags', array($t['b'] => array('role' => 'admin'), $t['c'] => array()));
        $owner->link('Tags', array($t['b'], $t['d']));
        $owner->unlink('Tags', array($t['c']));

        $this->assertEqual($owner->getPendingLinks(), array());
        $this->assertEqual($owner->getPendingUnlinks(), array());

        $owner->save();

        $this->assertEqual($this->linkedRoles($owner->id), array($t['b'] => 'admin', $t['d'] => null));
    }

    public function testOneToMany()
    {
        $owner = $this->createOwner();
        $kept = $this->createChild($owner->id);
        $dropped = $this->createChild($owner->id);
        $added = $this->createChild();

        $owner->syncLinks('Children', array($kept->id => array('name' => 'renamed'), $added->id => array()));

        $this->assertEqual($this->childOwner($added->id), null);

        $owner->save();

        $this->assertEqual($this->childOwner($kept->id), $owner->id);
        $this->assertEqual($this->childOwner($added->id), $owner->id);
        $this->assertEqual($this->childOwner($dropped->id), null);
        $this->assertEqual(Doctrine_Core::getTable('SyncLinks_Child')->find($kept->id)->name, 'renamed');
        $this->assertTrue(Doctrine_Core::getTable('SyncLinks_Child')->find($dropped->id) instanceof SyncLinks_Child);
        $this->assertEqual(count($owner->Children), 2);
    }

    public function testOneToManyUnknownIdRollsBack()
    {
        $owner = $this->createOwner();
        $child = $this->createChild($owner->id);

        $owner->syncLinks('Children', array(999999));

        try {
            $owner->save();
            $this->fail();
        } catch (Doctrine_Record_Exception $e) {
            $this->pass();
        }

        $this->assertEqual($this->childOwner($child->id), $owner->id);
    }

    public function testInvalidInput()
    {
        $t = $this->tagIds;
        $owner = $this->createOwner();
        $child = $this->createChild($owner->id);

        $invalid = array(
            array($owner, 'Unknown', array()),
            array($child, 'Owner', array($owner->id)),
            array($owner, 'Tags', array($t['a'], $t['b'] => array())),
            array($owner, 'Tags', array($t['a'] => array('nope' => 1))),
            array($owner, 'Tags', array($t['a'] => array('owner_id' => 1))),
            array($owner, 'Tags', array(null)),
        );

        foreach ($invalid as $case) {
            list($record, $alias, $ids) = $case;
            try {
                $record->syncLinks($alias, $ids);
                $this->fail();
            } catch (Doctrine_Record_Exception $e) {
                $this->pass();
            }
        }

        $this->assertEqual($owner->getPendingSyncs(), array());
    }
}

class SyncLinks_Owner extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('id', 'integer', null, array('primary' => true, 'autoincrement' => true));
        $this->hasColumn('name', 'string', 30);
    }

    public function setUp()
    {
        $this->hasMany('SyncLinks_Tag as Tags', array(
            'local' => 'owner_id',
            'foreign' => 'tag_id',
            'refClass' => 'SyncLinks_OwnerTag',
        ));
        $this->hasMany('SyncLinks_Child as Children', array(
            'local' => 'id',
            'foreign' => 'owner_id',
        ));
    }
}

class SyncLinks_Tag extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('id', 'integer', null, array('primary' => true, 'autoincrement' => true));
        $this->hasColumn('name', 'string', 30);
    }

    public function setUp()
    {
        $this->hasMany('SyncLinks_Owner as Owners', array(
            'local' => 'tag_id',
            'foreign' => 'owner_id',
            'refClass' => 'SyncLinks_OwnerTag',
        ));
    }
}

class SyncLinks_OwnerTag extends Doctrine_Record
{
    public static $writes = array();

    public function setTableDefinition()
    {
        $this->hasColumn('owner_id', 'integer', null, array('primary' => true));
        $this->hasColumn('tag_id', 'integer', null, array('primary' => true));
        $this->hasColumn('role', 'string', 30);
    }

    public function setUp()
    {
        $this->hasOne('SyncLinks_Owner as Owner', array('local' => 'owner_id', 'foreign' => 'id'));
        $this->hasOne('SyncLinks_Tag as Tag', array('local' => 'tag_id', 'foreign' => 'id'));
    }

    public function postInsert($event)
    {
        self::$writes[] = 'insert ' . $this->tag_id;
    }

    public function postUpdate($event)
    {
        self::$writes[] = 'update ' . $this->tag_id;
    }

    public function postDelete($event)
    {
        self::$writes[] = 'delete ' . $this->tag_id;
    }
}

class SyncLinks_Child extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('id', 'integer', null, array('primary' => true, 'autoincrement' => true));
        $this->hasColumn('name', 'string', 30);
        $this->hasColumn('owner_id', 'integer');
    }

    public function setUp()
    {
        $this->hasOne('SyncLinks_Owner as Owner', array(
            'local' => 'owner_id',
            'foreign' => 'id',
        ));
    }
}
