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

namespace OCA\Notifications\Devices\UnifiedPush;

use OCA\Notifications\Devices\Generic\GenericDevice;
use OCP\DB\QueryBuilder\IQueryBuilder;

class UnifiedPushDevice extends GenericDevice {
	private string $upUri;

	public function __construct(
		string $uid,
		int $token,
		string $deviceIdentifier,
		string $devicePublicKey,
		?string $devicePublicKeyHash,
		string $upUri,
		string $appType,
	) {
		parent::__construct(
			$uid,
			$token,
			$deviceIdentifier,
			$devicePublicKey,
			$devicePublicKeyHash,
			$appType,
		);
		$this->upUri = $upUri;
	}

	protected function getInsertValues(): array {
		return array_merge(
			parent::getInsertValues(),
			[
				'upuri' => $this->getUpUri(),
			],
		);
	}

	public function toUpdateQuery(IQueryBuilder $query): void {
		parent::toUpdateQuery($query);
		$query->set(
			'upuri',
			$query->createNamedParameter($this->getUpUri()),
		);
	}

	/**
	 * @return string
	 */
	protected function getUpUri(): string {
		return $this->upUri;
	}
}