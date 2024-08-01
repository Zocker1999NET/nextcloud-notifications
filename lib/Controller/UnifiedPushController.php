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
use OCA\Notifications\Devices\UnifiedPush\UnifiedPushDevice;
use OCA\Notifications\Devices\UnifiedPush\UnifiedPushDeviceManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

class UnifiedPushController extends GeneralPushController {

	// from spec
	private const DISCOVERY_UP_KEY = 'unifiedpush';
	private const DISCOVERY_VERSION_KEY = 'version';
	private const DISCOVERY_VERSION_NUM = 1;

	/** @var IClientService */
	protected IClientService $clientService;

	/** @var UnifiedPushDeviceManager */
	protected UnifiedPushDeviceManager $deviceManager;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		IUserSession $userSession,
		IProvider $tokenProvider,
		Manager $identityProof,
		IClientService $clientService,
		UnifiedPushDeviceManager $deviceManager,
	) {
		parent::__construct(
			$appName,
			$request,
			$session,
			$userSession,
			$tokenProvider,
			$identityProof,
		);

		$this->clientService = $clientService;
		$this->deviceManager = $deviceManager;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $upUri
	 * @param string $devicePublicKey
	 * @return DataResponse
	 */
	public function registerDevice(
		string $upUri,
		string $devicePublicKey,
	): DataResponse {
		$user = $this->getUser();
		if (is_null($user)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->verifyPushUri($upUri)) {
			return new DataResponse(['message' => 'INVALID_UNIFIED_PUSH_URI'],
				Http::STATUS_BAD_REQUEST);
		}

		if (!is_null($err = $this->verifyPublicKeyOrError($devicePublicKey))) {
			return $err;
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

		$device = new UnifiedPushDevice(
			$user->getUID(),
			$token->getId(),
			$deviceIdentifier,
			$devicePublicKey,
			null,
			$upUri,
			$appType,
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

	/**
	 * Checks if given URI is for a valid UnifiedPush distributor.
	 *
	 * See https://unifiedpush.org/spec/server/#discovery
	 *
	 * @param string $upUri
	 * @return bool If URI is for a UnifiedPush distributor service
	 */
	protected function verifyPushUri(string $upUri): bool {
		if (\strlen($upUri) > 100 ||  // max by spec
			!$this->isUriSafe($upUri)) {
			return false;
		}
		// check if valid UP endpoint
		$client = $this->clientService->newClient();
		try {
			$response = $client->get($upUri);
		} catch (\Exception $e) {
			// TODO should we do more with failed requests (like forwarding error to user/client?)
			return false;
		}
		if ($response->getStatusCode() != 200) {
			return false;
		}
		$body = $response->getBody();
		$bodyData = json_decode($body, true);
		return (is_array($bodyData) &&
			array_key_exists(self::DISCOVERY_UP_KEY, $bodyData) &&
			is_array($bodyData[self::DISCOVERY_UP_KEY]) &&
			array_key_exists(
				self::DISCOVERY_VERSION_KEY,
				$bodyData[self::DISCOVERY_UP_KEY],
			) &&
			$bodyData[self::DISCOVERY_UP_KEY][self::DISCOVERY_VERSION_KEY] ===
			self::DISCOVERY_VERSION_NUM);
	}

}
