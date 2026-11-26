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

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OCA\FilesExternalS3\Storage\AmazonS3;
use OCP\Files\StorageNotAvailableException;
use Test\TestCase;

/**
 * Ordner löschen, umbenennen und verschieben gegen einen nachgebildeten Bucket.
 * S3 kennt keine Ordner: Ein Ordner sind alle Objekte mit seinem Präfix, eine
 * Liste liefert höchstens 1000 Schlüssel, ein Löschauftrag nimmt höchstens 1000.
 */
class FolderOperationsTest extends TestCase {
	private const BUCKET = 'ordner-test';

	/** @var array<string, string> Schlüssel => Inhalt */
	private array $objects = [];
	/** @var string[] Schlüssel, deren Löschung der Bucket verweigert */
	private array $protectedKeys = [];
	private int $listCalls = 0;

	private function storage(array $params = []): AmazonS3 {
		return new AmazonS3($params + [
			'key' => 'test-key',
			'secret' => 'test-secret',
			'bucket' => self::BUCKET,
			'hostname' => 's3.ordner-test.invalid',
			'use_ssl' => true,
		]);
	}

	private function storageWithFakeBucket(): AmazonS3 {
		$storage = $this->storage();
		$client = new S3Client([
			'version' => '2006-03-01',
			'region' => 'eu-west-1',
			'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
			'handler' => fn (CommandInterface $command) => $this->handle($command),
		]);
		(new \ReflectionProperty(AmazonS3::class, 'connection'))->setValue($storage, $client);
		return $storage;
	}

	private function put(string ...$keys): void {
		foreach ($keys as $key) {
			$this->objects[$key] = \str_ends_with($key, '/') ? '' : "Inhalt $key";
		}
	}

	/** @return string[] */
	private function keys(): array {
		$keys = \array_keys($this->objects);
		\sort($keys, SORT_STRING);
		return $keys;
	}

	public function testRmdirDeletesFolderWithContent(): void {
		$this->put('ordner/', 'ordner/a.txt', 'ordner/unter/', 'ordner/unter/b.txt', 'ordnerzwei/c.txt', 'andere.txt');

		$this->assertTrue($this->storageWithFakeBucket()->rmdir('ordner'));
		$this->assertSame(['andere.txt', 'ordnerzwei/c.txt'], $this->keys());
	}

	public function testRmdirDeletesFolderWithMoreThanOneListPage(): void {
		$this->put('gross/', 'nachbar.txt');
		for ($i = 1; $i <= 2500; $i++) {
			$this->put(\sprintf('gross/datei-%04d.txt', $i));
		}

		$this->assertTrue($this->storageWithFakeBucket()->rmdir('gross'));
		$this->assertSame(['nachbar.txt'], $this->keys());
	}

	public function testRmdirFailsWhenTheBucketKeepsObjects(): void {
		$this->put('ordner/', 'ordner/a.txt', 'ordner/b.txt');
		$this->protectedKeys = ['ordner/a.txt'];

		$this->assertFalse($this->storageWithFakeBucket()->rmdir('ordner'));
		$this->assertSame(['ordner/a.txt'], $this->keys());
	}

	public function testRenameMovesFolderWithContent(): void {
		$this->put('quelle/', 'quelle/a.txt', 'quelle/unter/', 'quelle/unter/b.txt', 'quellen.txt');

		$this->assertTrue($this->storageWithFakeBucket()->rename('quelle', 'ziel/neu'));
		$this->assertSame(
			['quellen.txt', 'ziel/neu/', 'ziel/neu/a.txt', 'ziel/neu/unter/', 'ziel/neu/unter/b.txt'],
			$this->keys()
		);
		$this->assertSame('Inhalt quelle/unter/b.txt', $this->objects['ziel/neu/unter/b.txt']);
	}

	public function testUnreachableEndpointMakesTheStorageUnavailable(): void {
		// Port 9 auf 127.0.0.1: Verbindung wird sofort abgelehnt
		$storage = $this->storage(['hostname' => '127.0.0.1', 'port' => 9, 'use_ssl' => false, 'use_path_style' => true]);

		$this->expectException(StorageNotAvailableException::class);
		$storage->file_exists('datei.txt');
	}

