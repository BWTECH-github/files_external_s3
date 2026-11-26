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

namespace OCA\FilesExternalS3\Tests\Unit;

use OC\Files\Cache\Storage;
use OC\Files\External\StorageConfig;
use OC\Files\Filesystem;
use OC\Files\Mount\MountPoint;
use OC\Files\View;
use OCA\FilesExternalS3\Storage\AmazonS3;
use OCP\Files\External\IStorageConfig;
use Test\TestCase;

/**
 * Dateicache je Speicher-ID. Die eigene Einhängung eines Benutzers darf den
 * Dateicache einer anderen Einhängung desselben Buckets nicht öffnen: Der Kern
 * liefert Namen, Größen und Zeiten aus dem Cache, ohne den Speicher zu fragen
 * („nie auf Änderungen prüfen“, Suche) - die Schlüssel prüft dabei niemand.
 * Der Endpunkt hier ist nicht erreichbar: Was die Tests sehen, kommt aus dem Cache.
 *
 * @group DB
 */
class CacheSeparationTest extends TestCase {
	private const BUCKET = 'cache-test-bucket';
	private const HOST = 's3.cache-test.invalid';
	private const A = 'cache-konto-a';
	private const B = 'cache-konto-b';

	protected function setUp(): void {
		parent::setUp();
		foreach ([self::A, self::B] as $userId) {
			\OC::$server->getUserManager()->get($userId)?->delete();
			\OC::$server->getUserManager()->createUser($userId, 'Cache-Test-2026!');
		}
		$this->removeStorages();
	}

	protected function tearDown(): void {
		self::logout();
		$this->removeStorages();
		foreach ([self::A, self::B] as $userId) {
			\OC::$server->getUserManager()->get($userId)?->delete();
		}
		parent::tearDown();
	}

	private function removeStorages(): void {
		$db = \OC::$server->getDatabaseConnection();
		$query = $db->getQueryBuilder();
		$query->select('id')->from('storages')
			->where($query->expr()->like('id', $query->createNamedParameter('%' . $db->escapeLikeParameter(self::BUCKET . '@' . self::HOST) . '%')));
		$result = $query->execute();
		$ids = $result->fetchFirstColumn();
		$result->free();
		foreach ($ids as $id) {
			Storage::remove($id);
		}
	}

	/** Speicher, wie der Kern ihn für $userId einhängt */
	private function storage(string $userId, int $type, string $key, string $secret): AmazonS3 {
		$backends = \OC::$server->getStoragesBackendService();
		$config = new StorageConfig(990002);
		$config->setMountPoint('/S3');
		$config->setBackend($backends->getBackend('files_external_s3'));
		$config->setAuthMechanism($backends->getAuthMechanism('amazons3::accesskey'));
		$config->setBackendOptions([
			'bucket' => self::BUCKET,
			'hostname' => self::HOST,
			'port' => '',
			'region' => '',
			'use_ssl' => true,
			'use_path_style' => true,
			'key' => $key,
			'secret' => $secret,
		]);
		$config->setType($type);
		if ($type === IStorageConfig::MOUNT_TYPE_PERSONAl) {
			$config->setApplicableUsers([$userId]);
		}
		$user = \OC::$server->getUserManager()->get($userId);
		$config->getAuthMechanism()->manipulateStorageConfig($config, $user);
		$config->getBackend()->manipulateStorageConfig($config, $user);

		return new AmazonS3($config->getBackendOptions());
	}

	/** Der Bucket ist eingelesen: zwei Dateien stehen im Dateicache dieses Speichers */
	private function scan(AmazonS3 $storage): void {
		$cache = $storage->getCache();
		$entry = ['mtime' => 1790000000, 'storage_mtime' => 1790000000, 'permissions' => 31];
		$cache->put('', $entry + ['mimetype' => 'httpd/unix-directory', 'size' => 4096, 'etag' => 'wurzel']);
		$cache->put('gehalt.xlsx', $entry + ['mimetype' => 'application/octet-stream', 'size' => 2048, 'etag' => 'e1']);
		$cache->put('kuendigung.docx', $entry + ['mimetype' => 'application/octet-stream', 'size' => 2048, 'etag' => 'e2']);
	}

	/**
	 * Was $userId unter /S3 sieht und findet, wenn die Einhängung „nie auf
	 * Änderungen prüfen“ gewählt hat.
	 *
	 * @return array{listing: string[], search: string[]}
	 */
	private function seenBy(string $userId, AmazonS3 $storage): array {
		self::loginAsUser($userId);
		Filesystem::getMountManager()->addMount(new MountPoint(
			$storage,
			'/' . $userId . '/files/S3',
			null,
			Filesystem::getLoader(),
			['filesystem_check_changes' => 0]
		));
		$view = new View('/' . $userId . '/files');
		$names = static fn (array $infos): array => \array_values(\array_map(static fn ($info) => $info->getName(), $infos));
		try {
			$listing = $names($view->getDirectoryContent('/S3'));
		} catch (\Exception $e) {
			// Ohne Eintrag im eigenen Dateicache fragt der Kern den Speicher, und der ist nicht erreichbar
			$listing = [];
		}
		$seen = [
			'listing' => $listing,
			'search' => $names($view->search('gehalt')),
		];
		self::logout();

		return $seen;
	}

	public function testOwnMountWithOtherKeysShowsNothingFromTheFileCacheOfTheAdminMount(): void {
		$this->scan($this->storage(self::A, IStorageConfig::MOUNT_TYPE_ADMIN, 'richtiger-schluessel', 'richtig'));

		// Konto B hängt denselben Bucket am selben Endpunkt selbst ein, mit Schlüsseln, die nicht stimmen
		$seen = $this->seenBy(self::B, $this->storage(self::B, IStorageConfig::MOUNT_TYPE_PERSONAl, 'falscher-schluessel', 'falsch'));

		$this->assertSame(['listing' => [], 'search' => []], $seen);
	}

	public function testOwnMountShowsNothingFromTheFileCacheOfAnotherUsersOwnMount(): void {
		$this->scan($this->storage(self::A, IStorageConfig::MOUNT_TYPE_PERSONAl, 'richtiger-schluessel', 'richtig'));

		$seen = $this->seenBy(self::B, $this->storage(self::B, IStorageConfig::MOUNT_TYPE_PERSONAl, 'falscher-schluessel', 'falsch'));

		$this->assertSame(['listing' => [], 'search' => []], $seen);
	}

	public function testOwnerStillSeesHisOwnFileCache(): void {
		$this->scan($this->storage(self::A, IStorageConfig::MOUNT_TYPE_PERSONAl, 'richtiger-schluessel', 'richtig'));

		$seen = $this->seenBy(self::A, $this->storage(self::A, IStorageConfig::MOUNT_TYPE_PERSONAl, 'richtiger-schluessel', 'richtig'));

		$this->assertSame(['gehalt.xlsx', 'kuendigung.docx'], $seen['listing']);
		$this->assertSame(['gehalt.xlsx'], $seen['search']);
	}

	public function testAdminMountKeepsOneFileCacheForAllUsers(): void {
		// Von der Verwaltung hinterlegte Schlüssel gelten für alle: ein gemeinsamer Dateicache, wie bisher
		$this->scan($this->storage(self::A, IStorageConfig::MOUNT_TYPE_ADMIN, 'richtiger-schluessel', 'richtig'));

		$seen = $this->seenBy(self::B, $this->storage(self::B, IStorageConfig::MOUNT_TYPE_ADMIN, 'richtiger-schluessel', 'richtig'));

		$this->assertSame(['gehalt.xlsx', 'kuendigung.docx'], $seen['listing']);
	}
}
