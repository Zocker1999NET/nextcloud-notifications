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

use OCA\Notifications\Devices\Device;
use OCP\DB\QueryBuilder\IQueryBuilder;

abstract class GenericDevice implements Device {
	private string $uid;
	private int $token;
	private string $deviceIdentifier;
	private string $devicePublicKey;
	private string $devicePublicKeyHash;
	private string $appType;

	public function __construct(
		string $uid,
		int $token,
		string $deviceIdentifier,
		string $devicePublicKey,
		?string $devicePublicKeyHash,
		string $appType,
	) {
		$this->uid = $uid;
		$this->token = $token;
		$this->deviceIdentifier = $deviceIdentifier;
		$this->devicePublicKey = $devicePublicKey;
		$this->devicePublicKeyHash = $devicePublicKeyHash ?: hash('sha512', $devicePublicKey);
		$this->appType = $appType;
	}

	final public function toInsertQuery(IQueryBuilder $query): void {
		$query->values(
			GenericDeviceManager::arrayToNamedParameters(
				$query,
				$this->getInsertValues(),
			),
		);
	}

	protected function getInsertValues(): array {
		return [
			'uid' => $this->getUID(),
			'token' => $this->getToken(),
			'deviceidentifier' => $this->getDeviceIdentifier(),
			'devicepublickey' => $this->getDevicePublicKey(),
			'devicepublickeyhash' => $this->getDevicePublicKeyHash(),
			'apptype' => $this->getAppType(),
		];
	}

	public function toUpdateQuery(IQueryBuilder $query): void {
		$query->set(
			'devicepublickey',
			$query->createNamedParameter($this->getDevicePublicKeyHash()),
		)->set(
			'devicepublickeyhash',
			$query->createNamedParameter($this->getDevicePublicKeyHash()),
		)->set('apptype', $query->createNamedParameter($this->getAppType()));
	}

	public function addWhereKeyToQuery(IQueryBuilder $query): void {
		GenericDevice::queryForUidToken($query, $this->getUID(), $this->getToken());
	}

	public static function queryForUidToken(IQueryBuilder $query, string $uid, int $token): void {
		self::queryForUid($query, $uid);
		self::queryForToken($query, $token);
	}

	public static function queryForUid(IQueryBuilder $query, string $uid): void {
		$query->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid)));
	}

	public static function queryForToken(IQueryBuilder $query, int $token): void {
		$query->andWhere($query->expr()->eq('token', $query->createNamedParameter($token, IQueryBuilder::PARAM_INT)));
	}

	public static function queryForDeviceIdentifier(IQueryBuilder $query, string $deviceIdentifier): void {
		$query->andWhere($query->expr()->eq('deviceidentifier', $query->createNamedParameter($deviceIdentifier)));
	}

	public function getUID(): string {
		return $this->uid;
	}

	public function getToken(): int {
		return $this->token;
	}

	public function getDeviceIdentifier(): string {
		return $this->deviceIdentifier;
	}

	protected function getDevicePublicKey(): string {
		return $this->devicePublicKey;
	}

	protected function getDevicePublicKeyHash(): string {
		return $this->devicePublicKeyHash;
	}

	public function getAppType(): string {
		return $this->appType;
	}

	public function isAppType(string $appType): bool {
		return $this->appType === $appType;
	}

}