	/**
	 * Nachgebildeter Bucket: nur die Aufrufe, die die Ordnerabläufe brauchen.
	 */
	private function handle(CommandInterface $command): PromiseInterface {
		$args = $command->toArray();
		switch ($command->getName()) {
			case 'HeadObject':
				return isset($this->objects[$args['Key']])
					? Create::promiseFor(new Result(['ContentLength' => \strlen($this->objects[$args['Key']])]))
					: Create::rejectionFor($this->error($command, 'NotFound', 404));
			case 'PutObject':
				$this->objects[$args['Key']] = (string)($args['Body'] ?? '');
				return Create::promiseFor(new Result([]));
			case 'CopyObject':
				$source = \substr(\rawurldecode($args['CopySource']), \strlen(self::BUCKET) + 1);
				if (!isset($this->objects[$source])) {
					return Create::rejectionFor($this->error($command, 'NoSuchKey', 404));
				}
				$this->objects[$args['Key']] = $this->objects[$source];
				return Create::promiseFor(new Result([]));
			case 'DeleteObject':
				unset($this->objects[$args['Key']]);
				return Create::promiseFor(new Result([]));
			case 'DeleteObjects':
				return $this->deleteObjects($command, $args['Delete']['Objects']);
			case 'ListObjects':
				return $this->listObjects($command, $args);
		}
		return Create::rejectionFor($this->error($command, 'NotImplemented', 501));
	}

	private function listObjects(CommandInterface $command, array $args): PromiseInterface {
		// Wer dieselbe Seite immer wieder liest, kommt nie an ein Ende
		if (++$this->listCalls > 50) {
			return Create::rejectionFor($this->error($command, 'TooManyListCalls', 400));
		}
		$prefix = $args['Prefix'] ?? '';
		$marker = $args['Marker'] ?? '';
		$maxKeys = $args['MaxKeys'] ?? 1000;
		$matching = \array_values(\array_filter(
			$this->keys(),
			static fn (string $key) => \str_starts_with($key, $prefix) && ($marker === '' || \strcmp($key, $marker) > 0)
		));
		$data = ['Name' => self::BUCKET, 'Prefix' => $prefix, 'MaxKeys' => $maxKeys, 'IsTruncated' => false];
		$contents = [];
		if (isset($args['Delimiter'])) {
			// Ordnerliste: die Tests listen hier nur wenige Einträge, daher ohne Seiten
			$commonPrefixes = [];
			foreach ($matching as $key) {
				$position = \strpos($key, $args['Delimiter'], \strlen($prefix));
				if ($position === false) {
					$contents[] = ['Key' => $key];
				} else {
					$commonPrefixes[\substr($key, 0, $position + 1)] = true;
				}
			}
			if ($commonPrefixes !== []) {
				$data['CommonPrefixes'] = \array_map(static fn (string $p) => ['Prefix' => $p], \array_keys($commonPrefixes));
			}
		} else {
			foreach (\array_slice($matching, 0, $maxKeys) as $key) {
				$contents[] = ['Key' => $key];
			}
			$data['IsTruncated'] = \count($matching) > $maxKeys;
		}
		// Wie S3: ohne Treffer gibt es kein Feld Contents
		if ($contents !== []) {
			$data['Contents'] = $contents;
		}
		return Create::promiseFor(new Result($data));
	}

	private function deleteObjects(CommandInterface $command, array $objects): PromiseInterface {
		if (\count($objects) > 1000) {
			return Create::rejectionFor($this->error($command, 'MalformedXML', 400));
		}
		$errors = [];
		foreach ($objects as $object) {
			if (\in_array($object['Key'], $this->protectedKeys, true)) {
				$errors[] = ['Key' => $object['Key'], 'Code' => 'AccessDenied', 'Message' => 'Access Denied'];
				continue;
			}
			unset($this->objects[$object['Key']]);
		}
		// Wie S3: einzelne Fehler kommen in einer erfolgreichen Antwort
		return Create::promiseFor(new Result($errors === [] ? [] : ['Errors' => $errors]));
	}

	private function error(CommandInterface $command, string $code, int $status): S3Exception {
		return new S3Exception($code, $command, ['code' => $code, 'response' => new Response($status)]);
	}
}
