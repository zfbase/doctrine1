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
 * Doctrine_OptimisticLocking_TestCase
 *
 * Covers the 'optimisticLocking' table option: save() adds the version the
 * record was loaded with to the UPDATE's where clause and fails loudly when no
 * row matches.
 *
 * @package     Doctrine
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @category    Object Relational Mapping
 * @link        www.doctrine-project.org
 */
class Doctrine_OptimisticLocking_TestCase extends Doctrine_UnitTestCase
{
    public function prepareTables()
    {
        $this->tables = array(
            'OptLockArticle',
            'OptLockCustomField',
            'OptLockCtiParent',
            'OptLockCtiChild',
            'OptLockDisabled',
        );
        parent::prepareTables();
    }

    public function prepareData()
    { }

    /**
     * Reloads a record through a second, independent object, the way a
     * concurrent request would see it.
     */
    protected function reload($component, $id)
    {
        $table = $this->conn->getTable($component);
        $table->clear();

        return $table->find($id);
    }

    public function testUpdateAddsNoConditionWhenOptionIsOff()
    {
        $record = new OptLockDisabled();
        $record->name = 'first';
        $record->version = 1;
        $record->save();

        $stale = $this->reload('OptLockDisabled', $record->id);
        $fresh = $this->reload('OptLockDisabled', $record->id);

        $fresh->name = 'written by the winner';
        $fresh->save();

        // without the option nothing guards the write: last one wins, silently
        $stale->name = 'written by the loser';
        $stale->save();

        $this->assertEqual(
            $this->conn->fetchOne('SELECT name FROM opt_lock_disabled WHERE id = ?', array($record->id)),
            'written by the loser'
        );
        // and the version is left exactly as the application set it
        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT version FROM opt_lock_disabled WHERE id = ?', array($record->id)),
            1
        );
    }

    public function testSaveIncrementsVersion()
    {
        $article = new OptLockArticle();
        $article->title = 'first';
        $article->save();

        $this->assertEqual((int) $article->version, 1);

        $article->title = 'second';
        $article->save();

        $this->assertEqual((int) $article->version, 2);
        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT version FROM opt_lock_article WHERE id = ?', array($article->id)),
            2
        );
    }

    public function testStaleSaveThrowsLockingException()
    {
        $article = new OptLockArticle();
        $article->title = 'original';
        $article->save();

        $winner = $this->reload('OptLockArticle', $article->id);
        $loser  = $this->reload('OptLockArticle', $article->id);

        $this->assertEqual((int) $winner->version, (int) $loser->version);

        $winner->title = 'edited by the winner';
        $winner->save();

        $loser->title = 'edited by the loser';

        try {
            $loser->save();
            $this->fail('stale save should have raised Doctrine_Locking_Exception');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }

        // the winner's row survives untouched
        $this->assertEqual(
            $this->conn->fetchOne('SELECT title FROM opt_lock_article WHERE id = ?', array($article->id)),
            'edited by the winner'
        );
        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT version FROM opt_lock_article WHERE id = ?', array($article->id)),
            2
        );
    }

    public function testReloadingAfterAConflictLetsTheSaveThrough()
    {
        $article = new OptLockArticle();
        $article->title = 'original';
        $article->save();

        $winner = $this->reload('OptLockArticle', $article->id);
        $winner->title = 'winner';
        $winner->save();

        $retry = $this->reload('OptLockArticle', $article->id);
        $retry->title = 'retried';
        $retry->save();

        $this->assertEqual(
            $this->conn->fetchOne('SELECT title FROM opt_lock_article WHERE id = ?', array($article->id)),
            'retried'
        );
        $this->assertEqual((int) $retry->version, 3);
    }

    public function testDeletingTheRowMakesTheSaveFailToo()
    {
        $article = new OptLockArticle();
        $article->title = 'doomed';
        $article->save();

        $stale = $this->reload('OptLockArticle', $article->id);
        $this->conn->exec('DELETE FROM opt_lock_article WHERE id = ?', array($article->id));

        $stale->title = 'writing to a deleted row';

        try {
            $stale->save();
            $this->fail('save on a removed row should have raised Doctrine_Locking_Exception');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }
    }

    public function testCustomVersionFieldName()
    {
        $record = new OptLockCustomField();
        $record->name = 'first';
        $record->save();

        $this->assertEqual((int) $record->revision, 1);

        $winner = $this->reload('OptLockCustomField', $record->id);
        $loser  = $this->reload('OptLockCustomField', $record->id);

        $winner->name = 'winner';
        $winner->save();

        $loser->name = 'loser';

        try {
            $loser->save();
            $this->fail('stale save should have raised Doctrine_Locking_Exception');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }
    }

    public function testInsertIsNotAffected()
    {
        $before = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM opt_lock_article');

        $one = new OptLockArticle();
        $one->title = 'one';
        $one->save();

        $two = new OptLockArticle();
        $two->title = 'two';
        $two->save();

        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM opt_lock_article'),
            $before + 2
        );
        // both rows start guarded
        $this->assertEqual((int) $one->version, 1);
        $this->assertEqual((int) $two->version, 1);
    }

    public function testNullVersionIsLeftAlone()
    {
        $article = new OptLockArticle();
        $article->title = 'legacy row';
        $article->save();

        // a row written before the version column existed
        $this->conn->exec('UPDATE opt_lock_article SET version = NULL WHERE id = ?', array($article->id));

        $legacy = $this->reload('OptLockArticle', $article->id);
        $legacy->title = 'still writable';
        $legacy->save();

        $this->assertEqual(
            $this->conn->fetchOne('SELECT title FROM opt_lock_article WHERE id = ?', array($article->id)),
            'still writable'
        );
    }

    public function testClassTableInheritanceIsGuardedToo()
    {
        $child = new OptLockCtiChild();
        $child->name = 'original';
        $child->extra = 'child data';
        $child->save();

        $this->assertEqual((int) $child->version, 1);

        $winner = $this->reload('OptLockCtiChild', $child->id);
        $loser  = $this->reload('OptLockCtiChild', $child->id);

        $winner->name = 'edited by the winner';
        $winner->save();

        $loser->name = 'edited by the loser';

        try {
            $loser->save();
            $this->fail('stale save on a CTI record should have raised Doctrine_Locking_Exception');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }

        $this->assertEqual(
            $this->conn->fetchOne('SELECT name FROM opt_lock_cti_parent WHERE id = ?', array($child->id)),
            'edited by the winner'
        );
    }

    public function testSequentialSavesOnTheSameObjectNeedNoReload()
    {
        $article = new OptLockArticle();
        $article->title = 'v1';
        $article->save();

        for ($i = 2; $i <= 6; $i++) {
            $article->title = 'v' . $i;
            $article->save();

            $this->assertEqual((int) $article->version, $i);
            $this->assertEqual(
                (int) $this->conn->fetchOne('SELECT version FROM opt_lock_article WHERE id = ?', array($article->id)),
                $i
            );
        }
    }

    public function testSavingAnUnchangedRecordDoesNotBumpTheVersion()
    {
        $article = new OptLockArticle();
        $article->title = 'unchanged';
        $article->save();

        $article->save();                  // nothing modified: no statement at all
        $article->title = 'unchanged';     // same value: still not modified
        $article->save();

        $this->assertEqual((int) $article->version, 1);
        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT version FROM opt_lock_article WHERE id = ?', array($article->id)),
            1
        );
    }

    public function testRetryAfterAConflictMatchesOnTheOriginallyLoadedVersion()
    {
        $article = new OptLockArticle();
        $article->title = 'start';
        $article->save();
        $id = $article->id;

        // another process moves the row on
        $this->conn->exec('UPDATE opt_lock_article SET version = 50 WHERE id = ?', array($id));

        $article->title = 'attempt 1';

        try {
            $article->save();
            $this->fail('the conflicting save should have been refused');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }

        // that other change goes away again
        $this->conn->exec('UPDATE opt_lock_article SET version = 1 WHERE id = ?', array($id));

        // the failed attempt must not have corrupted the expected version
        $article->title = 'attempt 2';
        $article->save();

        $this->assertEqual(
            $this->conn->fetchOne('SELECT title FROM opt_lock_article WHERE id = ?', array($id)),
            'attempt 2'
        );
        $this->assertEqual(
            (int) $this->conn->fetchOne('SELECT version FROM opt_lock_article WHERE id = ?', array($id)),
            2
        );
    }

    public function testMisconfiguredFieldIsReported()
    {
        $record = new OptLockArticle();
        $record->title = 'configured wrong';
        $record->save();

        $table = $this->conn->getTable('OptLockArticle');
        $table->setOption('optimisticLocking', 'no_such_field');

        $record->title = 'changed';

        try {
            $record->save();
            $this->fail('a missing version field should have been reported');
        } catch (Doctrine_Locking_Exception $e) {
            $this->pass();
        }

        $table->setOption('optimisticLocking', true);
    }
}

class OptLockArticle extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('title', 'string', 100);
        $this->hasColumn('version', 'integer', 4);

        $this->option('optimisticLocking', true);
    }
}

class OptLockCustomField extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('name', 'string', 100);
        $this->hasColumn('revision', 'integer', 4);

        $this->option('optimisticLocking', 'revision');
    }
}

class OptLockCtiParent extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('name', 'string', 100);
        $this->hasColumn('version', 'integer', 4);

        $this->option('optimisticLocking', true);
    }
}

class OptLockCtiChild extends OptLockCtiParent
{
    public function setTableDefinition()
    {
        $this->hasColumn('extra', 'string', 100);
    }
}

class OptLockDisabled extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('name', 'string', 100);
        $this->hasColumn('version', 'integer', 4);
    }
}
