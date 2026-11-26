<?php
/**
 * @author BW-Tech GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
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

namespace OCA\files_external_s3\Migrations;

use OCA\FilesExternalS3\Migration\StorageIdRename;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\ISimpleMigration;

/**
 * 3.0.2: Die eigene Einhängung eines Benutzers bekommt eine Speicher-ID mit
 * seiner Kennung und damit einen eigenen Dateicache. Bisher öffnete sie den
 * Dateicache jeder Einhängung desselben Buckets am selben Endpunkt - auch
 * mit Schlüsseln, die nicht stimmen.
 */
class Version20261126000001 implements ISimpleMigration {
	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private ILogger $logger
	) {
	}

	#[\Override]
	public function run(IOutput $out) {
		(new StorageIdRename($this->db, $this->userManager, $this->logger))->separateOwnMounts($out);
	}
}
