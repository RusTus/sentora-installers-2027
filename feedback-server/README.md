# Sentora-2027 feedback server

A small website that collects the daily reports Sentora-2027 panels send (Sentora Config > customer_feedback, on by default) and shows statistics about them: how many panels run, which versions, operating systems and hardware, and totals of accounts, domains and mailboxes. A panel sends nothing private: no domain, user name, email address, password or file (the last report a panel sent is in its `/var/lib/sentora/feedback-last.json`).

Plain PHP 8 with SQLite (one file, nothing else to install).

## Setting it up

1. Put this folder on a web server with PHP 8 and `pdo_sqlite` (Ubuntu: `apt install php-sqlite3`). The website's document root is `public/`; `data/` and the PHP files above `public/` must not be served.
2. `cp config.example.php config.php`, and set `password_hash` (the stats page's password):
   ```bash
   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
   ```
3. Let the web server write `data/` (the database is made on the first report):
   ```bash
   chown www-data data && chmod 700 data
   ```
4. Give it https (panels send only over https).
5. Tell the panels where it is: put the address of `api.php` (such as `https://stats.example.com/api.php`) in `feedback-endpoint.txt` at the top of the installers repository. Panels read that file once a day; one server can also be pointed elsewhere in its Sentora Config > feedback_url.

## What it does

- `api.php` takes a report (POST, JSON, at most 32 KB), keeps only the known fields (each checked for its type and size; anything else is dropped), and records the address it came from. One address may send `per_hour` reports an hour (12); a panel sends one a day.
- `index.php` is the statistics page, behind the password: servers seen and active, panel versions, operating systems, virtualisation, hardware, the modules switched on, totals, and the latest servers with their addresses.
- Each server keeps its newest `keep` reports (400, about a year).
