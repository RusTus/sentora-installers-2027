# AppArmor (Debian, Ubuntu)

AppArmor stays on. Its profiles confine a few of the programs Sentora uses (BIND; on Ubuntu also
MySQL and ProFTPD; ClamAV). Where Sentora keeps a program's files outside the places its profile
allows, the paths are added here: `local/<profile>` is written by the installer into
`/etc/apparmor.d/local/<profile>` between `# BEGIN Sentora` and `# END Sentora` (the system's own
profile is never changed or disabled, and package updates keep the local file), then the profile
is reloaded.

Only what a program really needs is added (found with the profile in complain mode on a real
server, then tested in enforce mode). To see what AppArmor refused:

    journalctl -k | grep 'apparmor="DENIED"'
