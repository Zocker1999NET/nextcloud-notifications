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

use OCP\Http\Client\IClient;

interface Payload {

	/**
	 * Gets a key describing the target of the payload.
	 *
	 * It may be used to allow grouping multiple pushes into one connection
	 * by sending them using the same HTTP client
	 * or by using separate clients per target.
	 *
	 * It MUST NOT be used for determining where to send a notification.
	 *
	 * @return string
	 */
	public function getTargetKey(): string;

	/**
	 * Tries to group the current and the given payloads together.
	 *
	 * May modify one of both to create the combined one.
	 * The one returned will be the one with the data of both.
	 *
	 * Can return null always if grouping this kind of Payload is never supported.
	 *
	 * @param Payload $payload
	 * @return Payload|null Null if they cannot be grouped together or a Payload with both notifications encoded.
	 */
	public function groupWith(Payload $payload): ?Payload;

	/**
	 * @param IClient $client
	 * @param PushArgs $args
	 */
	public function send(IClient $client, PushArgs $args): void;
}
