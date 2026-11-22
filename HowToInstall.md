## How to Install (Self-Hosting)

This tool is designed to run on any standard web server with **PHP (7.4+ / 8.x)** and **MySQL / MariaDB**.

### 1. Create the Database Table
Create an empty database in your MySQL/MariaDB instance (e.g. via phpMyAdmin or CLI) and run the following SQL command to create the `wardrive_leaderboard` table:

```sql
CREATE TABLE IF NOT EXISTS `wardrive_leaderboard` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board` VARCHAR(100) NOT NULL DEFAULT 'Unknown',
  `device` VARCHAR(100) NOT NULL DEFAULT 'Unknown',
  `firmware` VARCHAR(150) NOT NULL DEFAULT 'Unknown',
  `score` INT NOT NULL DEFAULT 0,
  `peak_burst` INT NOT NULL DEFAULT 0,
  `mix_latency_ms` DOUBLE NOT NULL DEFAULT 0,
  `avg_rate` DOUBLE NOT NULL DEFAULT 0,
  `total_entries` INT NOT NULL DEFAULT 0,
  `wifi_count` INT NOT NULL DEFAULT 0,
  `ble_count` INT NOT NULL DEFAULT 0,
  `showInBoard` BOOLEAN NOT NULL DEFAULT TRUE,
  `filebyte` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_score` (`score`),
  INDEX `idx_filebyte` (`filebyte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Upload Files to Your Web Server
Download the repository files and upload them into the same directory on your web server (e.g. `/var/www/html/benchmark/`):
* `BenchMark.html` *(optional: rename to `index.html` so it loads automatically as the directory root)*
* `process_and_submit.php`
* `get_leaderboard.php`

### 3. Configure Database Credentials
Open `process_and_submit.php` and `get_leaderboard.php` and enter your database connection details at the top of both files:

```php
$db_host = 'localhost';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';
```

### 4. Server Requirements & Checklist
* **PHP Extensions:** Make sure `pdo_mysql` (`php-pdo` & `php-mysql`) is enabled.
* **Upload Limits (`php.ini`):** Wardriving CSV logs from stress tests can grow to several megabytes. Ensure `upload_max_filesize` and `post_max_size` are set high enough (e.g. `32M`).
* **Permissions:** PHP needs write permissions to its temporary upload directory (`upload_tmp_dir`) to parse incoming CSV files in memory.
* **HTTPS:** Serving the tool over HTTPS is recommended so uploads and leaderboard requests are encrypted in transit.
