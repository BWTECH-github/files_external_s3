# S3 External Storage for owncloud.online (`files_external_s3`)

[![License](https://img.shields.io/badge/License-GPL--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4-777bb4.svg)](https://www.php.net/)

S3-compatible object storage as an **external storage** backend for
owncloud.online. Administrators (and users, if permitted) mount an Amazon S3 or
S3-compatible bucket (MinIO, Ceph RGW, Scality, Wasabi, BackBlaze B2, …) as a
folder in the owncloud.online files interface. Files stay on the object store and are
served through owncloud.online with the usual sharing, sync and access controls.

Unlike **primary** object storage (`files_primary_s3`, configured server-wide in
`config.php`), this app plugs into the **files_external** framework and is
configured **per mount through the UI or `occ`** — no `config.php` change needed.

> Originally developed by ownCloud GmbH. Modified for owncloud.online and PHP 8.4
> by BW-Tech GmbH.

## Features

- Mount S3 / S3-compatible buckets as external storage (SDK v3).
- Path-style and virtual-hosted-style endpoints (`use_path_style`).
- Custom endpoint host/port and region — works with any S3-compatible service.
- Optional SSL, per-user or system-wide mounts, all files_external auth/scope
  controls.
- Full file operations: browse, read, write, copy, move, delete, mkdir.

## Requirements

- owncloud.online / owncloud.online **11.x**
- **PHP 8.4**
- The core **`files_external`** app enabled (`occ app:enable files_external`)
- Network access from the server to the S3 endpoint

## Installation

```bash
cd /path/to/owncloud/apps
git clone https://github.com/BWTECH-github/files_external_s3.git
cd files_external_s3
composer install --no-dev --optimize-autoloader
# adjust to your web-server user (e.g. www-data)
chown -R www-data:www-data .
cd /path/to/owncloud
sudo -u www-data ./occ app:enable files_external
sudo -u www-data ./occ app:enable files_external_s3
```

The backend **Amazon S3 compatible (SDK v3)** then appears under
*Settings → Admin → Storage* (and *Settings → Personal → Storage* when user
mounts are allowed).

## Configuration

### Via the web UI

*Settings → Storage → Add storage → “Amazon S3 compatible (SDK v3)”*, then fill:

| Field | Required | Description |
|---|---|---|
| **Folder name** | yes | Mount point shown in Files |
| **Bucket** | yes | Target S3 bucket (created automatically if missing) |
| **Hostname** | no | Endpoint host, e.g. `s3.amazonaws.com`, `minio.example.com` (default `s3.amazonaws.com`) |
| **Port** | no | Endpoint port (default 443 with SSL, 80 without) |
| **Region** | no | e.g. `eu-west-1` (default `eu-west-1`) |
| **Enable SSL** | no | Use `https` (recommended) |
| **Enable Path Style** | no | Required by most S3-compatible services (MinIO/Ceph) |
| **Access key** | yes | S3 access key ID |
| **Secret key** | yes | S3 secret access key |

Set the *Available for* scope (all users, groups, or specific users) as with any
external mount.

### Via `occ`

```bash
# create the mount
occ files_external:create "/S3 Bucket" files_external_s3 amazons3::accesskey

# configure it (use the id printed by files_external:list)
occ files_external:config <mount_id> bucket         my-bucket
occ files_external:config <mount_id> hostname       minio.example.com
occ files_external:config <mount_id> port           9000
occ files_external:config <mount_id> region         eu-west-1
occ files_external:config <mount_id> use_ssl        false
occ files_external:config <mount_id> use_path_style true
occ files_external:config <mount_id> key            <ACCESS_KEY>
occ files_external:config <mount_id> secret         <SECRET_KEY>

# verify
occ files_external:list
occ files_external:verify <mount_id>
```

## Daily usage

Once mounted, the bucket behaves like any other folder: users open it in Files,
up-/download, share and sync it. Directories are emulated with zero-byte
`…/` marker objects (standard S3 practice); deleting a folder removes all objects
under its prefix.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Mount shows a red ✗ in Storage settings | Wrong key/secret/bucket or endpoint unreachable — run `occ files_external:verify <id>`; check server → endpoint connectivity. |
| `Creation of bucket failed` | The access key lacks `CreateBucket`/`HeadBucket` rights, or the bucket exists in another account — pre-create the bucket and grant object rights only. |
| Files/folders not listed | For MinIO/Ceph set **Enable Path Style** = true; verify **Hostname**/**Port** match the endpoint. |
| TLS errors | Endpoint uses http → turn **Enable SSL** off (and set the correct port), or fix the endpoint certificate. |
| Backend missing in Storage settings | `files_external` core app not enabled, or app not enabled: `occ app:enable files_external files_external_s3`. |
| Slow directory operations on Ceph | `clearBucket` falls back to a per-object batch delete automatically; large prefixes take longer. |

## Attribution

Originally developed by **ownCloud GmbH** and contributors
(`owncloud/files_external_s3`, GPL-2.0). Modified for **owncloud.online** and
**PHP 8.4** by **BW-Tech GmbH**. Licensed under GPL-2.0 (unchanged).
