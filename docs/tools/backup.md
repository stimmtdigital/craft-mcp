# Backup Tools

Backup tools help manage database backups in your Craft installation. You can list existing backups and create new ones—useful for snapshotting the database before making significant changes.

## Database Backups

Craft stores database backups as SQL files in the `storage/backups` directory. These backups contain the complete database schema and data, and can be restored using Craft's CLI or database tools.

### list_backups

List all available database backups, sorted by date with the most recent first.

**Parameters:** None

**Example:**

```
list_backups
```

**Response:**

```json
{
  "count": 3,
  "backups": [
    {
      "filename": "mysite--2024-01-15-143022--v5.0.0.sql",
      "path": "/var/www/html/storage/backups/mysite--2024-01-15-143022--v5.0.0.sql",
      "size": "2.45 MB",
      "sizeBytes": 2569011,
      "created": "2024-01-15 14:30:22"
    },
    {
      "filename": "mysite--2024-01-10-091500--v5.0.0.sql",
      "path": "/var/www/html/storage/backups/mysite--2024-01-10-091500--v5.0.0.sql",
      "size": "2.41 MB",
      "sizeBytes": 2527436,
      "created": "2024-01-10 09:15:00"
    }
  ],
  "path": "/var/www/html/storage/backups"
}
```

The filename follows Craft's convention: `{systemName}--{timestamp}--v{craftVersion}.sql`. This makes it easy to identify when a backup was created and which Craft version it came from.

**Empty response:**

```json
{
  "count": 0,
  "backups": [],
  "path": "/var/www/html/storage/backups"
}
```

---

### create_backup

Create a new database backup. The backup file is saved to Craft's standard backup directory.

> **Note:** This is a dangerous tool that creates files on the server. It can be disabled via configuration.

**Parameters:** None

**Example:**

```
create_backup
```

**Response:**

```json
{
  "success": true,
  "backup": {
    "filename": "mysite--2024-01-15-150000--v5.0.0.sql",
    "path": "/var/www/html/storage/backups/mysite--2024-01-15-150000--v5.0.0.sql",
    "size": "2.45 MB",
    "sizeBytes": 2569011,
    "created": "2024-01-15 15:00:00"
  }
}
```

**Error response:**

```json
{
  "success": false,
  "error": "Unable to create backup: Permission denied"
}
```

Common failure reasons:

- **Permission denied**: The web server doesn't have write access to the backups directory
- **Disk full**: Not enough space to write the backup file
- **Database error**: Connection issues or timeout during dump

## Best Practices

- **Before migrations**: Create a backup before running `project-config/apply` or database migrations
- **Before bulk operations**: Back up before mass entry updates or deletions
- **Regular cleanup**: Old backups accumulate; periodically remove backups you no longer need
- **External storage**: For production, copy important backups to external storage—the `storage/backups` directory shouldn't be your only copy
