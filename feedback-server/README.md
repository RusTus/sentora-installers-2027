# Sentora-2027 feedback server

A small website that collects the daily reports Sentora-2027 panels send (Sentora Config > Feedback, on by default), counts the downloads of the Sentora-2027 add-ons, and keeps the ratings and comments administrators give them in Repo Browser. Its statistics page shows how many panels run, which versions, operating systems and hardware, totals of accounts, domains and mailboxes, the add-ons' downloads, and the ratings. A panel sends nothing private: no domain, user name, email address, password or file (the last report a panel sent is in its `/var/lib/sentora/feedback-last.json`).

Plain PHP 8 with SQLite (one file, nothing else to install). **Nothing in a panel depends on it:** without a feedback server, panels send no report and add-ons are downloaded from GitHub as always, just not counted or rated.

## Setting it up

Everything to upload is in `public_html/`, already laid out as a website:

```
public_html/
  index.php        the statistics page (behind a password)
  api.php          where panels send their daily report
  repo/            counts add-on downloads, takes ratings, answers Repo Browser
  inc/             the code library and your settings: never served (its .htaccess)
  data/            the database: never served (its .htaccess)
```

1. Upload `public_html/` as the website (a Sentora domain's folder works: its Apache reads the `.htaccess` files). It needs PHP 8 with `pdo_sqlite` and `curl` (Ubuntu: `apt install php-sqlite3 php-curl`).
2. Copy `inc/config.example.php` to `inc/config.php` and set `password_hash` (the statistics page's password):
   ```bash
   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
   ```
3. Let the web server write `data/` (the database is made on the first report). On a Sentora domain the site's own user already can; elsewhere:
   ```bash
   chown www-data data && chmod 700 data
   ```
4. Give it https (panels send only over https).
5. Tell the panels where it is: its address (such as `https://stats.example.com`) in Sentora Config > Feedback > Feedback server on one panel, or for every panel in `feedback-endpoint.txt` at the top of the installers repository (as `https://stats.example.com/api.php`; panels read that file once a day).

**nginx** reads no `.htaccess`: add these to the site.

```nginx
location ~ ^/(inc|data)/ { deny all; }
location ~ /\. { deny all; }
location /repo/ { try_files $uri /repo/index.php$is_args$args; }
```

## What it does

- **Reports** (`api.php`): a report (POST, JSON, at most 32 KB) keeps only the known fields (each checked for its type and size; anything else is dropped), and the address it came from is recorded. One address may send `per_hour` reports an hour (12); a panel sends one a day. Each server keeps its newest `keep` reports (400, about a year).
- **Two keys per panel:** its install ID (random, made once per panel installation) and its computer key (a one-way hash of the motherboard's UUID, else of the network cards' own addresses, else of the operating system's machine ID). Reinstalling the panel, or Ubuntu, on the same computer gives a new install ID with the same computer key, so the statistics page counts computers and reinstalls, and cloned virtual machines show as one computer. Neither key can be turned back into anything about the server.
- **Add-on downloads** (`repo/`): after a panel downloads a module from the Sentora-2027 repository on GitHub, it tells this server (`repo/<module>.zpp?for=install|upgrade&panel=<install ID>`, five seconds at most, never in the way of the download). Only panels are counted (zppy), only modules the repository lists, and a repeat within 10 minutes is not counted. Repo Browser shows the counts (`repo/repostats.php?json=true`, also `repo/repostatslist`). `repo/` can also stand in for the whole repository (it passes `packages.txt`, `SHA256SUMS` and the `.xml` files through from `repo_upstream` and sends each `.zpp` on to it).
- **Ratings and comments** (`repo/rate.php`, `repo/reviews.php`): an administrator rates an installed add-on in Repo Browser (1 to 5 stars and an optional comment of up to 500 characters); the panel's daemon sends it. A rating is accepted only from a panel that reports here, from that panel's own address; one per panel and module (a new one replaces it). Comments are plain text everywhere. On the statistics page each comment can be hidden from the panels (spam, abuse) and shown again; its stars still count.
- **The statistics page** (`index.php`, behind the password): servers seen and active, computers and reinstalls, panel versions, operating systems, virtualisation, hardware, countries, the modules switched on, the add-ons' downloads (installs, updates, last 30 days, panels) and stars, the newest ratings and comments, totals, the heaviest servers and the latest servers with their addresses.
