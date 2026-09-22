# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

`zfbase/doctrine1` — a maintenance fork of Doctrine 1.2 (the PHP 5.2-era ActiveRecord ORM + DBAL), kept alive on modern PHP. `composer.json` requires `php: ^8.0` and `ext-pdo`; there are no other dependencies and no dev dependencies. `Doctrine_Core::VERSION` is `1.2.4`.

Almost all recent history is compatibility work: removing `each()`, fixing implicit nullable parameters, `strtotime` usage, "Array to string conversion" notices, PHP 8.0 type errors, and PHP 8.4/8.5 deprecations, plus improvements to the phpdoc emitted by the model generator. Expect new work to be in the same vein. Preserve the public 1.2 API and the existing style: no namespaces, one class per file, `Doctrine_`-underscored class names, 4-space indent, `if ( ! $x)` spacing. Return types are added only where an SPL interface requires them (`Countable::count(): int`, `Iterator::next(): void`, …); do not type plain Doctrine methods such as `Doctrine_Access::remove()`, because every subclass — including ones in downstream applications — would then have to change or fatal.

## Layout and autoloading

- `lib/Doctrine.php` — empty BC shim; everything lives in `lib/Doctrine/Core.php` (`class Doctrine extends Doctrine_Core`).
- Autoloading is PSR-0 with underscores: `Doctrine_Query_Tokenizer` → `lib/Doctrine/Query/Tokenizer.php`. Composer declares `psr-0`, and `Doctrine_Core::autoload()` does the same mapping by hand (it also special-cases `sfYaml*` → `lib/Doctrine/Parser/sfYaml/`). `Doctrine_Core::modelsAutoload()` resolves generated model classes from the configured models directory.
- `tests/` — the test suite plus ~185 fixture model classes in `tests/models/` and 283 regression cases in `tests/Ticket/`.
- `tools/sandbox/` — a runnable example app (SQLite) wiring up `Doctrine_Cli`; useful as a scratch harness for reproducing behavior.
- `build.xml` is Phing, and only builds PEAR packages; it needs a `build.properties` (copy `build.properties.dev`). It is not part of normal development.

## Running tests

The suite does not use PHPUnit. It uses the in-repo `DoctrineTest` harness (`tests/DoctrineTest.php`, `tests/DoctrineTest/`), driven by `tests/run.php`:

```bash
cd tests
php run.php                              # everything
php run.php --group core                 # one group (see list below)
php run.php --group Doctrine_Query_TestCase   # or a single test class name
php run.php --filter Collection          # substring match on class names
php run.php --ticket 1234                # runs Doctrine_Ticket_1234_TestCase
php run.php --help
```

Options require the `--` prefix even though `--help` prints them with one dash. Groups defined in `run.php`: `tickets`, `driver`, `transaction`, `data_dict`, `sequence`, `export`, `import`, `expression`, `core`, `cli`, `relation`, `data_types`, `behaviors`, `validators`, `db`, `record`, `inheritance`, `search`, `cache`, `migration`, `parser`, `data_fixtures`, `unsorted`, `nestedset`. `tests/index.php` serves the same suite with an HTML reporter over a web server. A few ticket cases are excluded by name in `run.php` (MySQL/PostgreSQL-specific failures).

On PHP 8.5 the suite runs clean of deprecations, warnings and notices, with 449 test cases and 38 known failures. Those failures are long-standing and unrelated to the PHP version: row ordering in queries with no `ORDER BY` (`Doctrine_Query_MultiJoin_TestCase`, `Doctrine_Record_FromArray_TestCase`), tests asserting that PDO returns column values as strings when PHP 8.1+ returns native ints (`Doctrine_Ticket_982_TestCase`, `Doctrine_Record_ZeroValues_TestCase`), libxml emitting `<tag/>` instead of `<tag></tag>` (`Doctrine_Ticket_1674_TestCase`), and three single-assertion cases (`Doctrine_Base_TestCase`, `Doctrine_Record_Filter_TestCase`, `Doctrine_Ticket_1783_TestCase`). Treat that as the baseline: a change is a regression if it adds to it. Running the suite rewrites the committed artifact `tests/tmp/generated/BaseTicket_1527_User.php`, which is simply stale in git.

Test cases extend `Doctrine_UnitTestCase` (`tests/DoctrineTest/Doctrine_UnitTestCase.php`). Its `$driverName` decides the backend: `main` (the default) opens a real in-memory SQLite handle, while any other driver name opens `Doctrine_Adapter_Mock`, which records SQL without executing it — that is how the dialect-specific Export/Import/DataDict/Expression tests assert generated SQL per platform.

## Architecture

**PDO handles.** `Doctrine_Connection::connect()` builds the handle with `PDO::connect()` when it exists (PHP 8.4+), so SQLite connections are `Pdo\Sqlite` and can use `createFunction()` instead of the deprecated `PDO::sqliteCreateFunction()`. A handle injected by the caller may still be a plain `PDO`, so `Doctrine_Connection_Sqlite::_registerFunctions()` checks at runtime and falls back. Adapters implementing `Doctrine_Adapter_Interface` are not PDO at all and need not provide `inTransaction()`; `Doctrine_Transaction::_dbhInTransaction()` handles that.

