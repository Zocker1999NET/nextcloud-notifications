<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\NotificationsIntegrationTesting\Controller;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\Notification\IManager;

class EndpointController extends OCSController {
	/** @var IManager */
	private $manager;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IManager $manager
	 */
	public function __construct($appName, IRequest $request, IManager $manager) {
		parent::__construct($appName, $request);
		$this->manager = $manager;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @return DataResponse
	 */
	public function addNotification(string $userId = 'test1') {
		$notification = $this->manager->createNotification();
		$notification->setApp($this->request->getParam('app', 'notificationsintegrationtesting'))
			->setDateTime(\DateTime::createFromFormat('U', $this->request->getParam('timestamp', 1449585176))) // 2015-12-08T14:32:56+00:00
			->setUser($this->request->getParam('user', $userId))
			->setSubject($this->request->getParam('subject', 'testing'))
			->setLink($this->request->getParam('link', 'https://example.tld/'))
			->setMessage($this->request->getParam('message', 'message'))
			->setObject($this->request->getParam('object_type', 'object'), $this->request->getParam('object_id', 23));

		$this->manager->notify($notification);

		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @return DataResponse
	 */
	public function deleteNotifications() {
		$notification = $this->manager->createNotification();
		$this->manager->markProcessed($notification);

		return new DataResponse();
	}
}
