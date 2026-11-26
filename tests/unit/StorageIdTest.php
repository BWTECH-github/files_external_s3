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
use OC\Files\External\Service\DBConfigService;
use OCA\FilesExternalS3\Migration\StorageIdRename;
use OCA\FilesExternalS3\Storage\AmazonS3;
use OCP\ILogger;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Speicher-ID aus Bucket und Endpunkt, und die einmalige Übernahme der
 * bisherigen IDs (nur Bucket-Name) beim Upgrade.
 *
 * @group DB
 */
class StorageIdTest extends TestCase {
	private const BUCKET = 'kennung-test-bucket';
	private const HOST_A = 's3.erster-anbieter.example';
	private const HOST_B = 's3.zweiter-anbieter.example';

	private DBConfigService $mounts;
	/** @var int[] */
	private array $mountIds = [];
	/** @var string[] */
	private array $userIds = [];
	/** @var string[] */
	private array $warnings = [];
	/** @var string[] */
	private array $longStorageIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mounts = new DBConfigService(\OC::$server->getDatabaseConnection(), \OC::$server->getCrypto());
		$this->removeStorages();
	}

	protected function tearDown(): void {
		foreach ($this->mountIds as $mountId) {
			$this->mounts->removeMount($mountId);
		}
		foreach ($this->userIds as $userId) {
			$user = \OC::$server->getUserManager()->get($userId);
			if ($user !== null) {
				$user->delete();
			}
		}
		$this->removeStorages();
		parent::tearDown();
	}

	/** Alle Speicherzeilen dieser Tests, samt dem Zwischenspeicher des Kerns */
	private function removeStorages(): void {
		$db = \OC::$server->getDatabaseConnection();
		$query = $db->getQueryBuilder();
		$query->select('id')->from('storages')
			->where($query->expr()->like('id', $query->createNamedParameter('amazon%' . $db->escapeLikeParameter(self::BUCKET) . '%')));
		$result = $query->execute();
		$ids = $result->fetchFirstColumn();
		$result->free();
		// lange Kennungen legt der Kern als md5 ab, die findet das Muster nicht
		foreach (\array_merge($ids, $this->longStorageIds) as $id) {
			Storage::remove($id);
		}
		foreach (['anna', 'ben'] as $userId) {
			foreach ([self::HOST_A, self::HOST_B] as $host) {
				Storage::remove($this->ownId($userId, $host));
			}
		}
	}

	private function params(string $host, array $overrides = []): array {
		return $overrides + [
			'bucket' => self::BUCKET,
			'hostname' => $host,
			'port' => '',
			'region' => '',
			'use_ssl' => true,
			'use_path_style' => true,
		];
	}

	/** Einhängung, wie die Verwaltung (1) oder ein Benutzer (2) sie anlegt */
	private function addMount(array $params, int $type = DBConfigService::MOUNT_TYPE_ADMIN): int {
		$mountId = $this->mounts->addMount('/s3-' . \count($this->mountIds), 'files_external_s3', 'amazons3::accesskey', 100, $type);
		foreach ($params + ['key' => 'schluessel', 'secret' => 'geheim'] as $key => $value) {
			$this->mounts->setConfig($mountId, $key, $value);
		}
		$this->mountIds[] = $mountId;
		return $mountId;
	}

	/** Speicherzeile anlegen, wie der Kern es beim ersten Zugriff tut */
	private function addStorage(string $storageId): int {
		// Der Kern merkt sich Speicherzeilen je Prozess, auch die eines früheren Tests
		Storage::remove($storageId);
		return (new Storage($storageId))->getNumericId();
	}

	private function numericId(string $storageId): ?int {
		$query = \OC::$server->getDatabaseConnection()->getQueryBuilder();
		$query->select('numeric_id')->from('storages')
			->where($query->expr()->eq('id', $query->createNamedParameter(Storage::adjustStorageId($storageId))));
		$result = $query->execute();
		$numericId = $result->fetchOne();
		$result->free();
		return $numericId === false ? null : (int)$numericId;
	}

	private function step(): StorageIdRename {
		$logger = $this->createMock(ILogger::class);
		$logger->method('warning')->willReturnCallback(function ($message) {
			$this->warnings[] = $message;
		});
		return new StorageIdRename(
			\OC::$server->getDatabaseConnection(),
			\OC::$server->getUserManager(),
			$logger
		);
	}

	/** Migration von 3.0.1: ID aus Bucket und Endpunkt */
	private function rename(): void {
		$this->step()->run($this->createMock(IOutput::class));
	}

	/** Migration von 3.0.2: eigene Einhängungen bekommen ihre eigene ID */
	private function separate(): void {
		$this->step()->separateOwnMounts($this->createMock(IOutput::class));
	}

	/** Eigene Einhängung eines Benutzers */
	private function addOwnMount(string $userId, array $params): int {
		$mountId = $this->addMount($params, DBConfigService::MOUNT_TYPE_PERSONAl);
		$this->mounts->addApplicable($mountId, DBConfigService::APPLICABLE_TYPE_USER, $userId);
		return $mountId;
	}

	private function ownId(string $userId, string $host = self::HOST_A): string {
		return AmazonS3::storageId([AmazonS3::OWNER_OPTION => $userId] + $this->params($host));
	}

	private function legacyId(string $bucket = self::BUCKET): string {
		return 'amazon::' . $bucket;
	}

	public function testStorageIdSeparatesEqualBucketNamesOnDifferentEndpoints(): void {
		$first = AmazonS3::storageId($this->params(self::HOST_A));
		$second = AmazonS3::storageId($this->params(self::HOST_B));

		$this->assertNotSame($first, $second);
		$this->assertSame('amazon::' . self::BUCKET . '@' . self::HOST_A . ':443', $first);
	}

	public function testStorageIdSeparatesPortsOfOneHost(): void {
		$this->assertNotSame(
			AmazonS3::storageId($this->params(self::HOST_A, ['port' => '9000'])),
			AmazonS3::storageId($this->params(self::HOST_A, ['port' => '9001']))
		);
	}

	public function testStorageIdAppliesTheDefaultsOfTheConnection(): void {
		$this->assertSame(
			'amazon::' . self::BUCKET . '@s3.amazonaws.com:443',
			AmazonS3::storageId(['bucket' => self::BUCKET])
		);
		$this->assertSame(
			'amazon::' . self::BUCKET . '@' . self::HOST_A . ':80',
			AmazonS3::storageId($this->params(self::HOST_A, ['use_ssl' => false]))
		);
	}

	public function testStorageIdIgnoresSpellingOfTheHostAndTheRegion(): void {
		// Der Endpunkt entscheidet, welcher Bucket gemeint ist; die Region dient nur der Signatur
		$this->assertSame(
			AmazonS3::storageId($this->params(self::HOST_A, ['port' => 443, 'region' => 'eu-central-1'])),
			AmazonS3::storageId($this->params(' ' . \strtoupper(self::HOST_A) . ' ', ['region' => 'us-east-1']))
		);
	}

	public function testStorageIdCannotBeForgedThroughTheBucketName(): void {
		// Bucket-Name, der wie „Bucket@Host:Port“ eines anderen aussieht
		$this->assertNotSame(
			AmazonS3::storageId($this->params(self::HOST_A)),
			AmazonS3::storageId($this->params(self::HOST_B, ['bucket' => self::BUCKET . '@' . self::HOST_A . ':443']))
		);
		$this->assertNotSame(
			AmazonS3::storageId(['bucket' => 'a@b', 'hostname' => 'c', 'port' => '1']),
			AmazonS3::storageId(['bucket' => 'a', 'hostname' => 'b@c', 'port' => '1'])
		);
	}

	public function testStorageUsesTheNewId(): void {
		$params = $this->params(self::HOST_A) + ['key' => 'schluessel', 'secret' => 'geheim'];

		$this->assertSame(AmazonS3::storageId($params), (new AmazonS3($params))->getId());
	}

	public function testRenameKeepsTheFileCacheOfAMount(): void {
		$this->addMount($this->params(self::HOST_A));
		$numericId = $this->addStorage($this->legacyId());

		$this->rename();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))), 'Datei-IDs, Freigaben und Tags hängen an der numeric_id');
		$this->assertNull($this->numericId($this->legacyId()));
		$this->assertSame([], $this->warnings);
	}

	public function testRenameCoversPersonalMounts(): void {
		$this->addMount($this->params(self::HOST_A, ['use_ssl' => false]), DBConfigService::MOUNT_TYPE_PERSONAl);
		$numericId = $this->addStorage($this->legacyId());

		$this->rename();

		$this->assertSame($numericId, $this->numericId('amazon::' . self::BUCKET . '@' . self::HOST_A . ':80'));
	}

	public function testCollisionLeavesTheFileCacheToTheOldestMount(): void {
		$oldest = $this->addMount($this->params(self::HOST_A));
		$younger = $this->addMount($this->params(self::HOST_B), DBConfigService::MOUNT_TYPE_PERSONAl);
		$numericId = $this->addStorage($this->legacyId());

		$this->rename();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertNull($this->numericId(AmazonS3::storageId($this->params(self::HOST_B))), 'die jüngere Einhängung beginnt leer');
		$this->assertNull($this->numericId($this->legacyId()));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString((string)$oldest, $this->warnings[0]);
		$this->assertStringContainsString((string)$younger, $this->warnings[0]);
	}

	public function testMountsOfOneBucketOnOneEndpointKeepSharingTheFileCache(): void {
		$this->addMount($this->params(self::HOST_A));
		$this->addMount($this->params(\strtoupper(self::HOST_A), ['port' => '443']), DBConfigService::MOUNT_TYPE_PERSONAl);
		$numericId = $this->addStorage($this->legacyId());

		$this->rename();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertSame([], $this->warnings, 'derselbe Bucket am selben Endpunkt ist keine Kollision');
	}

	public function testSecondRunChangesNothing(): void {
		$this->addMount($this->params(self::HOST_A));
		$this->addMount($this->params(self::HOST_B));
		$numericId = $this->addStorage($this->legacyId());
		$this->rename();
		$this->warnings = [];
		// Die jüngere Einhängung hat inzwischen ihren eigenen, leeren Speicher
		$younger = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_B)));

		$this->rename();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertSame($younger, $this->numericId(AmazonS3::storageId($this->params(self::HOST_B))));
		$this->assertNull($this->numericId($this->legacyId()));
		$this->assertSame([], $this->warnings);
	}

	public function testFreshInstallationHasNothingToRename(): void {
		// Speicherzeile ohne Einhängung: gehört niemandem, bleibt unberührt
		$orphan = $this->addStorage($this->legacyId());

		$this->rename();

		$this->assertSame($orphan, $this->numericId($this->legacyId()));
		$this->assertSame([], $this->warnings);
	}

	public function testNewMountAfterTheUpgradeStartsWithTheNewId(): void {
		$this->addMount($this->params(self::HOST_A));

		$this->rename();

		$this->assertNull($this->numericId($this->legacyId()));
		$this->assertNull($this->numericId(AmazonS3::storageId($this->params(self::HOST_A))), 'die Zeile legt erst der erste Zugriff an');
	}

	public function testExistingNewIdIsNeverOverwritten(): void {
		$this->addMount($this->params(self::HOST_A));
		$legacy = $this->addStorage($this->legacyId());
		$current = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));

		$this->rename();

		$this->assertSame($current, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertSame($legacy, $this->numericId($this->legacyId()));
		$this->assertCount(1, $this->warnings);
	}

	public function testUserPlaceholderIsResolvedForEveryUser(): void {
		foreach (['kennung-anna', 'kennung-ben'] as $userId) {
			$user = \OC::$server->getUserManager()->createUser($userId, 'Kennung-Test-2026!');
			$user->updateLastLoginTimestamp();
			$this->userIds[] = $userId;
		}
		$this->addMount($this->params(self::HOST_A, ['bucket' => self::BUCKET . '-$user']));
		$anna = $this->addStorage($this->legacyId(self::BUCKET . '-kennung-anna'));
		$ben = $this->addStorage($this->legacyId(self::BUCKET . '-kennung-ben'));

		$this->rename();

		$this->assertSame($anna, $this->numericId('amazon::' . self::BUCKET . '-kennung-anna@' . self::HOST_A . ':443'));
		$this->assertSame($ben, $this->numericId('amazon::' . self::BUCKET . '-kennung-ben@' . self::HOST_A . ':443'));
		$this->assertSame([], $this->warnings);
	}

	public function testLongIdsAreHashedLikeTheCoreDoes(): void {
		$bucket = self::BUCKET . '-' . \str_repeat('x', 60);
		$this->addMount($this->params(self::HOST_A, ['bucket' => $bucket]));
		$numericId = $this->addStorage($this->legacyId($bucket));
		$newId = AmazonS3::storageId($this->params(self::HOST_A, ['bucket' => $bucket]));
		$this->assertGreaterThan(64, \strlen($newId));
		$this->longStorageIds = [$this->legacyId($bucket), $newId];

		$this->rename();

		$this->assertSame($numericId, $this->numericId($newId));
		$this->assertNull($this->numericId($this->legacyId($bucket)));
	}

	public function testStorageIdOfAnOwnMountCarriesItsOwner(): void {
		$shared = AmazonS3::storageId($this->params(self::HOST_A));

		$this->assertSame('amazon_user::anna::' . self::BUCKET . '@' . self::HOST_A . ':443', $this->ownId('anna'));
		$this->assertNotSame($shared, $this->ownId('anna'));
		$this->assertNotSame($this->ownId('anna'), $this->ownId('ben'));
		// Kennung und Bucket-Name, die zusammen wie die eines anderen aussehen
		$this->assertNotSame(
			AmazonS3::storageId([AmazonS3::OWNER_OPTION => 'a::b', 'bucket' => 'x', 'hostname' => self::HOST_A]),
			AmazonS3::storageId([AmazonS3::OWNER_OPTION => 'a', 'bucket' => 'b::x', 'hostname' => self::HOST_A])
		);
	}

	public static function ownerProvider(): array {
		$admin = \OCP\Files\External\IStorageConfig::MOUNT_TYPE_ADMIN;
		$personal = \OCP\Files\External\IStorageConfig::MOUNT_TYPE_PERSONAl;
		return [
			'eigene Einhängung' => [$personal, 'anna', ['anna'], 'anna'],
			'eigene Einhängung ohne Benutzer (occ, Löschen)' => [$personal, null, ['anna'], 'anna'],
			'Einhängung der Verwaltung' => [$admin, 'anna', ['anna'], ''],
			'Einhängung der Verwaltung, Art als Text' => ['1', 'anna', [], ''],
			'Prüfung beim Anlegen in den Einstellungen des Benutzers' => [null, 'anna', [], 'anna'],
			'Prüfung beim Anlegen in der Verwaltung' => [null, null, ['anna'], ''],
		];
	}

	/**
	 * @dataProvider ownerProvider
	 */
	public function testBackendNamesTheOwnerOfAnOwnMountOnly($type, ?string $userId, array $applicable, string $expected): void {
		$backends = \OC::$server->getStoragesBackendService();
		$config = new \OC\Files\External\StorageConfig(1);
		$config->setBackend($backends->getBackend('files_external_s3'));
		// In der Einhängung gespeicherte Angabe: darf nie gelten
		$config->setBackendOptions($this->params(self::HOST_A) + [AmazonS3::OWNER_OPTION => 'opfer']);
		if ($type !== null) {
			$config->setType($type);
		}
		$config->setApplicableUsers($applicable);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$config->getBackend()->manipulateStorageConfig($config, $user);

		$this->assertSame($expected, $config->getBackendOption(AmazonS3::OWNER_OPTION));
	}

	public function testOwnMountTakesItsFileCacheAlong(): void {
		$this->addOwnMount('anna', $this->params(self::HOST_A));
		$numericId = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));

		$this->separate();

		$this->assertSame($numericId, $this->numericId($this->ownId('anna')));
		$this->assertNull($this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertSame([], $this->warnings);
	}

	public function testAdminMountKeepsTheFileCacheItSharedWithAnOwnMount(): void {
		$own = $this->addOwnMount('anna', $this->params(self::HOST_A));
		$admin = $this->addMount($this->params(self::HOST_A));
		$numericId = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));

		$this->separate();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertNull($this->numericId($this->ownId('anna')), 'die eigene Einhängung beginnt leer');
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString((string)$own, $this->warnings[0]);
		$this->assertStringContainsString((string)$admin, $this->warnings[0]);
	}

	public function testOldestOwnMountKeepsTheFileCacheSharedByOwnMounts(): void {
		$oldest = $this->addOwnMount('anna', $this->params(self::HOST_A));
		$younger = $this->addOwnMount('ben', $this->params(self::HOST_A));
		$numericId = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));

		$this->separate();

		$this->assertSame($numericId, $this->numericId($this->ownId('anna')));
		$this->assertNull($this->numericId($this->ownId('ben')), 'die jüngere Einhängung beginnt leer');
		$this->assertNull($this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString((string)$oldest, $this->warnings[0]);
		$this->assertStringContainsString((string)$younger, $this->warnings[0]);
	}

	public function testSeparatingASecondTimeChangesNothing(): void {
		$this->addOwnMount('anna', $this->params(self::HOST_A));
		$this->addOwnMount('ben', $this->params(self::HOST_A));
		$numericId = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));
		$this->separate();
		$this->warnings = [];
		$ben = $this->addStorage($this->ownId('ben'));

		$this->separate();

		$this->assertSame($numericId, $this->numericId($this->ownId('anna')));
		$this->assertSame($ben, $this->numericId($this->ownId('ben')));
		$this->assertSame([], $this->warnings);
	}

	public function testOwnMountComesStraightFromTheBucketNameId(): void {
		// Upgrade von 3.0.0: beide Migrationen laufen nacheinander
		$this->addOwnMount('anna', $this->params(self::HOST_A));
		$numericId = $this->addStorage($this->legacyId());

		$this->rename();
		$this->separate();

		$this->assertSame($numericId, $this->numericId($this->ownId('anna')));
		$this->assertSame([], $this->warnings);
	}

	public function testAdminMountsAreLeftAloneWhenOwnMountsAreSeparated(): void {
		$this->addMount($this->params(self::HOST_A));
		$numericId = $this->addStorage(AmazonS3::storageId($this->params(self::HOST_A)));

		$this->separate();

		$this->assertSame($numericId, $this->numericId(AmazonS3::storageId($this->params(self::HOST_A))));
		$this->assertSame([], $this->warnings);
	}
}
