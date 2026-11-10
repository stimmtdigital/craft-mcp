# Backup Tools

List and create database backups. Both are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Lite edition.

---

## stimmt_backups_list

List the `.sql` files in Craft's database backup directory (`storage/backups` by default), newest first. No parameters.

Each backup has `filename`, `path`, `size` (human-readable), `sizeBytes` and `created`. The response also carries `count` and the backup `path`. When the directory does not exist, the list is empty.

---

## stimmt_backups_create

> Dangerous: hidden while `enableDangerousTools` is off, which is the default in production.

Create a database backup with Craft's own backup command, which writes a file on the server. No parameters.

Returns `success` and `backup` with the same keys as a listed backup.
