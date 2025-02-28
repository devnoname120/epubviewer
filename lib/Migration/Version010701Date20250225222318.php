<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Migration;

use Closure;
use OC;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

class Version010701Date20250225222318 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $connection) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('reader_bookmarks')) {
			$table = $schema->getTable('reader_bookmarks');
			if ($table->hasColumn('last_modified')) {
				$col = $table->getColumn('last_modified');
				$col->setNotnull(true);
				$col->setDefault(0);
			}
		}

		if ($schema->hasTable('reader_prefs')) {
			$table = $schema->getTable('reader_prefs');
			if ($table->hasColumn('last_modified')) {
				$col = $table->getColumn('last_modified');
				$col->setNotnull(true);
				$col->setDefault(0);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->connection->executeStatement("UPDATE `*PREFIX*reader_bookmarks` SET `last_modified` = 0 WHERE `last_modified` IS NULL");
		$this->connection->executeStatement("UPDATE `*PREFIX*reader_prefs` SET `last_modified` = 0 WHERE `last_modified` IS NULL");
	}
}