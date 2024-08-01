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

namespace OCA\Notifications\Devices\Generic;

use OCA\Notifications\Devices\DeviceManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

abstract class GenericDeviceManager implements DeviceManager {
	private IDBConnection $db;

	protected string $tableName;

	public function __construct(IDBConnection $db, string $tableName) {
		$this->db = $db;
		$this->tableName = $tableName;
	}

	/**
	 * @param GenericDevice $device
	 * @return bool If the device was new/changed to the database
	 */
	public function saveDevice(GenericDevice $device): bool {
		if (!$this->checkIfSaved($device)) {
			return $this->insertDevice($device);
		}
		return $this->updateDevice($device);
	}

	public function deleteByDevice(GenericDevice $device): bool {
		// do not re-use deleteByToken here, a device might redefine the key
		// (e.g. to support multiple registrations per device)
		$query = $this->db->getQueryBuilder();
		$query->delete($this->tableName);
		$device->addWhereKeyToQuery($query);
		return $query->executeStatement() !== 0;
	}

	public function deleteByUidToken(string $uid, int $token): bool {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->tableName);
		GenericDevice::queryForUidToken($query, $uid, $token);
		return $query->executeStatement() !== 0;
	}

	public function deleteByToken(int $token): bool {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->tableName);
		GenericDevice::queryForToken($query, $token);
		return $query->executeStatement() !== 0;
	}

	public function deleteByDeviceIdentifier(string $deviceIdentifier): bool {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->tableName);
		GenericDevice::queryForDeviceIdentifier($query, $deviceIdentifier);
		return $query->executeStatement() !== 0;
	}

	/**
	 * @param callable $callback
	 * @return bool If any entry was deleted
	 * @throws \OCP\DB\Exception
	 */
	protected function deleteByCallback(callable $callback): bool {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->tableName);
		$callback($query);
		return $query->executeStatement() !== 0;
	}

	protected function checkIfSaved(GenericDevice $device): bool {
		return !is_null(
			$this->queryDevice($device->getUID(), $device->getToken()),
		);
	}

	/**
	 * @param string $uid
	 * @return GenericDevice[]
	 */
	public function getDevicesForUser(string $uid): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->tableName);
		GenericDevice::queryForUid($query, $uid);
		$result = $query->executeQuery();

		$devices = [];
		while ($row = $result->fetch()) {
			$devices[] = $this->deviceFromRow($row);
		}

		$result->closeCursor();
		return $devices;
	}

	/**
	 * @param string[] $userIds
	 * @return GenericDevice[][]
	 */
	public function getDevicesForUserList(array $userIds): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->tableName)->where(
				$query->expr()->in(
					'uid',
					$query->createNamedParameter(
						$userIds,
						IQueryBuilder::PARAM_STR_ARRAY,
					),
				),
			);

		$devices = [];
		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			if (!isset($devices[$row['uid']])) {
				$devices[$row['uid']] = [];
			}
			$devices[$row['uid']][] = $this->deviceFromRow($row);
		}

		$result->closeCursor();
		return $devices;
	}

	public function queryDevice(string $uid, int $token): ?GenericDevice {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->tableName);
		GenericDevice::queryForUidToken($query, $uid, $token);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_null($row) ? null : $this->deviceFromRow($row);
	}

	protected function insertDevice(GenericDevice $device): bool {
		$query = $this->db->getQueryBuilder();
		$query->insert($this->tableName);
		$device->toInsertQuery($query);
		return $query->executeStatement() > 0;
	}

	protected function updateDevice(GenericDevice $device): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->tableName);
		$device->toUpdateQuery($query);
		$device->addWhereKeyToQuery($query);
		return $query->executeStatement() !== 0;
	}

	abstract protected function deviceFromRow(array $row): GenericDevice;

	public static function arrayToNamedParameters(
		IQueryBuilder $query,
		array $values,
	): array {
		$ret = [];
		foreach ($values as $key => $val) {
			$ret[$key] = $query->createNamedParameter(
				$val,
				is_int($val) ? IQueryBuilder::PARAM_INT :
					IQueryBuilder::PARAM_STR,
			);
		}
		return $ret;
	}

}
