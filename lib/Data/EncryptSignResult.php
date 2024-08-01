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

final class EncryptSignResult {

	public function __construct(
		private string $encrypted,
		private string $signature,
	) {
	}

	public function getEncryptedRaw(): string {
		return $this->encrypted;
	}

	public function getSignatureRaw(): string {
		return $this->signature;
	}

	public function getEncryptedBase64(): string {
		return base64_encode($this->encrypted);
	}

	public function getSignatureBase64(): string {
		return base64_decode($this->signature);
	}

}
