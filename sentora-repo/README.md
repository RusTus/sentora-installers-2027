# Sentora-2027 add-on modules

Optional modules for Sentora-2027, installed from the panel's **Repo Browser**. New installs list this repository already.

| Module | What it does |
| --- | --- |
| `PHP-Adminer` | Adminer 6.3.0 for each account's own MySQL databases, locked to the panel login and the account's own MySQL users |
| `webalizer_stats` | Webalizer traffic reports for each account's domains, updated every hour, in the panel's Light or Dark (builds the webalizer program on install) |
| `visitor_stats` | Visitors reports for each account's domains: visitors per day, pages, referrers, search terms and browsers, in the panel's Light or Dark (builds the visitors program on install) |

## Using it

On a server installed before this repository moved here, add it once in **Admin > Repo Browser**:

```text
https://raw.githubusercontent.com/RusTus/sentora-installers-2027/master2027/sentora-repo
```

Then refresh the list and install modules from it. Every download is checked against `SHA256SUMS`; a module that doesn't match isn't installed.

## Adding or updating a module

1. Put `<name>.zpp` (a zip of the module folder) in this folder. The file name, without `.zpp`, becomes the module's folder name: letters, numbers, `_` and `-` only.
2. Add `<name>` on its own line in `packages.txt` (new modules only).
3. For an update, raise the version to the same number in two places: `<version>` in the module's own `module.xml` (inside the `.zpp`) and `<latestversion>` in `<name>.xml` here. Servers with the module installed see the higher number within an hour: the administrators get a notice, and Repo Browser lists it under **Updates**.
4. Update the checksums, then commit to `master2027`:

   ```bash
   sha256sum *.zpp > SHA256SUMS
   ```

GitHub can take a few minutes to serve a new commit at the address above.

> [!WARNING]
> Installing a module runs its code inside the panel. Only add modules you trust.