**Configurable cascade.** `Doctrine_Configurable` is the base of `Doctrine_Manager`, `Doctrine_Connection` and `Doctrine_Table`. `getAttribute()`/`getParam()` walk up `$this->parent` when a key is unset locally, giving the Manager → Connection → Table inheritance chain. Attribute constants (`ATTR_*`, `ERR_*`, `FETCH_*`, `MODEL_LOADING_*`, hydration modes) all live in `Doctrine_Core`.

**Manager → Connection → Table → Record.** `Doctrine_Manager` is a singleton registry of named connections. `Doctrine_Connection` is abstract with one subclass per platform (`Mysql`, `Pgsql`, `Sqlite`, `Mssql`, `Oracle`, `Db2`, plus `Mock`); each composes platform modules (`Doctrine_Connection_Module` subclasses) for `transaction`, `dataDict`, `expression`, `export`, `import`, `sequence`, `unitOfWork`, `formatter`. `Doctrine_Table` holds the in-memory schema for one record class and is the finder/query factory; `Doctrine_Record` is the ActiveRecord instance.

**How a model defines itself.** `Doctrine_Table::initDefinition()` instantiates the record class and calls `setTableDefinition()` (columns, table options) via reflection, then `setUp()` (relations, `actAs`, `hasMany`/`hasOne`). Generated models split this: a `Base*` class carries the definition, the user subclass carries custom code, which is why the generator's phpdoc matters. `Doctrine_Record` magic (`__get`/`__set`, `Doctrine_Access`, `Doctrine_Record_Filter`) resolves column values and lazy relation loads; `Doctrine_Record_State` constants track clean/dirty/proxy/transient.

**Optimistic locking.** Off unless a model sets the `optimisticLocking` table option (`true` for a `version` field, or a field name). When on, `Doctrine_Connection_UnitOfWork` seeds the version to 1 on insert, and on update adds the loaded version to `$identifier` and increments the field, so the UPDATE both matches on the old value and writes the new one; a zero row count raises `Doctrine_Locking_Exception`. This works because `Doctrine_Connection::update()` builds its where clause from the *keys* of `$identifier`, the way `delete()` always has — pass `$checkOption` to `quoteIdentifier()` there, since `Doctrine_Connection_Mssql` flips that argument's default and will otherwise change the emitted SQL. Table options are not inherited, so for class-table inheritance the option is resolved through `joinedParents` (never `parents`, which also lists abstract base classes that cannot be instantiated) and the condition goes only to the table whose column definition owns the field. `Doctrine_Record::replace()` and DQL updates bypass all of this by design.

**Saving.** `$record->save()` delegates to `Doctrine_Connection_UnitOfWork::saveGraph()`, which orders writes across the object graph: `saveRelatedLocalKeys` → insert/update the record → `saveRelatedForeignKeys` / `saveAssociations` (join tables), with `buildFlushTree()` topologically sorting tables by relation dependency. Cascading deletes and integrity actions also run through UnitOfWork and `Doctrine_IntegrityMapper`.

**DQL pipeline.** `Doctrine_Query` (extending `Doctrine_Query_Abstract`) parses DQL with `parseDqlQuery()` using `Doctrine_Query_Tokenizer` and a part-class per clause (`Doctrine_Query_From`, `Where`, `Orderby`, …) under `lib/Doctrine/Query/`. `getSqlQuery()` renders platform SQL (including the LIMIT subquery rewrite for one-to-many joins), `execute()` runs it and hands the result set to `Doctrine_Hydrator`. Hydration drivers in `lib/Doctrine/Hydrator/` implement the hydration modes: `RecordDriver` (object graph), `ArrayDriver`, `ScalarDriver`, `SingleScalarDriver`, `NoneDriver`, plus the `*HierarchyDriver` variants for nested sets. `Doctrine_RawSql` reuses the same hydration path for hand-written SQL.

**Behaviors.** `actAs()` attaches a `Doctrine_Template` (`Timestampable`, `Sluggable`, `SoftDelete`, `Versionable`, `I18n`, `NestedSet`, `Searchable`, `Geographical`), which delegates unknown method calls to the record. Templates that need extra storage own a `Doctrine_Record_Generator` subclass, which builds a whole auxiliary model at runtime (e.g. the `*_version`, `*_translation` and search index tables). Event hooks run through `Doctrine_EventListener` / `Doctrine_Record_Listener` with `Doctrine_Event` payloads.

**Schema round-trip.** `Doctrine_Import_Schema` reads YAML schema into definitions and `Doctrine_Import_Builder` writes PHP model classes (this is the code path the phpdoc-generation commits touch); `Doctrine_Import_<Driver>` reverse-engineers models from a live database. In the other direction `Doctrine_Export_<Driver>` emits DDL and `Doctrine_Export_Schema` writes schema files. `Doctrine_Migration` handles versioned migrations with `Doctrine_Migration_Diff` generating them from schema deltas. `Doctrine_Data` imports/exports YAML fixtures.

**CLI.** `Doctrine_Cli` dispatches to `Doctrine_Task_*` classes (`BuildAll`, `GenerateModelsYaml`, `Migrate`, `LoadData`, `Dql`, …); task name, required arguments and description are declared as properties on each task class. `tools/sandbox/doctrine` is a working entry point.
