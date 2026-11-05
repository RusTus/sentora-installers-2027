/*
 * zsudo - lets the panel (web server user) ask root for a few fixed actions.
 *
 * The original zsudo ran ANY command line as root through "at now". Every
 * hosted website runs as the same web server user as the panel, so any site
 * (or anyone who could upload a PHP file) could run "zsudo <anything>" and
 * become root.
 *
 * This version only accepts the exact commands the panel uses and runs a
 * fixed command line for each; nothing from the caller reaches a shell.
 * Only the panel's user can run it (see below).
 *
 * Accepted:
 *   zsudo service <apache2|httpd> <reload|restart|graceful>
 *   zsudo service <named|bind9> <start|stop|reload|restart>
 *
 * The panel runs as its own user ("sentora"); zsudo is installed root:sentora
 * 4750, so the hosted sites (www-data) can not run it at all.
 */
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>

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

    cmd[0] = '\0';
    if (argc == 4 && strcmp(argv[1], "service") == 0
        && ((one_of(argv[2], apache) && one_of(argv[3], apache_act))
            || (one_of(argv[2], bind) && one_of(argv[3], bind_act)))) {
        /* Every word is one of the constants above */
        snprintf(cmd, sizeof cmd, "/usr/sbin/service %s %s", argv[2], argv[3]);
    } else {
        fprintf(stderr, "zsudo: command not allowed\n");
        return 2;
    }

    if (setgid(0) != 0 || setuid(0) != 0) {
        fprintf(stderr, "zsudo: must be installed setuid root\n");
        return 1;
    }
    /* Run it through atd (outside the web server's systemd sandbox), detached
       from the calling web request, exactly as before - but only this fixed line */
    FILE *at = popen("/usr/bin/at now > /dev/null 2>&1", "w");
    if (at == NULL) {
        perror("zsudo: at");
        return 1;
    }
    fprintf(at, "%s > /dev/null 2>&1\n", cmd);
    return pclose(at) == 0 ? 0 : 1;
}
