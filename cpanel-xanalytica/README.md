# XAnalytica cPanel Cron

This package replaces browser automation with direct HTTP API calls from PHP CLI.

Observed XAnalytica API flow:

- `POST /api/login`
- `GET /api/me`
- `GET /api/users`
- `POST /api/x-data/user/save`
- `POST /api/fb-data/posts/{id}`
- `POST /api/instagram-data/posts/{id}`

The script supports both a login session cookie and a bearer token returned by the login response. Credentials are never written to the log.

## Recommended location

Upload the folder outside `public_html`, for example:

`/home/CPANEL_USER/xanalytica-refresh/`

Copy `config.example.php` to `config.php` and fill in the real credentials.

Recommended permissions:

- folder: `750`
- `config.php`: `600`
- `refresh.php`: `750`

## Database

Create a MySQL/MariaDB database and user in cPanel, then import `schema.sql` with phpMyAdmin.

Fill the DB credentials in `config.php`.

## Manual test

Run from cPanel Terminal/SSH:

`/usr/local/bin/php -q /home/CPANEL_USER/xanalytica-refresh/refresh.php --force`

Expected success:

`{"status":"completed","issued":33,"failed":0,...}`

Do not enable the production cron until the forced test returns 33/33.

## Cron

Use an hourly cron. The PHP script itself checks Stockholm time, so DST and the cPanel server timezone do not matter:

`0 * * * * /usr/local/bin/php -q /home/CPANEL_USER/xanalytica-refresh/refresh.php >> /home/CPANEL_USER/xanalytica-refresh/logs/cron.log 2>&1`

The refresh executes only at Stockholm hours:

`01:00, 05:00, 09:00, 13:00, 17:00, 21:00`

Manual refresh at any time:

`/usr/local/bin/php -q /home/CPANEL_USER/xanalytica-refresh/refresh.php --force`

## Safety

- Non-blocking file lock prevents overlapping runs.
- Stops if `/api/users` does not return exactly 11 users.
- Requires exactly 33 issued platform requests and 0 failures.
- Logs every run and platform request in MySQL.
- Keeps credentials out of logs.
- Evaluates the schedule in `Europe/Stockholm`.

Keep the GitHub schedule enabled until this cPanel job completes one forced test and one scheduled test successfully. Then disable one scheduler to avoid duplicate updates.
