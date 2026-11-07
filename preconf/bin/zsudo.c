/*
 * zsudo - lets the panel (web server user) ask root for a few fixed actions.
 *
 * The original zsudo ran ANY command line as root through "at now". Every
 * hosted website runs as the same web server user as the panel, so any site
 * (or anyone who could upload a PHP file) could run "zsudo <anything>" and
 * become root.
 *
 * This version only accepts the exact commands the panel uses and runs a
 * fixed command line for each; nothing from the caller's ARGUMENTS reaches a
 * shell. Only the panel's user can run it (see below).
 *
 * Just as important, nothing from the caller's ENVIRONMENT reaches root either.
 * A set-uid helper inherits the caller's environment, and here that environment
 * would otherwise reach a root context two ways:
 *   - popen() runs /bin/sh now; after setuid(0) the loader no longer treats the
 *     process as set-uid, so the shell would honour the inherited environment;
 *   - at(1) SAVES the submitter's environment and REPLAYS it when the (root) job
 *     runs later.
 * The loader scrubs LD_PRELOAD and friends from a set-uid program, but it does
 * NOT scrub PATH, BASH_ENV, IFS and the like. "service" is a /bin/sh script that
 * calls "systemctl" by its bare name, so a caller who set PATH to a directory of
 * their own (with a fake "systemctl" in it) got that fake run as root - the
 * argument whitelist below did not matter, because the payload was in PATH. So
 * before doing anything, we wipe the environment and set a known-safe one.
 *
 * The same thinking applies to the rest of the context a set-uid helper hands to
 * root. at(1) also records the umask and the working directory and replays them
 * when the root job runs, and popen() starts /bin/sh holding whatever file
 * descriptors the caller left open. The fixed reload creates no files, so none of
 * this is exploitable today, but a caller-chosen umask or a leaked descriptor
 * reaching a future root action would be, so we make them safe here too.
 *
 * Accepted:
 *   zsudo service <apache2|httpd> <reload|restart|graceful>
 *   zsudo service <named|bind9> <start|stop|reload|restart>
 *   zsudo ftp-kick     (no arguments: ends the open FTP sessions of the FTP
 *                       logins switched off with their client, through the
 *                       root-owned /etc/sentora/panel/bin/sentora-ftp-kick)
 *
 * The panel runs as its own user ("sentora"); zsudo is installed root:sentora
 * 4750, so the hosted sites (their own web<id> users) can not run it at all.
 */
#define _GNU_SOURCE
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/types.h>
#include <grp.h>
#include <sys/stat.h>

static int one_of(const char *s, const char *const *list)
{
    for (; *list; list++)
        if (strcmp(s, *list) == 0)
            return 1;
    return 0;
}

int main(int argc, char *argv[])
{
    static const char *const apache[] = { "apache2", "httpd", NULL };
    static const char *const apache_act[] = { "reload", "restart", "graceful", NULL };
    static const char *const bind[] = { "named", "bind9", NULL };
    static const char *const bind_act[] = { "start", "stop", "reload", "restart", NULL };
    char cmd[256];

    /* Drop the caller's environment before it can reach the shell below or be
       saved by at(1); give root a minimal, fixed one. This is what stops the
       PATH / BASH_ENV / IFS tricks described above. */
    if (clearenv() != 0
        || setenv("PATH", "/usr/sbin:/usr/bin:/sbin:/bin", 1) != 0
        || setenv("SHELL", "/bin/sh", 1) != 0
        || setenv("IFS", " \t\n", 1) != 0) {
        fprintf(stderr, "zsudo: could not reset the environment\n");
        return 1;
    }

    cmd[0] = '\0';
    if (argc == 4 && strcmp(argv[1], "service") == 0
        && ((one_of(argv[2], apache) && one_of(argv[3], apache_act))
            || (one_of(argv[2], bind) && one_of(argv[3], bind_act)))) {
        /* Every word is one of the constants above */
        snprintf(cmd, sizeof cmd, "/usr/sbin/service %s %s", argv[2], argv[3]);
    } else if (argc == 2 && strcmp(argv[1], "ftp-kick") == 0) {
        /* A fixed line: the script is root's (the panel folder is root:root) and takes no input from here */
        snprintf(cmd, sizeof cmd, "/usr/bin/php -q /etc/sentora/panel/bin/sentora-ftp-kick");
    } else {
        fprintf(stderr, "zsudo: command not allowed\n");
        return 2;
    }

    /* A clean context for root, not just a clean environment (see the note above):
       a safe umask (at replays the caller's), a known working directory, and no
       inherited file descriptors past stderr. */
    umask(022);
    if (chdir("/") != 0) {
        perror("zsudo: chdir /");
        return 1;
    }
    {
        long maxfd = sysconf(_SC_OPEN_MAX);
        if (maxfd < 0 || maxfd > 65536) {
            maxfd = 65536;
        }
        for (int fd = 3; fd < (int) maxfd; fd++) {
            close(fd);
        }
    }

    /* Become root with only root's groups (drop the inherited "sentora" group). */
    if (setgroups(0, NULL) != 0 || setgid(0) != 0 || setuid(0) != 0) {
        fprintf(stderr, "zsudo: must be installed setuid root\n");
        return 1;
    }
    /* Run it through atd (outside the web server's systemd sandbox), detached
       from the calling web request, exactly as before - but only this fixed
       line, and now with the clean environment set above. */
    FILE *at = popen("/usr/bin/at now > /dev/null 2>&1", "w");
    if (at == NULL) {
        perror("zsudo: at");
        return 1;
    }
    fprintf(at, "%s > /dev/null 2>&1\n", cmd);
    return pclose(at) == 0 ? 0 : 1;
}
