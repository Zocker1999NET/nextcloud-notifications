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

namespace OCA\Notifications\Controller;

use OC\Authentication\Token\IProvider;
use OC\Security\IdentityProof\Manager;
use OCA\Notifications\Devices\ProxyPush\ProxyPushDevice;
use OCA\Notifications\Devices\ProxyPush\ProxyPushDeviceManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

class ProxyPushController extends GeneralPushController {

	private ProxyPushDeviceManager $deviceManager;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		IUserSession $userSession,
		IProvider $tokenProvider,
		Manager $identityProof,
		ProxyPushDeviceManager $deviceManager,
	) {
		parent::__construct(
			$appName,
			$request,
			$session,
			$userSession,
			$tokenProvider,
			$identityProof,
		);
		$this->deviceManager = $deviceManager;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $pushTokenHash
	 * @param string $devicePublicKey
	 * @param string $proxyServer
	 * @return DataResponse
	 */
	public function registerDevice(
		string $pushTokenHash,
		string $devicePublicKey,
		string $proxyServer,
	): DataResponse {
		$user = $this->getUser();
		if (is_null($user)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!preg_match('/^([a-f0-9]{128})$/', $pushTokenHash)) {
			return new DataResponse(['message' => 'INVALID_PUSHTOKEN_HASH'],
				Http::STATUS_BAD_REQUEST);
		}

		if (!is_null($err = $this->verifyPublicKeyOrError($devicePublicKey))) {
			return $err;
		}

		if (\strlen($proxyServer) > 256 || !$this->isUriSafe($proxyServer)) {
			return new DataResponse(['message' => 'INVALID_PROXY_SERVER'],
				Http::STATUS_BAD_REQUEST);
		}

		$token = $this->getSessionToken();
		if (is_null($token)) {
			return new DataResponse(['message' => 'INVALID_SESSION_TOKEN'],
				Http::STATUS_BAD_REQUEST);
		}

		$key = $this->identityProof->getKey($user);
		[$deviceIdentifier, $signature] =
			$this->signAndHashDeviceIdentifier($token, $user, $key);

		$appType = $this->determineAppType();

		$device = new ProxyPushDevice(
			$user->getUID(),
			$token->getId(),
			$deviceIdentifier,
			$devicePublicKey,
			null,  // gets generated automatically
			$pushTokenHash,
			$proxyServer,
			$appType
		);
		$created = $this->deviceManager->saveDevice($device);

		return new DataResponse([
			'publicKey' => $key->getPublic(),
			'deviceIdentifier' => $deviceIdentifier,
			'signature' => base64_encode($signature),
		], $created ? Http::STATUS_CREATED : Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function removeDevice(): DataResponse {
		$user = $this->getUser();
		if (is_null($user)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$token = $this->getSessionToken();
		if (is_null($token)) {
			return new DataResponse(['message' => 'INVALID_SESSION_TOKEN'],
				Http::STATUS_BAD_REQUEST);
		}

		if ($this->deviceManager->deleteByUidToken($user->getUID(), $token->getId())) {
			return new DataResponse([], Http::STATUS_ACCEPTED);
		}

		return new DataResponse([], Http::STATUS_OK);
	}

}
