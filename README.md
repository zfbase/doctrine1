# Doctrine 1

`zfbase/doctrine1` is a maintenance fork of Doctrine 1.2, the PHP ActiveRecord ORM and database abstraction layer, kept working on modern PHP (8.0 through 8.5). It keeps the public 1.2 API, so existing applications and generated models run unchanged.

The original 1.2 documentation still applies. This file covers only what the fork adds.

## Installation

```bash
composer require zfbase/doctrine1
```

Requires PHP `^8.0` and `ext-pdo`. Classes are autoloaded through Composer (PSR-0, `Doctrine_` prefix).

## Optimistic locking

Optimistic locking stops two processes from silently overwriting each other's changes to the same row. Each row carries a version number. When a record is saved, the `UPDATE` only matches the row if its version is still the one the record was loaded with, and it increments the version in the same statement. If another process saved the row in between, or deleted it, no row matches and `save()` throws `Doctrine_Locking_Exception` instead of overwriting.

It is off by default. To turn it on for a model, add an integer column and set the `optimisticLocking` table option:

```php
class Article extends Doctrine_Record
{
    public function setTableDefinition()
    {
        $this->hasColumn('title', 'string', 100);
        $this->hasColumn('version', 'integer', 4);

        $this->option('optimisticLocking', true);        // uses the "version" field
        // $this->option('optimisticLocking', 'revision'); // or name another field
    }
}
```

The same in a YAML schema:

```yaml
Article:
  columns:
    title: string(100)
    version: integer(4)
  options:
    optimisticLocking: true
```

Handling a conflict:

```php
try {
    $article->title = 'New title';
    $article->save();
} catch (Doctrine_Locking_Exception $e) {
    // someone else changed or deleted the row since it was loaded:
    // reload it, apply the change again, or report the conflict to the user
    $article = Doctrine_Core::getTable('Article')->find($article->id);
}
```

Behavior details:

- **Insert.** A new record starts at version `1`, unless the version was already set (for example, by a listener).
- **Update.** The version goes up by one on every save that writes something. Saving an unmodified record sends no statement, so the version doesn't change.
- **Repeated saves.** The same object can be saved many times without reloading, because its version is updated after each save.
- **Failed saves.** After a failed save, the record still expects the version it was loaded with, so a retry doesn't silently use a wrong version.
- **Existing rows.** A row whose version is `NULL` (written before the column existed) is saved without a check and gets version `1`. Every later save of it is checked.
- **Class table inheritance.** Set the option on the parent model. Child models inherit it, and the check runs on the table that holds the version column.
- **Misconfiguration.** If the configured field doesn't exist on the model, `save()` throws `Doctrine_Locking_Exception`.
- **Not covered.** `Doctrine_Record::replace()` and DQL `UPDATE` queries skip the check by design.

## Syncing related ids: `syncLinks()`

`$record->syncLinks($alias, $ids)` replaces the whole set of links for a relation in one call. You pass the ids that should be linked. On the next `save()`, Doctrine adds the missing links, removes the ones you didn't list, and updates the ones whose values changed. Nothing is sent to the database before `save()`, and everything runs in the save's transaction.

```php
// list form: just the ids
$user->syncLinks('Groups', array(3, 5, 8));
$user->save();

// id => values: also writes columns of the refClass (join) record
$user->syncLinks('Groups', array(
    3 => array('role' => 'admin'),
    5 => array('role' => 'member'),
    8 => array(),
));
$user->save();

// an empty array removes every link
$user->syncLinks('Groups', array());
$user->save();
```

Supported relations:

| Relation | New ids | Ids already linked | Ids no longer listed |
| --- | --- | --- | --- |
| Many-to-many (`refClass`) | a join record is inserted with the given values | the join record is updated if its values changed | the join record is deleted |
| One-to-many (`hasMany` with a foreign key) | the related record's foreign key is set to this record, and the given values are written to it | the given values are written to the related record | the foreign key is set to `NULL` (the record is kept) |

Behavior details:

- **New records.** `syncLinks()` works on a record that isn't saved yet: `save()` inserts it first, then its links.
- **Only changed rows are written.** A link whose values are unchanged gets no `UPDATE`.
- **Hooks and validation run.** Links are written as records, so hooks, listeners, validators, `Timestampable` and optimistic locking on the join or related model all apply.
- **Unknown ids.** For one-to-many relations, an id that doesn't exist makes `save()` throw `Doctrine_Record_Exception`, and the whole save is rolled back. For many-to-many relations, the ids are not checked beforehand, so use a foreign key constraint on the join table to reject unknown ids.
- **Wins over loaded collections.** The set passed to `syncLinks()` is applied after any changes you made to a loaded relation collection, so it decides the final state. After the save the relation is reloaded, so `$user->Groups` shows the new set.
- **Before save, the relation shows the old set.** Until `save()` runs, `$user->Groups` still returns the links as they are in the database.
- **Combining with `link()` and `unlink()`.** Called after `syncLinks()` on the same alias (without `$now = true`), `link()` adds ids to the pending set and `unlink()` removes them. Calling `syncLinks()` again replaces the set.
- **Pending state.** Use `getPendingSyncs()` to read it and `resetPendingSyncs()` to discard it.
- **Invalid calls.** These throw `Doctrine_Record_Exception` right away, before anything is queued:
  - an unknown alias;
  - a relation that is neither many-to-many nor one-to-many;
  - a model with a composite primary key;
  - a mix of plain ids and `id => values`;
  - a value for a field that doesn't exist, or for the link's own key columns.

`fromArray()` and `synchronizeWithArray()` don't use `syncLinks()`. They keep their original 1.2 behavior.

## Running the tests

The suite uses its own test harness, not PHPUnit:

```bash
cd tests
php run.php                         # everything
php run.php --group record          # one group
php run.php --filter SyncLinks      # test classes whose name contains the text
```

## License

LGPL, see [LICENSE](LICENSE).
