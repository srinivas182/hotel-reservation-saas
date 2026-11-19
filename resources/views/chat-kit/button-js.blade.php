/* {{ config('app.name') }} — WhatsApp button. No cookies, no trackers. */
(function () {
    'use strict';
    var c = {!! json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    if (!c.url || document.getElementById('hra-wa-button')) { return; }
    var lang = (document.documentElement.lang || c.locale).slice(0, 2);
    var t = c.texts[lang] || c.texts[c.locale];
    function minutes(hhmm) { var p = hhmm.split(':'); return (+p[0]) * 60 + (+p[1]); }
    function online() {
        try {
            var parts = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: c.tz }).format(new Date());
            var now = minutes(parts), from = minutes(c.from), until = minutes(c.until);
            return from <= until ? now >= from && now < until : now >= from || now < until;
        } catch (e) { return true; }
    }
    var on = online();
    var root = document.createElement('div');
    root.id = 'hra-wa-button';
    root.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483000;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;display:flex;flex-direction:column;align-items:flex-end;gap:8px';
    var bubble = document.createElement('div');
    bubble.setAttribute('role', 'status');
    bubble.style.cssText = 'display:none;max-width:240px;background:#fff;color:#111827;border-radius:14px;padding:10px 32px 10px 12px;box-shadow:0 6px 24px rgba(0,0,0,.18);font-size:14px;line-height:1.35;position:relative';
    bubble.textContent = t.bubble;
    var x = document.createElement('button');
    x.type = 'button';
    x.setAttribute('aria-label', t.close);
    x.textContent = '×';
    x.style.cssText = 'position:absolute;top:4px;right:6px;border:0;background:none;font-size:18px;line-height:1;cursor:pointer;color:#6b7280';
    x.onclick = function () { bubble.style.display = 'none'; try { sessionStorage.setItem('hra-wa-bubble', '1'); } catch (e) {} };
    bubble.appendChild(x);
    var a = document.createElement('a');
    a.href = c.url;
    a.target = '_blank';
    a.rel = 'noopener';
    a.setAttribute('aria-label', t.label);
    a.style.cssText = 'display:flex;align-items:center;gap:10px;background:' + c.color + ';color:#fff;text-decoration:none;border-radius:999px;padding:10px 16px 10px 12px;box-shadow:0 6px 24px rgba(0,0,0,.22);font-weight:600;font-size:15px';
    a.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>';
    function el(tag, css, text) { var e = document.createElement(tag); e.style.cssText = css; if (text) { e.textContent = text; } return e; }
    var words = el('span', 'display:flex;flex-direction:column;line-height:1.15');
    words.appendChild(el('span', '', t.label));
    var status = el('span', 'font-size:12px;font-weight:500;opacity:.9;display:flex;align-items:center;gap:5px');
    status.appendChild(el('span', 'width:8px;height:8px;border-radius:50%;background:' + (on ? '#4ade80' : '#fde68a')));
    status.appendChild(el('span', '', on ? t.online : t.later));
    words.appendChild(status);
    a.appendChild(words);
    root.appendChild(bubble);
    root.appendChild(a);
    function mount() {
        document.body.appendChild(root);
        var seen = false;
        try { seen = sessionStorage.getItem('hra-wa-bubble') === '1'; } catch (e) {}
        if (!seen) { setTimeout(function () { bubble.style.display = 'block'; }, 3000); }
    }
    if (document.body) { mount(); } else { document.addEventListener('DOMContentLoaded', mount); }
})();
