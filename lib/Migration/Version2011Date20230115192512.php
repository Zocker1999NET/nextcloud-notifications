<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Felix Stupp <me+github@banananet.work>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Notifications\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Recreate notifications_pushtoken(s) with a primary key for cluster support
 */
class Version2011Date20230115192512 extends SimpleMigrationStep {

	/** @var IDBConnection */
	protected $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('notifications_uptokens')) {
			$table = $schema->createTable('notifications_uptokens');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('token', Types::INTEGER, [
				'notnull' => true,
				'length' => 4,
				'default' => 0,
			]);
			$table->addColumn('deviceidentifier', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$table->addColumn('devicepublickey', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('devicepublickeyhash', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$table->addColumn('upuri', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('apptype', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'unknown',
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uid', 'token'], 'oc_nup_tokens_uid');
		}
		return $schema;
	}
}
