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
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Übernimmt die Speicher-IDs von vor 3.0.1 (nur der Bucket-Name) in die neuen
 * aus Bucket und Endpunkt: oc_storages.id wird umbenannt, numeric_id bleibt -
 * und mit ihr Dateicache, Datei-IDs, Freigaben und Tags.
 *
 * Teilen sich mehrere Einhängungen eine alte ID, obwohl sie an verschiedenen
 * Endpunkten hängen, behält die älteste (kleinste mount_id) den Dateicache.
 * Die anderen beginnen leer; welcher Inhalt im gemeinsamen Cache wem gehörte,
 * lässt sich nicht mehr trennen. Das steht als Warnung im Protokoll.
 *
 * Ein zweiter Lauf ändert nichts: Nach dem ersten gibt es die alte ID nicht
 * mehr, und eine vorhandene neue ID wird nie überschrieben.
 *
 * Gelesen wird direkt aus external_mounts und external_config: Während des
 * Upgrades ist die App nicht geladen, der Speicherdienst des Kerns kennt die
 * Speicherart dann nicht und ließe ihre Einhängungen aus.
 */
class StorageIdRename implements IRepairStep {
	private const BACKEND = 'files_external_s3';

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
		$renamed = 0;
		foreach ($this->mountsByLegacyId() as $legacyId => $mounts) {
			if (!$this->storageExists($legacyId)) {
				// Neuinstallation, noch nie eingehängt oder schon übernommen
				continue;
			}

			// Die Einhängungen stehen nach mount_id: die erste ist die älteste
			$keeper = $mounts[0];
			if ($this->storageExists($keeper['newId'])) {
				$this->warn(
					$output,
					"S3 storage id '{$legacyId}' of mount {$keeper['mountId']} was not renamed: "
					. "the new id '{$keeper['newId']}' already exists."
				);
				continue;
			}

			$this->renameStorage($legacyId, $keeper['newId']);
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
					"S3 mounts {$keeper['mountId']}, {$emptied} shared the file cache of storage id '{$legacyId}' "
					. "although they use different endpoints. Mount {$keeper['mountId']} keeps the file cache. "
					. "Starting with an empty one: {$emptied} - shares and tags set there have to be set again."
				);
			}
		}

		$output->info("files_external_s3: {$renamed} storage ids renamed to bucket and endpoint");
	}

	/**
	 * @return array<string, list<array{mountId: int, newId: string}>> alte ID => Einhängungen, älteste zuerst
	 */
	private function mountsByLegacyId(): array {
		$byLegacyId = [];
		foreach ($this->readMounts() as $mountId => $options) {
			foreach ($this->resolveUserPlaceholder($options) as $params) {
				if (($params['bucket'] ?? '') === '') {
					continue;
				}
				$byLegacyId[self::adjust(AmazonS3::legacyStorageId($params))][] = [
					'mountId' => $mountId,
					'newId' => self::adjust(AmazonS3::storageId($params)),
				];
			}
		}

		return $byLegacyId;
	}

	/**
	 * Alle S3-Einhängungen, die der Verwaltung und die eigenen der Benutzer.
	 *
	 * @return array<int, array<string, mixed>> mount_id => Angaben, aufsteigend nach mount_id
	 */
	private function readMounts(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('m.mount_id', 'c.key', 'c.value')
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
			$mounts[$mountId] ??= [];
			if (\in_array($row['key'], self::ID_OPTIONS, true)) {
				$mounts[$mountId][$row['key']] = $row['value'];
			}
		}
		foreach ($mounts as $mountId => $options) {
			if (\array_key_exists('use_ssl', $options)) {
				// wie der Kern beim Einhängen: Wahrheitswerte stehen als Text in der Datenbank
				$mounts[$mountId]['use_ssl'] = (bool)$options['use_ssl'];
			}
		}

		return $mounts;
	}

	/**
	 * Der Kern ersetzt „$user“ in den Angaben einer Einhängung durch den
	 * Anmeldenamen: Dann hat jeder Benutzer seinen eigenen Speicher.
	 *
	 * @param array<string, mixed> $options
	 * @return list<array<string, mixed>>
	 */
	private function resolveUserPlaceholder(array $options): array {
		$placeholders = \array_filter($options, static fn ($value) => \is_string($value) && \str_contains($value, '$user'));
		if ($placeholders === []) {
			return [$options];
		}

		$resolved = [];
		$this->userManager->callForSeenUsers(static function (IUser $user) use ($options, $placeholders, &$resolved) {
			foreach ($placeholders as $key => $value) {
				$options[$key] = \str_replace('$user', $user->getUserName(), $value);
			}
			$resolved[] = $options;
		});

		return $resolved;
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

	private function renameStorage(string $legacyId, string $newId): void {
		$query = $this->db->getQueryBuilder();
		$query->update('storages')
			->set('id', $query->createNamedParameter($newId))
			->where($query->expr()->eq('id', $query->createNamedParameter($legacyId)));
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
