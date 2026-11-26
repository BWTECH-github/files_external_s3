<?php
/**
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH.
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 */

namespace OCA\FilesExternalS3\Backend;

use OCA\FilesExternalS3\Auth\AccessKey;
use OCA\FilesExternalS3\Storage\AmazonS3 as S3Storage;
use OCP\Files\External\Backend\Backend;
use OCP\Files\External\DefinitionParameter;
use OCP\Files\External\IStorageConfig;
use OCP\IL10N;
use OCP\IUser;

class AmazonS3 extends Backend {
	public function __construct(IL10N $l) {
		$this
			->setIdentifier('files_external_s3')
			->setStorageClass(\OCA\FilesExternalS3\Storage\AmazonS3::class)
			->setText($l->t('Amazon S3 compatible (SDK v3)'))
			->addParameters([
				(new DefinitionParameter('bucket', $l->t('Bucket'))),
				(new DefinitionParameter('hostname', $l->t('Hostname')))
					->setFlag(DefinitionParameter::FLAG_OPTIONAL),
				(new DefinitionParameter('port', $l->t('Port')))
					->setFlag(DefinitionParameter::FLAG_OPTIONAL),
				(new DefinitionParameter('region', $l->t('Region')))
					->setFlag(DefinitionParameter::FLAG_OPTIONAL),
				(new DefinitionParameter('use_ssl', $l->t('Enable SSL')))
					->setType(DefinitionParameter::VALUE_BOOLEAN),
				(new DefinitionParameter('use_path_style', $l->t('Enable Path Style')))
					->setType(DefinitionParameter::VALUE_BOOLEAN),
			])
			->addAuthScheme(AccessKey::SCHEME_AMAZONS3_ACCESSKEY);
		;
	}

	/**
	 * Nennt dem Speicher den Besitzer einer eigenen Einhängung: Sie bekommt eine
	 * eigene Speicher-ID und damit einen eigenen Dateicache
	 * (Storage\AmazonS3::storageId()). Immer gesetzt, auch leer - eine in der
	 * Einhängung gespeicherte Angabe dieses Namens gilt nie.
	 */
	#[\Override]
	public function manipulateStorageConfig(IStorageConfig &$storage, ?IUser $user = null) {
		$storage->setBackendOption(S3Storage::OWNER_OPTION, self::ownerOf($storage, $user));
	}

	/**
	 * Besitzer einer eigenen Einhängung, leer bei Einhängungen der Verwaltung.
	 *
	 * Eine noch nicht gespeicherte Einhängung kennt ihre Art nicht (Prüfung
	 * beim Anlegen): Die Einstellungen der Benutzer nennen dann den Benutzer,
	 * die der Verwaltung nicht. Ohne Benutzer (occ, Löschen) gehört die eigene
	 * Einhängung dem einen Benutzer, für den sie gilt.
	 */
	public static function ownerOf(IStorageConfig $storage, ?IUser $user): string {
		$type = $storage->getType();
		$personal = $type === null ? $user !== null : (int)$type === IStorageConfig::MOUNT_TYPE_PERSONAl;
		if (!$personal) {
			return '';
		}
		if ($user !== null) {
			return $user->getUID();
		}
		$owners = \array_values((array)$storage->getApplicableUsers());

		return \count($owners) === 1 ? (string)$owners[0] : '';
	}
}
