<?php
/**
 * Docker Hosting at a glance (installed by the Docker Hosting add-on; Widget Admin shows it).
 * Administrators: each node in one line, green or what needs a look. Everyone: their own apps.
 * Only reads; shows only the logged-in account's own apps.
 */
class dashboard_widget_docker_hosting
{
    /** what needs a look, in a word or two: key => (how urgent, label) */
    static $labels = array('offline' => array(3, 'offline'), 'gone' => array(3, 'away over a day'), 'missing' => array(2, 'apps missing'),
        'disk' => array(2, 'low on disk'), 'security' => array(1, 'updates waiting'), 'reboot' => array(1, 'needs a restart'),
        'agent' => array(1, 'agent update'), 'found' => array(1, 'found apps'));

    static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    static function t($s, array $a = array())
    {
        $t = ui_language::translate($s);
        foreach ($a as $v) {
            $t = preg_replace('/%s/', str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $v), $t, 1);
        }
        return self::h($t);
    }

    static function Render($user)
    {
        global $zdbh;
        $code = dirname(__FILE__) . '/../../../modules/docker_hosting/code/dh.php';
        if (!is_file($code) || !dashboard_widgets::CanOpen($user, 'docker_hosting')) {
            return '<p class="text-muted">' . self::t('Docker Hosting is not available to you.') . '</p>';
        }
        require_once $code;
        $acc = (int) $user['userid'];
        $out = '';
        if (dh::IsAdmin(dh::Account($acc))) {
            $rows = '';
            $nodes = dh::Nodes();
            $n = 0;
            foreach ($nodes as $node) {
                if ((string) $node['nd_signpk_vc'] === '') {
                    continue;
                }
                if (++$n > 6) {
                    break;
                }
                // a short label for each thing (the full words are on the Docker Hosting page), the most urgent first
                $short = array();
                foreach (array_keys(dh::Words($node, false)) as $k) {
                    $short[$k] = strpos($k, 'app-') === 0 ? array(2, 'app down') : (isset(self::$labels[$k]) ? self::$labels[$k] : array(1, 'needs a look'));
                }
                uasort($short, function ($a, $b) { return $b[0] - $a[0]; });
                $first = reset($short);
                $rows .= '<tr><td>' . self::h($node['nd_name_vc']) . '</td><td>'
                    . (!$short ? '<span class="label label-success">' . self::t('fine') . '</span>'
                        : '<span class="label ' . ($first[0] >= 2 ? 'label-danger' : 'label-warning') . '" title="' . self::h(implode(', ', array_map(function ($x) { return ui_language::translate($x[1]); }, $short))) . '">'
                            . self::t($first[1]) . (count($short) > 1 ? ' +' . (count($short) - 1) : '') . '</span>')
                    . '</td></tr>';
            }
            $out .= '<p class="dwdh-head">' . self::t('Nodes') . '</p>'
                . ($rows !== '' ? '<table class="dw-usage dwdh-nodes">' . $rows . '</table>' . (count($nodes) > 6 ? '<p class="text-muted dwdh-more">' . self::t('and %s more', array(count($nodes) - 6)) . '</p>' : '')
                    : '<p class="text-muted">' . self::t('No nodes yet.') . '</p>');
        }
        $q = $zdbh->prepare("SELECT dc_domain_vc, dc_state_vc FROM x_dh_containers WHERE dc_acc_fk = :a AND dc_deleted_in IS NULL ORDER BY dc_domain_vc");
        $q->execute(array(':a' => $acc));
        $apps = $q->fetchAll(PDO::FETCH_ASSOC);
        $count = array('running' => 0, 'stopped' => 0, 'other' => 0);
        $list = '';
        foreach ($apps as $i => $c) {
            $st = (string) $c['dc_state_vc'];
            $k = $st === 'running' ? 'running' : ($st === 'stopped' ? 'stopped' : 'other');
            $count[$k]++;
            if ($i < 5) {
                $list .= '<tr><td>' . self::h($c['dc_domain_vc']) . '</td><td><span class="label ' . ($k === 'running' ? 'label-success' : ($k === 'stopped' ? 'label-default' : 'label-warning')) . '">'
                    . self::h(ui_language::translate($st)) . '</span></td></tr>';
            }
        }
        $out .= '<p class="dwdh-head">' . self::t('My apps') . '</p>';
        if ($apps) {
            $out .= '<p class="dwdh-sum">' . self::t('%s running', array($count['running'])) . ($count['stopped'] ? ' &middot; ' . self::t('%s stopped', array($count['stopped'])) : '')
                . ($count['other'] ? ' &middot; <b>' . self::t($count['other'] === 1 ? '1 needs a look' : '%s need a look', array($count['other'])) . '</b>' : '') . '</p>'
                . '<table class="dw-usage dwdh-apps">' . $list . '</table>' . (count($apps) > 5 ? '<p class="text-muted dwdh-more">' . self::t('and %s more', array(count($apps) - 5)) . '</p>' : '');
        } else {
            $out .= '<p class="text-muted">' . self::t('No apps yet.') . '</p>';
        }
        return $out . '<p class="dwdh-go"><a class="btn btn-default btn-sm" href="./?module=docker_hosting' . (dh::IsAdmin(dh::Account($acc)) ? '&amp;tab=nodes' : '') . '">' . self::t('Open Docker Hosting') . '</a></p>';
    }
}
