<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Notifications;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Security\IdentityProof\Key;
use OC\Security\IdentityProof\Manager;
use OCA\Notifications\Data\NotificationArgs;
use OCA\Notifications\Data\NotificationDeleteSet;
use OCA\Notifications\Data\NotificationSet;
use OCA\Notifications\Data\Payload;
use OCA\Notifications\Data\PushArgs;
use OCA\Notifications\Devices\AllDeviceManager;
use OCA\Notifications\Devices\Device;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Push {
	// We don't push to devices that are older than 60 days
	public const TOKEN_MAX_AGE = 60 * 24 * 60 * 60;

	/** @var AllDeviceManager */
	protected AllDeviceManager $deviceManager;
	/** @var INotificationManager */
	protected $notificationManager;
	/** @var IConfig */
	protected $config;
	/** @var IProvider */
	protected $tokenProvider;
	/** @var Manager */
	private $keyManager;
	/** @var IClientService */
	protected $clientService;
	/** @var ICache */
	protected $cache;
	/** @var IUserStatusManager */
	protected $userStatusManager;
	/** @var IFactory */
	protected $l10nFactory;
	/** @var LoggerInterface */
	protected $log;
	/** @var OutputInterface */
	protected $output;
	/** @var Payload[] */
	protected array $payloadsToSend = [];

	/** @var bool */
	protected $deferPreparing = false;
	/** @var bool */
	protected $deferPayloads = false;
	/** @var array[] */
	protected $deletesToPush = [];
	/** @var INotification[] */
	protected $notificationsToPush = [];

	/** @var null[]|IUserStatus[] */
	protected $userStatuses = [];
	/** @var array[] */
	protected $userDevices = [];
	/** @var string[] */
	protected $loadDevicesForUsers = [];
	/** @var string[] */
	protected $loadStatusForUsers = [];

	/**
	 * A very small and privileged list of apps that are allowed to push during DND.
	 * @var bool[]
	 */
	protected $allowedDNDPushList = [
		'twofactor_nextcloud_notification' => true,
	];

	public function __construct(
		AllDeviceManager $targetManager,
		INotificationManager $notificationManager,
		IConfig $config,
		IProvider $tokenProvider,
		Manager $keyManager,
		IClientService $clientService,
		ICacheFactory $cacheFactory,
		IUserStatusManager $userStatusManager,
		IFactory $l10nFactory,
		LoggerInterface $log,
	) {
		$this->deviceManager = $targetManager;
		$this->notificationManager = $notificationManager;
		$this->config = $config;
		$this->tokenProvider = $tokenProvider;
		$this->keyManager = $keyManager;
		$this->clientService = $clientService;
		$this->cache = $cacheFactory->createDistributed('pushtokens');
		$this->userStatusManager = $userStatusManager;
		$this->l10nFactory = $l10nFactory;
		$this->log = $log;
	}

	public function setOutput(OutputInterface $output): void {
		$this->output = $output;
	}

	protected function printInfo(string $message): void {
		$this->output?->writeln($message);
	}

	public function isDeferring(): bool {
		return $this->deferPayloads;
	}

	public function deferPayloads(): void {
		$this->deferPreparing = true;
		$this->deferPayloads = true;
	}

	public function flushPayloads(): void {
		$this->deferPreparing = false;

		if (!empty($this->loadDevicesForUsers)) {
			$this->loadDevicesForUsers = array_unique($this->loadDevicesForUsers);
			$missingDevicesFor = array_diff($this->loadDevicesForUsers, array_keys($this->userDevices));
			$newUserDevices = $this->deviceManager->getDevicesForUserList($missingDevicesFor);
			foreach ($missingDevicesFor as $userId) {
				$this->userDevices[$userId] = $newUserDevices[$userId] ?? [];
			}
			$this->loadDevicesForUsers = [];
		}

		if (!empty($this->loadStatusForUsers)) {
			$this->loadStatusForUsers = array_unique($this->loadStatusForUsers);
			$missingStatusFor = array_diff($this->loadStatusForUsers, array_keys($this->userStatuses));
			$newUserStatuses = $this->userStatusManager->getUserStatuses($missingStatusFor);
			foreach ($missingStatusFor as $userId) {
				$this->userStatuses[$userId] = $newUserStatuses[$userId] ?? null;
			}
			$this->loadStatusForUsers = [];
		}

		if (!empty($this->notificationsToPush)) {
			foreach ($this->notificationsToPush as $id => $notification) {
				$this->pushToDevice($id, $notification);
			}
			$this->notificationsToPush = [];
		}

		if (!empty($this->deletesToPush)) {
			foreach ($this->deletesToPush as $id => $data) {
				$this->pushDeleteToDevice($data['userId'], $id, $data['app']);
			}
			$this->deletesToPush = [];
		}

		$this->deferPayloads = false;
		$this->sendNotifications();
	}

	/**
	 * @param Device[] $devices
	 * @param string $app
	 * @return Device[]
	 */
	public function filterDeviceList(array $devices, string $app): array {
		$isTalkNotification = \in_array($app, ['spreed', 'talk', 'admin_notification_talk'], true);

		$talkDevices = array_filter($devices, static function ($device) {
			return $device->isAppType('talk');
		});
		$otherDevices = array_filter($devices, static function ($device) {
			return !$device->isAppType('talk');
		});

		$this->printInfo('Identified ' . count($talkDevices) . ' Talk devices and ' . count($otherDevices) . ' others.');

		if (!$isTalkNotification) {
			if (empty($otherDevices)) {
				// We only send file notifications to the files app.
				// If you don't have such a device, bye!
				return [];
			}
			return $otherDevices;
		}

		if (empty($talkDevices)) {
			// If you don't have a talk device,
			// we fall back to the files app.
			return $otherDevices;
		}
		return $talkDevices;
	}

	public function pushToDevice(int $notificationId, INotification $notification): void {
		if (!$this->hasInternetConnection()) {
			return;
		}

		if ($this->deferPreparing) {
			$this->notificationsToPush[$notificationId] = clone $notification;
			$this->loadDevicesForUsers[] = $notification->getUser();
			$this->loadStatusForUsers[] = $notification->getUser();
			return;
		}

		$user = $this->createFakeUserObject($notification->getUser());

		if (!$this->isNotificationAllowed($notification)) {
			$this->printInfo('<error>User status is set to DND - no push notifications will be sent</error>');
			return;
		}

		$devices = $this->loadUserDevices($notification->getUser());

		if (empty($devices)) {
			$this->printInfo('No devices found for user');
			return;
		}

		$this->printInfo('Trying to push to ' . count($devices) . ' devices');
		$this->printInfo('');

		$language = $this->l10nFactory->getUserLanguage($user);
		$this->printInfo('Language is set to ' . $language);

		try {
			$this->notificationManager->setPreparingPushNotification(true);
			$notification = $this->notificationManager->prepare($notification, $language);
		} catch (\InvalidArgumentException $e) {
			return;
		} finally {
			$this->notificationManager->setPreparingPushNotification(false);
		}

		$userKey = $this->keyManager->getKey($user);

		$this->printInfo('Private user key size: ' . strlen($userKey->getPrivate()));
		$this->printInfo('Public user key size: ' . strlen($userKey->getPublic()));

		$isTalkNotification = \in_array($notification->getApp(), ['spreed', 'talk', 'admin_notification_talk'], true);
		$devices = $this->filterDeviceList($devices, $notification->getApp());
		if (empty($devices)) {
			return;
		}

		$maxAge = time() - self::TOKEN_MAX_AGE;
		$notificationSet = new NotificationSet(
			$notificationId,
			$notification,
			$isTalkNotification,
		);
		$args = new NotificationArgs(
			$userKey,
			$this->log,
		);

		foreach ($devices as $device) {
			$this->printInfo('');
			$this->printInfo('Device token:' . $device->getToken());

			if (!$this->validateToken($device->getToken(), $maxAge)) {
				// Token does not exist anymore
				continue;
			}

			try {
				$payload = $device->generatePayloadForNotification($notificationSet, $args);
				$this->payloadsToSend[] = $payload;
			} catch (\InvalidArgumentException $e) {
				// Failed to encrypt message for device: public key is invalid
				$this->deviceManager->deleteByToken($device->getToken());
			}
		}

		if (!$this->deferPayloads) {
			$this->sendNotifications();
		}
	}

	public function pushDeleteToDevice(string $userId, int $notificationId, string $app = ''): void {
		if (!$this->hasInternetConnection()) {
			return;
		}

		if ($this->deferPreparing) {
			$this->deletesToPush[$notificationId] = ['userId' => $userId, 'app' => $app];
			$this->loadDevicesForUsers[] = $userId;
			return;
		}

		$user = $this->createFakeUserObject($userId);

		$devices = $this->loadUserDevices($userId);

		$deleteSet = new NotificationDeleteSet(
			$userId,
			$notificationId,
			$app,
		);

		if (!$deleteSet->isDeleteAll() && $app !== '') {
			// Only filter when it's not a single delete
			$devices = $this->filterDeviceList($devices, $app);
		}
		if (empty($devices)) {
			return;
		}

		$maxAge = time() - self::TOKEN_MAX_AGE;
		$args = new NotificationArgs(
			$this->keyManager->getKey($user),
			$this->log,
		);

		foreach ($devices as $device) {
			if (!$this->validateToken($device->getToken(), $maxAge)) {
				// Token does not exist anymore
				continue;
			}

			try {
				$payload = $device->generatePayloadForDelete($deleteSet, $args);
				$this->payloadsToSend[] = $payload;
			} catch (\InvalidArgumentException $e) {
				// Failed to encrypt message for device: public key is invalid
				$this->deviceManager->deleteByToken($device->getToken());
			}
		}

		if (!$this->deferPayloads) {
			$this->sendNotifications();
		}
	}

	protected function sendNotifications(): void {
		$payloadsToSend = $this->payloadsToSend;
		$this->payloadsToSend = [];
		if (empty($payloadsToSend)) {
			return;
		}
		// sort by target key to group them together
		usort($payloadsToSend, function (Payload $a, Payload $b) {
			return $a->getTargetKey() <=> $b->getTargetKey();
		});
		$pushArgs = new PushArgs(
			$this->config,
			$this->output,
			$this->log,
			$this->deviceManager,
			$this->notificationManager,
		);
		$client = $this->clientService->newClient();
		$lastPusher = null;
		foreach ($payloadsToSend as $pusher) {
			if (is_null($lastPusher)) {
				$lastPusher = $pusher;
				continue;
			}
			$combined = $lastPusher->groupWith($pusher);
			if (is_null($combined)) {
				$lastPusher->send($client, $pushArgs);
				if ($lastPusher->getTargetKey() !== $pusher->getTargetKey()) {
					$client = $this->clientService->newClient();
				}
				$lastPusher = $pusher;
			} else {
				$lastPusher = $combined;
			}
		}
		$lastPusher->send($client, $pushArgs);
	}

	protected function validateToken(int $tokenId, int $maxAge): bool {
		$age = $this->cache->get('t' . $tokenId);
		if ($age !== null) {
			return $age > $maxAge;
		}

		try {
			// Check if the token is still valid...
			$token = $this->tokenProvider->getTokenById($tokenId);
			$this->cache->set('t' . $tokenId, $token->getLastCheck(), 600);
			if ($token->getLastCheck() > $maxAge) {
				$this->printInfo('Device token is valid');
			} else {
				$this->printInfo('Device token "last checked" is older than 60 days: ' . $token->getLastCheck());
			}
			return $token->getLastCheck() > $maxAge;
		} catch (InvalidTokenException $e) {
			// Token does not exist anymore, should drop the push device entry
			$this->printInfo('InvalidTokenException is thrown');
			$this->deviceManager->deleteByToken($tokenId);
			$this->cache->set('t' . $tokenId, 0, 600);
			return false;
		}
	}

	/**
	 * Loads devices for given user.
	 *
	 * This method caches the results.
	 *
	 * @param string $userId
	 * @return Device[]
	 */
	protected function loadUserDevices(string $userId): array {
		if (!array_key_exists($userId, $this->userDevices)) {
			$devices = $this->deviceManager->getDevicesForUser($userId);
			$this->userDevices[$userId] = $devices;
		} else {
			$devices = $this->userDevices[$userId];
		}
		return $devices;
	}

	/**
	 * Checks if the notification is allowed to be sent depending on the user's DND status.
	 *
	 * @param INotification $notification
	 * @return bool If notification is allowed to be sent
	 */
	protected function isNotificationAllowed(INotification $notification): bool {
		return !$this->isUserDND($notification->getUser()) ||
			!empty($this->allowedDNDPushList[$notification->getApp()]);
	}

	protected function isUserDND(string $userId): bool {
		return $this->loadUserStatus($userId)?->getStatus() === IUserStatus::DND ?? false;
	}

	protected function loadUserStatus(string $userId): ?IUserStatus {
		if (array_key_exists($userId, $this->userStatuses)) {
			$userStatus = $this->userStatuses[$userId];
		} else {
			$userStatuses = $this->userStatusManager->getUserStatuses([$userId]);
			$userStatus = $userStatuses[$userId] ?? null;
			$this->userStatuses[$userId] = $userStatus;
		}
		return $userStatus;
	}

	private function hasInternetConnection(): bool {
		return $this->config->getSystemValueBool('has_internet_connection', true);
	}

	protected function createFakeUserObject(string $userId): IUser {
		return new FakeUser($userId);
	}
}
