# TikoSchool — Off-Site Backup & Recovery (Google Drive, encrypted)

Nightly backup flow on the VPS (`tikoschool-vps`):

```
MySQL → mysqldump (in-use databases only) → gzip → [rclone crypt] → Google Drive (TIKSCHOOL-BACKUPS)
profile-images/ → rclone copy (incremental, never sync) → same crypt remote → Drive
```

- **What is backed up:** only the database(s) the app actually uses — `DB_DATABASE` from
  `.env` (currently: `tikoschool`, ~1.1 MB dump). `tikoschool_vide` (a leftover demo
  schema with sample data and zero app references) is deliberately excluded; older
  `tikoschool_vide` files already on Drive age out via the 30-day prune.
  PLUS the profile images (`storage/app/private/profile-images/` inside the app_storage
  volume — students/teachers/assistants/admins subdirectories). The DB stores only the
  logical paths; without the files every image reference is dead. Images are copied with
  `rclone copy` (only new/changed files transfer) into `tikcrypt:profile-images/`.
- **Local:** `/var/backups/tikoschool/daily|weekly/` — 14 dailies + 8 weeklies
- **Off-site:** Google Drive, folder `TIKSCHOOL-BACKUPS`, remote `tikcrypt:` — **30 days** retention
- **Encryption:** rclone `crypt` remote — filenames AND contents encrypted. Nobody with read
  access to the Google account (or Google itself) can read the data.
- **Check the latest backup:** `/usr/local/bin/backup-tikoschool-latest.sh` — prints the
  newest off-site file per database plus local counts. Backups are named
  `<database>_YYYY-MM-DD_HHMM.sql.gz`; newest = latest timestamp (never guess from the
  raw Drive names — those are encrypted).
- **Image integrity:** `php /app/artisan profile-images:integrity` inside the php
  container checks BOTH directions — every DB reference resolves to a file, and no file
  is unreferenced (orphan). Report-only; `--strict` exits non-zero for automation.

## What runs where

| Unit | When | What |
|---|---|---|
| `tikoschool-backup.timer` → `backup-tikoschool.sh` | daily 03:15 UTC | dump → gzip → verify → local retention → `rclone copy` DB + profile images to `tikcrypt:` (with post-copy existence check) |
| `tikoschool-offsite-prune.timer` → `rclone delete tikcrypt: --min-age 30d` | daily 03:30 UTC | keeps at most 30 days on Drive (never deletes the newest) |

Integration point: `/etc/tikoschool-backup.env` contains one line,
`OFFSITE_REMOTE=tikcrypt:` — the backup script already had the off-box hook; this variable
just points it at the encrypted Drive remote.

## CREDENTIALS — store these OFF the VPS (they are the recovery key)

**If these are lost, the backups are unreadable forever.**

| Secret | Value / location | What to store |
|---|---|---|
| Crypt passphrase (`password` / `password2`) | generated at setup | **password manager + paper copy** |
| Crypt salt (`salt` / `salt2`) | generated at setup | **same place as the passphrase** |
| `rclone.conf` | `/root/.config/rclone/rclone.conf` (copy: `/root/rclone.conf.backup-YYYYMMDD`) | one copy in your password manager or USB; regenerate on a new box using the passphrase+salt anyway |
| Google account + its own recovery | the account that owns `TIKSCHOOL-BACKUPS` | your normal Google account recovery (2FA etc.) |
| MySQL root password | `/var/www/Tikoschool/.env` (`DB_ROOT_PASSWORD`) | password manager |
| `OFFSITE_REMOTE` line | `/etc/tikoschool-backup.env` | trivial to recreate |

> The crypt passphrase and salt were printed once during setup. If they were not saved,
> they can be recovered **from the VPS** (`rclone.conf` is always valid there) but not from
> Google Drive alone.

---

## THE ENTIRE VPS IS LOST — new machine procedure

### 1. Install MySQL + deploy the app

Follow `DEPLOYMENT_RUNBOOK.md` to rebuild the Docker stack (nginx / php-fpm / mysql /
redis) and create `/var/www/Tikoschool/.env` with the live values (at minimum
`DB_ROOT_PASSWORD`).

### 2. Install rclone and rebuild Google access

```bash
apt-get update && apt-get install -y rclone
rclone config
# Create the "gdrive" remote (type: Google Drive, scope: drive.file).
# On a headless box: answer 'n' to auto-config, run the printed
#   rclone authorize "drive" "..."  on a machine WITH a browser,
# paste the token back.
```

### 3. Recreate the encrypted remote from the stored passphrase + salt

