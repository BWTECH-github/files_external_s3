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

namespace OCA\FilesExternalS3\Migration;

use OCA\FilesExternalS3\Storage\AmazonS3;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Übernimmt bestehende Speicher-IDs in die neuen: oc_storages.id wird
 * umbenannt, numeric_id bleibt - und mit ihr Dateicache, Datei-IDs, Freigaben
 * und Tags. Zwei Schritte, je einer je Migration:
 *
 * run() (3.0.1): von der ID aus dem Bucket-Namen zur ID aus Bucket und
 * Endpunkt. Teilen sich mehrere Einhängungen eine alte ID, obwohl sie an
 * verschiedenen Endpunkten hängen, behält die älteste (kleinste mount_id) den
 * Dateicache.
 *
 * separateOwnMounts() (3.0.2): Die eigene Einhängung eines Benutzers bekommt
 * eine ID mit seiner Kennung. Verwendet eine Einhängung der Verwaltung
 * dieselbe ID, bleibt der Dateicache bei ihr; sonst behält ihn die älteste
 * eigene Einhängung.
 *
 * Wer leer ausgeht, liest neu ein - wem welcher Inhalt im gemeinsamen Cache
 * gehörte, lässt sich nicht mehr trennen. Das steht als Warnung im Protokoll.
 *
 * Ein zweiter Lauf ändert nichts: Danach gibt es die alte ID nicht mehr, und
 * eine vorhandene neue ID wird nie überschrieben.
 *
 * Gelesen wird direkt aus den Tabellen der Einhängungen, ohne den
 * Speicherdienst des Kerns: Er ließe Einhängungen aus, deren Speicherart
 * gerade nicht geladen ist.
 */
class StorageIdRename implements IRepairStep {
	private const BACKEND = 'files_external_s3';

	/** oc_external_mounts.type und oc_external_applicable.type des Kerns */
	private const MOUNT_TYPE_PERSONAL = 2;
	private const APPLICABLE_TYPE_USER = 3;

