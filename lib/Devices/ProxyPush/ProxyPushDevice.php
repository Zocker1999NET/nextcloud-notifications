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

namespace OCA\Notifications\Devices\ProxyPush;

use;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Notifications\Data\EncryptSignResult;
use OCA\Notifications\Data\NotificationArgs;
use OCA\Notifications\Data\NotificationDeleteSet;
use OCA\Notifications\Data\NotificationSet;
use OCA\Notifications\Data\Payload;
use OCA\Notifications\Data\PushArgs;
use OCA\Notifications\Devices\Generic\GenericDevice;
use OCA\Notifications\Devices\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClient;
use OCP\Util;

class ProxyPushDevice extends GenericDevice {

	private string $pushTokenHash;
	private string $proxyServer;

	public function __construct(
		string $uid,
		int $token,
		string $deviceIdentifier,
		string $devicePublicKey,
		?string $devicePublicKeyHash,
		string $pushTokenHash,
		string $proxyServer,
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

		$this->pushTokenHash = $pushTokenHash;
		$this->proxyServer = rtrim($proxyServer, '/');
	}

	public function generatePayloadForNotification(NotificationSet $notificationSet, NotificationArgs $args): Payload {
		$notification = $notificationSet->getNotification();
		$data = [
			'nid' => $notificationSet->getNotificationId(),
			'app' => $notification->getApp(),
			'subject' => '',
			'type' => $notification->getObjectType(),
			'id' => $notification->getObjectId(),
		];
		// Max length of encryption is ~240, so we need to make sure the subject is shorter.
		// Also, subtract two for encapsulating quotes will be added.
		$maxDataLength = 200 - strlen(json_encode($data)) - 2;
		$data['subject'] = Util::shortenMultibyteString($notification->getParsedSubject(), $maxDataLength);
		if ($notification->getParsedSubject() !== $data['subject']) {
			$data['subject'] .= '…';
		}

		if ($notificationSet->isTalkNotification()) {
			$priority = 'high';
			$type = $data['type'] === 'call' ? 'voip' : 'alert';
		} elseif ($data['app'] === 'twofactor_nextcloud_notification' || $data['app'] === 'phonetrack') {
			$priority = 'high';
			$type = 'alert';
		} else {
			$priority = 'normal';
			$type = 'alert';
		}

		$dataJson = json_encode($data);
		$result = $this->encryptAndSign($args->userKey->getPrivate(), $dataJson, $args);
		return $this->generatePayload([
			'deviceIdentifier' => $this->getDeviceIdentifier(),
			'pushTokenHash' => $this->pushTokenHash,
			'subject' => $result->getEncryptedBase64(),
			'signature' => $result->getSignatureBase64(),
			'priority' => $priority,
			'type' => $type,
		]);
	}

	public function generatePayloadForDelete(NotificationDeleteSet $notification, NotificationArgs $args): Payload {
		if ($notification->isDeleteAll()) {
			$data = [
				'delete-all' => true,
			];
		} else {
			$data = [
				'nid' => $notification->getNotificationId(),
				'delete' => true,
			];
		}
		$dataJson = json_encode($data);
		$result = $this->encryptAndSign($args->userKey->getPrivate(), $dataJson, $args);
		return $this->generatePayload([
			'deviceIdentifier' => $this->getDeviceIdentifier(),
			'pushTokenHash' => $this->pushTokenHash,
			'subject' => $result->getEncryptedBase64(),
			'signature' => $result->getSignatureBase64(),
			'priority' => 'normal',
			'type' => 'background',
		]);
	}

	/**
	 * Encrypts & signs data for this device.
	 *
	 * @param string $data data to encrypt & sign
	 * @return EncryptSignResult encrypted data & signature, not specially encoded
	 */
	private function encryptAndSign(string $userPrivateKey, string $data, NotificationArgs $args): EncryptSignResult {
		// TODO are printInfo important? How may they be logged using logger than side piped output
		// $this->printInfo('Device public key size: ' . strlen($device->getDevicePublicKey()));
		// $this->printInfo('Data to encrypt is: ' . json_encode($data));
		if (!openssl_public_encrypt(json_encode($data), $encrypted, $this->getDevicePublicKey(), OPENSSL_PKCS1_PADDING)) {
			$error = openssl_error_string();
			$args->logger->error($error, ['app' => 'notifications']);
			// $this->printInfo('Error while encrypting data: "' . $error . '"');
			throw new \InvalidArgumentException('Failed to encrypt message for device');
		}
		if (openssl_sign($encrypted, $signature, $userPrivateKey, OPENSSL_ALGO_SHA512)) {
			// $this->printInfo('Signed encrypted push subject');
		} else {
			// $this->printInfo('Failed to signed encrypted push subject');
		}
		return new EncryptSignResult(
			$encrypted,
			$signature,
		);
	}

