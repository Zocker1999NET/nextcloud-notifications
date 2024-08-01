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

namespace OCA\Notifications\Devices;

use OCA\Notifications\Devices\Generic\GenericDevice;

/**
 * DeviceManager implementation binding multiple specific DeviceManager together as one
 */
class SharedDeviceManager implements DeviceManager {

	/** @var DeviceManager[] */
	private array $deviceManagers;

	/**
	 * @param DeviceManager[] $deviceManagers
	 */
	public function __construct(array $deviceManagers) {
		$this->deviceManagers = $deviceManagers;
	}

	/**
	 * @param string $uid
	 * @return GenericDevice[]
	 */
	public function getDevicesForUser(string $uid): array {
		$ret = [];
		foreach ($this->deviceManagers as $manager) {
			$ret[] = $manager->getDevicesForUser($uid);
		}
		return array_merge(...$ret);
	}

	/**
	 * @param string[] $userIds
	 * @return GenericDevice[][]
	 */
	public function getDevicesForUserList(array $userIds): array {
		$ret = [];
		foreach ($this->deviceManagers as $manager) {
			$map = $manager->getDevicesForUserList($userIds);
			foreach ($map as $uid => $devices) {
				if (!isset($ret[$uid])) {
					$ret[$uid] = [];
				}
				$ret[$uid] = array_merge($ret[$uid], $devices);
			}
		}
		return $ret;
	}

	public function deleteByToken(int $token): bool {
		$ret = false;
		foreach ($this->deviceManagers as $manager) {
			$ret = $ret || $manager->deleteByToken($token);
		}
		return $ret;
	}

	public function deleteByDeviceIdentifier(string $deviceIdentifier): bool {
		$ret = false;
		foreach ($this->deviceManagers as $manager) {
			$ret = $ret || $manager->deleteByDeviceIdentifier($deviceIdentifier);
		}
		return $ret;
	}
}