	/** Angaben, aus denen die Speicher-ID entsteht */
	private const ID_OPTIONS = ['bucket', 'hostname', 'port', 'use_ssl'];

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private ILogger $logger
	) {
	}

	#[\Override]
	public function getName() {
		return 'Rename S3 storage ids to bucket and endpoint';
	}

	#[\Override]
	public function run(IOutput $output) {
		$byLegacyId = [];
		foreach ($this->readMounts() as $mountId => $mount) {
			foreach ($this->resolveUserPlaceholder($mount['options']) as $params) {
				if (($params['bucket'] ?? '') !== '') {
					$byLegacyId[self::adjust(AmazonS3::legacyStorageId($params))][] = [
						'mountId' => $mountId,
						'newId' => self::adjust(AmazonS3::storageId($params)),
					];
				}
			}
		}

		$renamed = $this->renameEach($output, $byLegacyId, 'although they use different endpoints');
		$output->info("files_external_s3: {$renamed} storage ids renamed to bucket and endpoint");
	}

	/**
	 * Eigene Einhängungen der Benutzer von der gemeinsamen ID lösen.
	 */
	public function separateOwnMounts(IOutput $output): void {
		$mounts = $this->readMounts();

		// IDs, die Einhängungen der Verwaltung weiter verwenden
		$shared = [];
		foreach ($mounts as $mountId => $mount) {
			if ($mount['type'] === self::MOUNT_TYPE_PERSONAL) {
				continue;
			}
			foreach ($this->resolveUserPlaceholder($mount['options']) as $params) {
				if (($params['bucket'] ?? '') !== '') {
					$shared[self::adjust(AmazonS3::storageId($params))] = $mountId;
				}
			}
		}

		$bySharedId = [];
		foreach ($mounts as $mountId => $mount) {
			if ($mount['type'] !== self::MOUNT_TYPE_PERSONAL || $mount['owner'] === null) {
				continue;
			}
			$params = $this->resolveUserPlaceholderFor($mount['options'], $mount['owner']);
			if (($params['bucket'] ?? '') === '') {
				continue;
			}
			$sharedId = self::adjust(AmazonS3::storageId($params));
			if (isset($shared[$sharedId])) {
				if ($this->storageExists($sharedId)) {
					$this->warn(
						$output,
						"Own S3 mount {$mountId} of {$mount['owner']} used the file cache of admin mount {$shared[$sharedId]} "
						. "(storage id '{$sharedId}'). The admin mount keeps it; mount {$mountId} starts with an empty one - "
						. 'shares and tags set there have to be set again.'
					);
				}
				continue;
			}
			$bySharedId[$sharedId][] = [
				'mountId' => $mountId,
				'newId' => self::adjust(AmazonS3::storageId([AmazonS3::OWNER_OPTION => $mount['owner']] + $params)),
			];
		}

		$renamed = $this->renameEach($output, $bySharedId, 'although they belong to different users');
		$output->info("files_external_s3: {$renamed} storage ids of own mounts renamed to their owner");
	}

	/**
	 * Je alter ID: Die erste Einhängung (kleinste mount_id) übernimmt die Zeile,
	 * Einhängungen mit einer anderen neuen ID beginnen leer.
	 *
	 * @param array<string, list<array{mountId: int, newId: string}>> $byOldId
	 * @return int umbenannte Speicher
	 */
	private function renameEach(IOutput $output, array $byOldId, string $reason): int {
		$renamed = 0;
		foreach ($byOldId as $oldId => $mounts) {
			$oldId = (string)$oldId;
			if (!$this->storageExists($oldId)) {
				// Neuinstallation, noch nie eingehängt oder schon übernommen
				continue;
			}

			$keeper = $mounts[0];
			if ($this->storageExists($keeper['newId'])) {
				$this->warn(
					$output,
					"S3 storage id '{$oldId}' of mount {$keeper['mountId']} was not renamed: "
					. "the new id '{$keeper['newId']}' already exists."
				);
				continue;
			}

			$this->renameStorage($oldId, $keeper['newId']);
			$renamed++;

			$others = [];
			foreach ($mounts as $mount) {
				if ($mount['newId'] !== $keeper['newId']) {
					$others[$mount['mountId']] = true;
				}
			}
			if ($others !== []) {
				$emptied = \implode(', ', \array_keys($others));
				$this->warn(
					$output,
					"S3 mounts {$keeper['mountId']}, {$emptied} shared the file cache of storage id '{$oldId}' "
					. "{$reason}. Mount {$keeper['mountId']} keeps the file cache. "
					. "Starting with an empty one: {$emptied} - shares and tags set there have to be set again."
				);
			}
		}

		return $renamed;
	}

	/**
	 * Alle S3-Einhängungen, die der Verwaltung und die eigenen der Benutzer.
	 *
	 * @return array<int, array{type: int, owner: ?string, options: array<string, mixed>}> aufsteigend nach mount_id
	 */
	private function readMounts(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('m.mount_id', 'm.type', 'c.key', 'c.value')
			->from('external_mounts', 'm')
			->leftJoin('m', 'external_config', 'c', $query->expr()->eq('m.mount_id', 'c.mount_id'))
			->where($query->expr()->eq('m.storage_backend', $query->createNamedParameter(self::BACKEND)))
			->orderBy('m.mount_id', 'ASC');
		$result = $query->execute();
		$rows = $result->fetchAllAssociative();
		$result->free();

		$mounts = [];
		foreach ($rows as $row) {
			$mountId = (int)$row['mount_id'];
			$mounts[$mountId] ??= ['type' => (int)$row['type'], 'owner' => null, 'options' => []];
			if (\in_array($row['key'], self::ID_OPTIONS, true)) {
				$mounts[$mountId]['options'][$row['key']] = $row['value'];
			}
		}
		foreach ($mounts as $mountId => $mount) {
			if (\array_key_exists('use_ssl', $mount['options'])) {
				// wie der Kern beim Einhängen: Wahrheitswerte stehen als Text in der Datenbank
				$mounts[$mountId]['options']['use_ssl'] = (bool)$mount['options']['use_ssl'];
			}
		}

		foreach ($this->readOwners() as $mountId => $owners) {
			// Eine eigene Einhängung gehört genau einem Benutzer
			if (isset($mounts[$mountId]) && \count($owners) === 1) {
				$mounts[$mountId]['owner'] = $owners[0];
			}
		}

		return $mounts;
	}

	/**
	 * @return array<int, list<string>> mount_id => Benutzer, für die die Einhängung gilt
	 */
	private function readOwners(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('a.mount_id', 'a.value')
			->from('external_applicable', 'a')
			->innerJoin('a', 'external_mounts', 'm', $query->expr()->eq('m.mount_id', 'a.mount_id'))
			->where($query->expr()->eq('m.storage_backend', $query->createNamedParameter(self::BACKEND)))
			->andWhere($query->expr()->eq('a.type', $query->createNamedParameter(self::APPLICABLE_TYPE_USER, IQueryBuilder::PARAM_INT)));
		$result = $query->execute();
		$rows = $result->fetchAllAssociative();
		$result->free();

		$owners = [];
		foreach ($rows as $row) {
			$owners[(int)$row['mount_id']][] = (string)$row['value'];
		}

		return $owners;
	}

	/**
	 * Der Kern ersetzt „$user“ in den Angaben einer Einhängung durch den
	 * Anmeldenamen: Dann hat jeder Benutzer seinen eigenen Speicher.
	 *
	 * @param array<string, mixed> $options
	 * @return list<array<string, mixed>>
	 */
	private function resolveUserPlaceholder(array $options): array {
		if (self::placeholders($options) === []) {
			return [$options];
		}

		$resolved = [];
		$this->userManager->callForSeenUsers(function (IUser $user) use ($options, &$resolved) {
			$resolved[] = self::replaceUser($options, $user->getUserName());
		});

		return $resolved;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function resolveUserPlaceholderFor(array $options, string $userId): array {
		if (self::placeholders($options) === []) {
			return $options;
		}
		$user = $this->userManager->get($userId);

		return self::replaceUser($options, $user === null ? $userId : $user->getUserName());
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, string>
	 */
	private static function placeholders(array $options): array {
		return \array_filter($options, static fn ($value) => \is_string($value) && \str_contains($value, '$user'));
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function replaceUser(array $options, string $userName): array {
		foreach (self::placeholders($options) as $key => $value) {
			$options[$key] = \str_replace('$user', $userName, $value);
		}

		return $options;
	}

	private function storageExists(string $storageId): bool {
		$query = $this->db->getQueryBuilder();
		$query->select('numeric_id')
			->from('storages')
			->where($query->expr()->eq('id', $query->createNamedParameter($storageId)));
		$result = $query->execute();
		$exists = $result->fetchOne() !== false;
		$result->free();

		return $exists;
	}

	private function renameStorage(string $oldId, string $newId): void {
		$query = $this->db->getQueryBuilder();
		$query->update('storages')
			->set('id', $query->createNamedParameter($newId))
			->where($query->expr()->eq('id', $query->createNamedParameter($oldId)));
		$query->execute();
	}

	/** Wie der Kern: IDs über 64 Zeichen stehen als md5 in oc_storages */
	private static function adjust(string $storageId): string {
		return \strlen($storageId) > 64 ? \md5($storageId) : $storageId;
	}

	private function warn(IOutput $output, string $message): void {
		$this->logger->warning($message, ['app' => self::BACKEND]);
		$output->warning($message);
	}
}
