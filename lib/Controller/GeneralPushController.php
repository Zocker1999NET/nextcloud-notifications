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

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Security\IdentityProof\Key;
use OC\Security\IdentityProof\Manager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;

abstract class GeneralPushController extends OCSController {

	public const LOCAL_DOMAIN_SUFFIXES = [
		// sorted alphabetically
		// omit optional trailing separator '.' here
		'internal',  // from Appendix G, RFC 6762
		'local',  // used by mDNS, RFC 6762
		'localhost',  // Section 6.3, RFC 6761
	];


	/** @var ISession */
	protected ISession $session;

	/** @var IUserSession */
	protected IUserSession $userSession;

	/** @var IProvider */
	protected IProvider $tokenProvider;

	/** @var Manager */
	protected Manager $identityProof;

	public function __construct(string $appName,
								IRequest $request,
								ISession $session,
								IUserSession $userSession,
								IProvider $tokenProvider,
								Manager $identityProof) {
		parent::__construct($appName, $request);
		$this->session = $session;
		$this->userSession = $userSession;
		$this->tokenProvider = $tokenProvider;
		$this->identityProof = $identityProof;
	}

	/**
	 * Verifies that a given URI is safe to use for notification purposes.
	 *
	 * First an URI needs to meet the requirements of RFC2396
	 * Second an URI needs to meet one of the following requirements to be considered safe:
	 * - either https:// is used (not http://)
	 * - or a local host is addressed using http:// (see $this->verifyNonLocalHost for more info)
	 *
	 * @return bool if URI seems safe to use
	 */
	protected function isUriSafe(string $uri): bool {
		if (\filter_var($uri, FILTER_VALIDATE_URL) === false) {
			return false;
		}
		$parts = \parse_url($uri);
		// scheme & host (at least when scheme != "file") are required & so expected to be set
		if ($parts['scheme'] === 'https') {
			// https is allowed for everyone
			return true;
		}
		if ($parts['scheme'] === 'http') {
			// http is only allowed for local connections
			if ($this->isLocalHost($parts['host'])) {
				return true;
			}
			return false;
		}
		// other schemes are not supported
		return false;
	}

	/**
	 * Checks that a host is a local one.
	 *
	 * See self::LOCAL_DOMAIN_SUFFIXES for a list of considered local domains / domain suffixes.
	 *
	 * @return bool If host seems local
	 */
	protected function isLocalHost(string $host): bool {
		$escape = function ($s) {
			return \preg_quote($s, '/');
		};
		$suffixesRe = \implode('|', \array_map($escape, self::LOCAL_DOMAIN_SUFFIXES));
		// (^|\.) = separated domain label
		// \.?$ = trailing separators might be used
		$domainRe = '/(^|\.)(' . $suffixesRe . ')\.?$/';
		if (\preg_match($domainRe, $host)) {
			return false;
		}
		return true;
	}

	/**
	 * Verifies if the given string represents a valid public key for OpenSSL to support.
	 *
	 * @return ?DataResponse Null if public key is in a valid format, otherwise a valid HTTP response with an error description.
	 */
	protected function verifyPublicKeyOrError(string $publicKey): ?DataResponse {
		if (
			\strpos($publicKey, '-----BEGIN PUBLIC KEY-----' . "\n") !== 0 ||
			((\strlen($publicKey) !== 450 || \strpos($publicKey, "\n" . '-----END PUBLIC KEY-----') !== 425) &&
				(\strlen($publicKey) !== 451 || \strpos($publicKey, "\n" . '-----END PUBLIC KEY-----' . "\n") !== 425))
			) {
			return new DataResponse(['message' => 'INVALID_DEVICE_KEY'], Http::STATUS_BAD_REQUEST);
		}
		return null;
	}

	/**
	 * Checks if user is logged in & and returns its object.
	 *
	 * @return ?IUser None if user is not logged in else IUser object.
	 */
	protected function getUser(): ?IUser {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user;
		}
		return null;
	}

	protected function getSessionToken(): ?IToken {
		$tokenId = $this->session->get('token-id');
		if (!\is_int($tokenId)) {
			return null;
		}
		try {
			return $this->tokenProvider->getTokenById($tokenId);
		} catch (InvalidTokenException $e) {
			return null;
		}
	}

	protected function determineAppType(): string {
		if ($this->request->isUserAgent([
			IRequest::USER_AGENT_TALK_ANDROID,
			IRequest::USER_AGENT_TALK_IOS,
		])) {
			return 'talk';
		} elseif ($this->request->isUserAgent([
			IRequest::USER_AGENT_CLIENT_ANDROID,
			IRequest::USER_AGENT_CLIENT_IOS,
		])) {
			return 'nextcloud';
		}
		return 'unknown';
	}

	/**
	 * @param IToken
	 * @param IUser
	 * @return string[] first deviceIdentifier hashed as SHA512, second the OpenSSL signature
	 */
	protected function signAndHashDeviceIdentifier(IToken $token, IUser $user, Key $key) {
		$deviceIdentifier = json_encode([$user->getCloudId(), $token->getId()]);
		openssl_sign($deviceIdentifier, $signature, $key->getPrivate(), OPENSSL_ALGO_SHA512);
		/**
		 * For some reason the push proxy's golang code needs the signature
		 * of the deviceIdentifier before the sha512 hashing. Assumption is that
		 * openssl_sign already does the sha512 internally.
		 */
		$deviceIdentifier = base64_encode(hash('sha512', $deviceIdentifier, true));
		return [$deviceIdentifier, $signature];
	}

}
