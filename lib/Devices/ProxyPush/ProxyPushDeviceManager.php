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

namespace OCA\Notifications\Devices\ProxyPush;

use OCA\Notifications\Devices\Generic\GenericDeviceManager;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProxyPushDeviceManager extends GenericDeviceManager {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'notifications_pushhash');
	}

	/**
	 * @throws Exception
	 */
	protected function insertDevice(ProxyPushDevice $device): bool {
		// In case the auth token is new, delete potentially old entries for the same device (push token) by this user
		$this->deleteByPushTokenHash(
			$device->getUID(),
			$device->getPushTokenHash(),
		);
		return parent::insertDevice($device);
	}

	/**
	 * @param string $uid
	 * @param string $pushTokenHash
	 * @return bool If any entry was deleted
	 * @throws Exception
	 */
	protected function deleteByPushTokenHash(
		string $uid,
		string $pushTokenHash,
	): bool {
		return $this->deleteByCallback(function (IQueryBuilder $query) use (
			$uid,
			$pushTokenHash,
		) {
			$query->where(
					$query->expr()->eq(
						'uid',
						$query->createNamedParameter($uid),
					),
				)->andWhere(
					$query->expr()->eq(
						'pushtokenhash',
						$query->createNamedParameter($pushTokenHash),
					),
				);
		});
	}

	protected function deviceFromRow(array $row): ProxyPushDevice {
		return new ProxyPushDevice(
			$row['uid'],
			$row['token'],
			$row['deviceidentifier'],
			$row['devicepublickey'],
			$row['devicepublickeyhash'],
			$row['pushtokenhash'],
			$row['proxyserver'],
			$row['apptype'],
		);
	}
}