	private function generatePayload(array $payload): Payload {
		return new class($this, json_encode($payload)) implements Payload {
			/** @var string[] */
			private array $payloadList = [];

			protected function __construct(private ProxyPushDevice $device, string $payload) {
				$this->payloadList[] = $payload;
			}

			public function getTargetKey(): string {
				return $this->device->getProxyServer();
			}

			public function groupWith(Payload $payload): ?Payload {
				if (
					// verify other payload is from same anonymous class
					get_class($this) !== get_class($payload) ||
					// after payload generation, only proxy server matters for grouping
					$this->device->getProxyServer() === $payload->device->getProxyServer()
				) {
					return null;
				}
				$this->payloadList = array_merge($this->payloadList, $payload->payloadList);
				return $this;
			}

			public function send(IClient $client, PushArgs $args): void {
				// TODO ensure isFairUseOfFreePushService is here
				if (!$args->notificationManager->isFairUseOfFreePushService()) {
					/**
					 * We want to keep offering our push notification service for free, but large
					 * users overload our infrastructure. For this reason we have to rate-limit the
					 * use of push notifications. If you need this feature, consider using Nextcloud Enterprise.
					 */
					return;
				}
				$proxyServer = $this->device->getProxyServer();

				try {
					$requestData = [
						'body' => [
							'notifications' => $this->payloadList,
						],
					];
					if ($proxyServer === 'https://push-notifications.nextcloud.com') {
						$subscriptionKey = $args->config->getAppValue('support', 'subscription_key');
						if ($subscriptionKey) {
							$requestData['headers']['X-Nextcloud-Subscription-Key'] = $subscriptionKey;
						}
					}
					$response = $client->post($proxyServer . '/notifications', $requestData);
					$status = $response->getStatusCode();
					$body = $response->getBody();
					$bodyData = json_decode($body, true);
				} catch (ClientException $e) {
					// Server responded with 4xx (400 Bad Request mostlikely)
					$response = $e->getResponse();
					$status = $response->getStatusCode();
					$body = $response->getBody()->getContents();
					$bodyData = json_decode($body, true);
				} catch (ServerException $e) {
					// Server responded with 5xx
					$response = $e->getResponse();
					$body = $response->getBody()->getContents();
					$error = \is_string($body) ? $body : ('no reason given (' . $response->getStatusCode() . ')');

					$args->logger->debug('Could not send notification to push server [{url}]: {error}', [
						'error' => $error,
						'url' => $proxyServer,
						'app' => 'notifications',
					]);

					$args->printInfo('Could not send notification to push server [' . $proxyServer . ']: ' . $error);
					return;
				} catch (\Exception $e) {
					$args->logger->error($e->getMessage(), [
						'exception' => $e,
					]);

					$error = $e->getMessage() ?: 'no reason given';
					$args->printInfo('Could not send notification to push server [' . get_class($e) . ']: ' . $error);
					return;
				}

				if (is_array($bodyData) && array_key_exists('unknown', $bodyData) && array_key_exists('failed', $bodyData)) {
					if (is_array($bodyData['unknown'])) {
						// Proxy returns null when the array is empty
						foreach ($bodyData['unknown'] as $unknownDevice) {
							$args->printInfo('Deleting device because it is unknown by the push server: ' . $unknownDevice);
							$args->deviceManager->deleteByDeviceIdentifier($unknownDevice);
						}
					}

					if ($bodyData['failed'] !== 0) {
						$args->printInfo('Push notification sent, but ' . $bodyData['failed'] . ' failed');
					} else {
						$args->printInfo('Push notification sent successfully');
					}
				} elseif ($status !== Http::STATUS_OK) {
					$error = $body && $bodyData === null ? $body : 'no reason given';
					$args->printInfo('Could not send notification to push server [' . $proxyServer . ']: ' . $error);
					$args->logger->warning('Could not send notification to push server [{url}]: {error}', [
						'error' => $error,
						'url' => $proxyServer,
						'app' => 'notifications',
					]);
				} else {
					$error = $body && $bodyData === null ? $body : 'no reason given';
					$args->printInfo('Push notification sent but response was not parsable, using an outdated push proxy? [' . $proxyServer . ']: ' . $error);
					$args->logger->info('Push notification sent but response was not parsable, using an outdated push proxy? [{url}]: {error}', [
						'error' => $error,
						'url' => $proxyServer,
						'app' => 'notifications',
					]);
				}
			}
		};
	}

	protected function getInsertValues(): array {
		return array_merge(
			 parent::getInsertValues(),
			 [
			 	'pushtokenhash' => $this->getPushTokenHash(),
			 	'proxyserver' => $this->getProxyServer(),
			 ],
		 );
	}

	public function toUpdateQuery(IQueryBuilder $query): void {
		parent::toUpdateQuery($query);
		$query->set(
				'pushtokenhash',
				$query->createNamedParameter($this->getPushTokenHash()),
			)->set(
				'proxyserver',
				$query->createNamedParameter($this->getProxyServer()),
			);
	}

	/**
	 * @return string
	 */
	protected function getPushTokenHash(): string {
		return $this->pushTokenHash;
	}

	/**
	 * @return string
	 */
	public function getProxyServer(): string {
		return $this->proxyServer;
	}
}
