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

use OCA\Notifications\Devices\AllDeviceManager;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

// collected into one object so more "world" args can be added
// as required for new notification methods
// without changing existing ones
final class PushArgs {
	public function __construct(
		public IConfig $config,
		private ?OutputInterface $output,
		public LoggerInterface $logger,
		public AllDeviceManager $deviceManager,
		public INotificationManager $notificationManager,
	) {
	}

	public function printInfo(string $message): void {
		$this->output?->writeln($message);
	}

}
