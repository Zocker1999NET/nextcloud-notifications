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

namespace OCA\Notifications\Data;

use OC\Security\IdentityProof\Key;
use OCA\Notifications\FakeUser;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Collects required objects for preparing a notification.
 *
 * Explicitly excludes objects required for pushing the notification.
 */
final class NotificationArgs {

	public function __construct(
		public Key $userKey,
		public LoggerInterface $logger,
	) {
	}

	private static function createFakeUserObject(string $userId): IUser {
		return new FakeUser($userId);
	}

}
