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

use OCA\Notifications\Devices\ProxyPush\ProxyPushDeviceManager;

/**
 * SharedDeviceManager which couples all known & supported DeviceManager together
 * by relying on Dependency Injection
 */
class AllDeviceManager extends SharedDeviceManager {

	public function __construct(
		ProxyPushDeviceManager $proxyDeviceManager,
		//UnifiedPushDeviceManager $unifiedPushDeviceManager,
	) {
		// TODO enable UP DM when fully implemented
		parent::__construct([
			$proxyDeviceManager,
			//$unifiedPushDeviceManager,
		]);
	}

}
