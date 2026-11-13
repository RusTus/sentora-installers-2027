/* Web Hardening: "What it caught".
   List: search and filters, older lines a page at a time (button, or scrolling to the end), and
   new lines added by themselves (every 3 seconds while the page is open and visible).
   Statistics: tiles and charts drawn here as SVG (nothing loaded from elsewhere).
   Everything comes from code/caught.php: the list's rows already escaped by the server, the
   statistics as numbers and plain words (put in with textContent only). */
(function () {
    'use strict';
    var card = document.getElementById('ph-caught');
    if (!card || !window.fetch) {
        return;
    }
    var D = card.dataset, feed = D.feed;
    var $ = function (sel) { return card.querySelector(sel); };
    var body = $('tbody'), empty = $('.ph-empty'), looked = $('.ph-looked'), count = $('.ph-count'), more = $('.ph-more'), scroller = $('.ph-scroll');
    var dot = $('.ph-live-dot'), dotText = $('.ph-live-text'), pauseBtn = $('.ph-pause');
    var q = $('.ph-q'), fKind = $('.ph-f-kind'), fAct = $('.ph-f-act'), fGuard = $('.ph-f-guard');
    var last = parseInt(D.last, 10) || 0, oldest = parseInt(D.oldest, 10) || 0, total = 0, shown = body.children.length;
    var paused = false, busy = false, loadingOlder = false, tab = 'list', days = 30, gen = 0;
    var SVGNS = 'http://www.w3.org/2000/svg';
    var COLORS = ['#447fb3', '#d1495b', '#edae49', '#00798c', '#8e6cbe', '#3f9c6b', '#e07a3f', '#5c6f84', '#c25b9b', '#9aa83a'];

    total = parseInt(D.count, 10) || 0;
    var statsAt = 0;

    function state(cls, text) {
        dot.classList.remove('is-off', 'is-err');
        if (cls) { dot.classList.add(cls); }
        dotText.textContent = text;
    }
    function filters() {
        return '&q=' + encodeURIComponent(q.value.trim()) + '&kind=' + encodeURIComponent(fKind.value) + '&act=' + encodeURIComponent(fAct.value) + '&guard=' + encodeURIComponent(fGuard.value);
    }
    function get(query) {
        return fetch(feed + '?' + query, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
            .then(function (j) { if (typeof j.looked === 'string') { looked.textContent = j.looked; } state(paused ? 'is-off' : '', D[paused ? 'paused' : 'live']); return j; });
    }
    function rowsOf(html) {
        var t = document.createElement('tbody');
        t.innerHTML = html;
        return Array.prototype.slice.call(t.children);
    }
    function showCount() {
        count.textContent = D.shown.replace('{n}', shown).replace('{t}', total);
        empty.hidden = shown > 0;
        if (!shown && (q.value || fKind.value || fAct.value || fGuard.value)) { empty.textContent = D.none; }
    }

    // ----- the list -----
    function reload() {
        var my = ++gen;
        get('before=0' + filters()).then(function (j) {
            if (my !== gen) { return; }
            body.innerHTML = j.rows;
            shown = j.count; total = j.total; oldest = j.oldest; last = Math.max(last, j.last);
            more.hidden = !j.more;
            scroller.scrollTop = 0;
            showCount();
        }).catch(function () { state('is-err', D.err); });
    }
    function older() {
        if (loadingOlder || more.hidden || !oldest) { return; }
        loadingOlder = true;
        var my = gen;
        more.textContent = D.loading;
        get('before=' + oldest + filters()).then(function (j) {
            if (my !== gen) { return; }
            rowsOf(j.rows).forEach(function (r) { body.appendChild(r); });
            shown += j.count; total = j.total; oldest = j.oldest || oldest;
            more.hidden = !j.more;
            showCount();
        }).catch(function () { state('is-err', D.err); }).then(function () {
            loadingOlder = false;
            more.textContent = more.getAttribute('data-label');
        });
    }
    function poll() {
        if (paused || busy || document.hidden) { return; }
        if (tab === 'stats') { return; }
        busy = true;
        var my = gen;
        get('after=' + last + filters()).then(function (j) {
            if (my !== gen || !j.count) { return; }
            if (j.more) { reload(); return; }
            var rows = rowsOf(j.rows);
            for (var i = rows.length - 1; i >= 0; i--) {
                rows[i].classList.add('ph-new');
                body.insertBefore(rows[i], body.firstChild);
            }
            last = Math.max(last, j.last);
            shown += j.count; total = j.total;
            showCount();
        }).catch(function () { state('is-err', D.err); }).then(function () { busy = false; });
    }
    more.setAttribute('data-label', more.textContent);
    more.addEventListener('click', older);
    scroller.addEventListener('scroll', function () {
        if (scroller.scrollTop + scroller.clientHeight > scroller.scrollHeight - 80) { older(); }
    });
    var timer = null;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(reload, 350); });
    [fKind, fAct, fGuard].forEach(function (el) { el.addEventListener('change', reload); });

    // ----- the statistics -----
    function el(name, attrs, text) {
        var e = document.createElementNS(SVGNS, name);
        for (var k in attrs) { if (attrs.hasOwnProperty(k)) { e.setAttribute(k, attrs[k]); } }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function svg(w, h) { return el('svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', role: 'img', preserveAspectRatio: 'xMidYMid meet' }); }
    function fmt(n) { return Number(n).toLocaleString(); }
    function clear(box) { while (box.firstChild) { box.removeChild(box.firstChild); } }
    function note(box, text) { clear(box); var p = document.createElement('p'); p.className = 'ph-muted'; p.textContent = text; box.appendChild(p); }
    function filterBy(what, value) {
        q.value = what === 'q' ? value : '';
        fKind.value = what === 'kind' ? value : '';
        fAct.value = ''; fGuard.value = '';
        show('list');
        reload();
    }

    function tiles(s) {
        var box = $('.ph-tiles');
        clear(box);
        [[D.total, s.drop + s.watch, ''], [D.drop, s.drop, 'is-bad'], [D.watch, s.watch, 'is-watch'], [D.sources, s.sources, '']].forEach(function (t) {
            var d = document.createElement('div'), b = document.createElement('b'), sp = document.createElement('span');
            d.className = 'ph-tile ' + t[2];
            b.textContent = fmt(t[1]);
            sp.textContent = t[0];
            d.appendChild(b); d.appendChild(sp);
            box.appendChild(d);
        });
    }
    function colorOf(kinds) {
        var map = {};
        kinds.forEach(function (k, i) { map[k.key] = i < COLORS.length - 1 ? COLORS[i] : COLORS[COLORS.length - 1]; });
        return map;
    }
    function daysChart(s, colors) {
        var box = $('.ph-c-days');
        if (!s.kinds.length) { note(box, D.empty); return; }
        clear(box);
        var W = 760, H = 220, L = 40, B = 24, T = 8, n = s.days.length, max = 0;
        s.days.forEach(function (d) { var t = 0; for (var k in d.by) { if (d.by.hasOwnProperty(k)) { t += d.by[k]; } } d.t = t; max = Math.max(max, t); });
        max = Math.max(1, max);
        var g = svg(W, H), bw = (W - L - 4) / n;
        for (var i = 0; i <= 4; i++) {
            var y = T + (H - T - B) * (1 - i / 4);
            g.appendChild(el('line', { x1: L, x2: W, y1: y, y2: y, 'class': 'ph-grid' }));
            g.appendChild(el('text', { x: L - 6, y: y + 4, 'text-anchor': 'end', 'class': 'ph-axis' }, fmt(Math.round(max * i / 4))));
        }
        s.days.forEach(function (d, i) {
            var x = L + i * bw + Math.max(1, bw * 0.15), w = Math.max(1, bw * 0.7), y = H - B;
            s.kinds.forEach(function (k) {
                var v = d.by[k.key] || 0;
                if (!v) { return; }
                var h = (H - T - B) * v / max;
                y -= h;
                var r = el('rect', { x: x, y: y, width: w, height: h, fill: colors[k.key], rx: 1.5 });
                r.appendChild(el('title', {}, d.day + ' · ' + k.label + ': ' + fmt(v)));
                g.appendChild(r);
            });
            var every = Math.ceil(n / 10);
            if (i % every === 0 || n <= 10) {
                g.appendChild(el('text', { x: x + w / 2, y: H - 6, 'text-anchor': 'middle', 'class': 'ph-axis' }, d.day.slice(5)));
            }
        });
        box.appendChild(g);
    }
    function donut(s, colors) {
        var box = $('.ph-c-kinds');
        if (!s.kinds.length) { note(box, D.empty); return; }
        clear(box);
        var all = 0;
        s.kinds.forEach(function (k) { all += k.n; });
        var wrap = document.createElement('div'); wrap.className = 'ph-donut';
        var g = svg(160, 160), a = -Math.PI / 2, R = 70, r = 44;
        s.kinds.forEach(function (k) {
            var f = k.n / all, b = a + f * Math.PI * 2;
            if (f >= 0.9999) { b = a + Math.PI * 2 - 0.0001; }
            var big = b - a > Math.PI ? 1 : 0;
            var p = ['M', 80 + R * Math.cos(a), 80 + R * Math.sin(a), 'A', R, R, 0, big, 1, 80 + R * Math.cos(b), 80 + R * Math.sin(b),
                'L', 80 + r * Math.cos(b), 80 + r * Math.sin(b), 'A', r, r, 0, big, 0, 80 + r * Math.cos(a), 80 + r * Math.sin(a), 'Z'].join(' ');
            var path = el('path', { d: p, fill: colors[k.key], 'class': 'ph-click' });
            path.appendChild(el('title', {}, k.label + ': ' + fmt(k.n)));
            path.addEventListener('click', function () { filterBy('kind', k.key); });
            g.appendChild(path);
            a = b;
        });
        g.appendChild(el('text', { x: 80, y: 78, 'text-anchor': 'middle', 'class': 'ph-big' }, fmt(all)));
        g.appendChild(el('text', { x: 80, y: 96, 'text-anchor': 'middle', 'class': 'ph-axis' }, D.total));
        wrap.appendChild(g);
        var ul = document.createElement('ul'); ul.className = 'ph-legend';
        s.kinds.forEach(function (k) {
            var li = document.createElement('li'), i = document.createElement('i'), t = document.createElement('span'), n = document.createElement('b');
            i.style.background = colors[k.key];
            t.textContent = k.label;
            n.textContent = fmt(k.n) + ' · ' + Math.round(k.n * 100 / all) + '%';
            li.appendChild(i); li.appendChild(t); li.appendChild(n);
            li.className = 'ph-click';
            li.addEventListener('click', function () { filterBy('kind', k.key); });
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        box.appendChild(wrap);
    }
    function bars(sel, list, color, click) {
        var box = $(sel);
        if (!list.length) { note(box, D.empty); return; }
        clear(box);
        var max = 1;
        list.forEach(function (x) { max = Math.max(max, x.n); });
        list.forEach(function (x) {
            var row = document.createElement('div'), lab = document.createElement('span'), bar = document.createElement('i'), n = document.createElement('b');
            row.className = 'ph-bar' + (click ? ' ph-click' : '');
            lab.textContent = x.label;
            lab.title = x.label;
            bar.style.width = Math.max(2, Math.round(x.n * 100 / max)) + '%';
            bar.style.background = color;
            n.textContent = fmt(x.n);
            var track = document.createElement('em'); track.appendChild(bar);
            row.appendChild(lab); row.appendChild(track); row.appendChild(n);
            if (click) { row.addEventListener('click', function () { filterBy('q', x.label); }); }
            box.appendChild(row);
        });
    }
    function hours(s) {
        var box = $('.ph-c-hours');
        clear(box);
        var W = 360, H = 150, B = 20, T = 8, max = 1, n = s.hours.length;
        s.hours.forEach(function (h) { max = Math.max(max, h.n); });
        var g = svg(W, H), step = W / (n - 1 || 1), pts = [];
        s.hours.forEach(function (h, i) { pts.push([i * step, T + (H - T - B) * (1 - h.n / max)]); });
        var area = 'M0 ' + (H - B) + ' ' + pts.map(function (p) { return 'L' + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ') + ' L' + W + ' ' + (H - B) + ' Z';
        g.appendChild(el('path', { d: area, 'class': 'ph-area' }));
        g.appendChild(el('path', { d: 'M' + pts.map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L'), 'class': 'ph-line' }));
        s.hours.forEach(function (h, i) {
            if (h.n) {
                var c = el('circle', { cx: pts[i][0], cy: pts[i][1], r: 3, 'class': 'ph-pt' });
                c.appendChild(el('title', {}, h.label + ': ' + fmt(h.n)));
                g.appendChild(c);
            }
            if (i % 4 === 0) { g.appendChild(el('text', { x: Math.min(W - 14, Math.max(14, i * step)), y: H - 4, 'text-anchor': 'middle', 'class': 'ph-axis' }, h.label)); }
        });
        box.appendChild(g);
    }
    function stats(quiet) {
        var my = ++gen;
        statsAt = Date.now();
        if (!quiet) { note($('.ph-c-days'), D.loading); }
        busy = true;
        get('stats=1&days=' + days).then(function (j) {
            if (my !== gen || !j.stats) { return; }
            var s = j.stats, colors = colorOf(s.kinds);
            tiles(s);
            daysChart(s, colors);
            donut(s, colors);
            hours(s);
            bars('.ph-c-ips', s.ips, '#d1495b', true);
            bars('.ph-c-targets', s.targets, '#447fb3', true);
        }).catch(function () { state('is-err', D.err); }).then(function () { busy = false; });
    }

    // ----- tabs, range, pause -----
    function show(which) {
        tab = which;
        card.querySelectorAll('.ph-tabs [data-tab]').forEach(function (b) {
            b.classList.toggle('on', b.getAttribute('data-tab') === which);
            b.setAttribute('aria-selected', b.getAttribute('data-tab') === which ? 'true' : 'false');
        });
        card.querySelectorAll('.ph-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== which; });
        gen++;
        if (which === 'stats') { stats(false); }
    }
    card.querySelectorAll('.ph-tabs [data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-tab')); if (b.getAttribute('data-tab') === 'list') { reload(); } });
    });
    card.querySelectorAll('.ph-range [data-days]').forEach(function (b) {
        b.addEventListener('click', function () {
            days = parseInt(b.getAttribute('data-days'), 10) || 30;
            card.querySelectorAll('.ph-range [data-days]').forEach(function (x) { x.classList.toggle('active', x === b); });
            stats(false);
        });
    });
    pauseBtn.addEventListener('click', function () {
        paused = !paused;
        pauseBtn.textContent = pauseBtn.getAttribute(paused ? 'data-resume' : 'data-pause');
        state(paused ? 'is-off' : '', D[paused ? 'paused' : 'live']);
        if (!paused) { poll(); }
    });
    document.addEventListener('visibilitychange', poll);
    // (the list every 3 seconds; the statistics, which take more work, every 15)
    setInterval(function () {
        if (tab !== 'stats') { poll(); return; }
        if (!paused && !busy && !document.hidden && Date.now() - statsAt > 15000) { stats(true); }
    }, 3000);
})();