```bash
# From your stored secrets:
rclone config create tikcrypt crypt \
  remote gdrive:TIKSCHOOL-BACKUPS \
  filename_encryption standard \
  directory_name_encryption true \
  password  "$(rclone obscure 'PASTE_YOUR_PASSPHRASE')" \
  password2 "$(rclone obscure 'PASTE_YOUR_PASSPHRASE')" \
  salt      "$(rclone obscure 'PASTE_YOUR_SALT')" \
  salt2     "$(rclone obscure 'PASTE_YOUR_SALT')"

rclone lsf tikcrypt:      # must list the backups — if this errors, the pass/salt are wrong
```

> Alternatively copy the saved `rclone.conf` backup onto the new box — it contains valid
> obscured values and works as-is: `cp rclone.conf.backup-YYYYMMDD /root/.config/rclone/rclone.conf`

### 4. List / download / decrypt / decompress a backup

```bash
rclone lsf tikcrypt:                                  # choose a file (one per database)
rclone copy tikcrypt:tikoschool_2026-08-16_2301.sql.gz /tmp/restore/
gunzip -k /tmp/restore/tikoschool_2026-08-16_2301.sql.gz
```

Decryption happens automatically inside the `tikcrypt:` remote — what lands on disk is the
plain `.sql.gz`. Download **one file per database** you need (`tikoschool_*`, `tikoschool_vide_*`, ...).

### 4b. Restore the profile images (same drill, second copy)

The images live under `tikcrypt:profile-images/<type>/<hex>.webp` and belong inside the
app_storage volume:

```bash
# Host-side: the volume's real path (project name prefixes the volume)
IMG=/var/lib/docker/volumes/tikoschool_app_storage/_data/app/private/profile-images
mkdir -p "$IMG"
rclone copy tikcrypt:profile-images "$IMG"

# Fix ownership to the php container's runtime user, then verify counts match the DB:
docker exec -it tikoschool-php-1 chown -R www-data:www-data /app/storage/app/private/profile-images
docker exec -it tikoschool-php-1 php /app/artisan profile-images:integrity
```

`profile-images:integrity` must report `Referenced images: N / Missing files: 0 /
Orphaned files: 0` — that proves every path stored in MySQL now resolves to a restored
file and nothing is missing. The application serves them immediately; no cache or config
step is involved.

### 5. Create the database and restore

```bash
cd /var/www/Tikoschool
DBR=$(grep -E '^DB_ROOT_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
docker compose exec -T mysql mysql -u root -p"$DBR" \
  -e "CREATE DATABASE tikoschool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker compose exec -T mysql mysql -u root -p"$DBR" tikoschool \
  < /tmp/restore/tikoschool_2026-08-16_2301.sql
# repeat per database — each dump file restores into its own database
```

### 6. Verify the restore

```bash
docker compose exec -T mysql mysql -u root -p"$DBR" -N tikoschool -e "
  SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tikoschool';
  SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM students; SELECT COUNT(*) FROM invoices;
  SELECT COUNT(*) FROM transactions; SELECT COUNT(*) FROM teacher_wallet_entries;"
```

Expect: 39 tables, and the wallet ledger present (`teacher_wallet_entries`). **Check the
triggers survived** — the dump is taken with `--triggers`, but confirm before trusting it:

```bash
docker compose exec -T mysql mysql -u root -p"$DBR" -N -e \
  "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='tikoschool';"
```

### 7. Re-enable the schedule

```bash
systemctl enable --now tikoschool-backup.timer tikoschool-offsite-prune.timer
echo 'OFFSITE_REMOTE=tikcrypt:' > /etc/tikoschool-backup.env
chmod 600 /etc/tikoschool-backup.env
systemctl start tikoschool-backup.service   # verify: journalctl -u tikoschool-backup.service
```

---

## Policies

- **Retention:** 14 dailies + 8 weeklies locally; 30 days on Google Drive.
- **Never upload plaintext:** the only thing the backup script ever sends off-box is
  through `tikcrypt:` — ciphertext. A raw `gdrive:TIKSCHOOL-BACKUPS` listing shows only
  gibberish names (encrypted filenames).
- **Never delete the newest:** pruning is `--min-age 30d` only.
- **Fail loudly:** any failed dump/verify/upload step aborts the service run with a
  non-zero exit; the previous day's backup is never touched.
- **Logs:** `journalctl -u tikoschool-backup.service`.

## Restore into a scratch DB (safety test) — one-liner

```bash
cd /var/www/Tikoschool
DBR=$(grep -E '^DB_ROOT_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
rclone copy tikcrypt:tikoschool_2026-08-16_2301.sql.gz /tmp/restore/
gunzip -k /tmp/restore/*.sql.gz
docker compose exec -T mysql mysql -u root -p"$DBR" \
  -e "DROP DATABASE IF EXISTS tikoschool_restore_test; CREATE DATABASE tikoschool_restore_test;"
docker compose exec -T mysql mysql -u root -p"$DBR" tikoschool_restore_test < /tmp/restore/*.sql
docker compose exec -T mysql mysql -u root -p"$DBR" -N tikoschool_restore_test \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tikoschool_restore_test';"
```