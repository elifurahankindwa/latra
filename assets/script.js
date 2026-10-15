
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.content) || '';
}

function apiForm(action, params = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', getCsrfToken());
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    return fetch('api.php', { method: 'POST', body: fd });
}

function apiJson(action, params = {}) {
    return apiForm(action, params).then(r => r.json());
}

function sseUrl(action, params = {}) {
    const q = new URLSearchParams(Object.assign({ action, _csrf: getCsrfToken() }, params));
    return 'api.php?' + q.toString();
}

// Lucide runtime replaces every <i data-lucide="..."> with the matching inline SVG.
// Call after injecting any HTML that contains such placeholders.
function refreshIcons() {
    if (window.lucide) {
        lucide.createIcons();
    }
}


// PROGRESS MODAL CLASS

class ProgressModal {
    constructor({ title, subtitle = '', icon = 'satellite-dish', showLog = false, cancelable = false } = {}) {
        this._startTime = Date.now();
        this._total = 100;
        this._current = 0;

        this._overlay = document.createElement('div');
        this._overlay.className = 'progress-modal-overlay';
        this._overlay.innerHTML = `
            <div class="progress-modal-box" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
                <div class="progress-modal-header">
                    <div class="progress-modal-icon" id="pmIcon"><i data-lucide="${icon}"></i></div>
                    <div>
                        <div class="progress-modal-title" id="pmTitle">${title}</div>
                        <div class="progress-modal-subtitle" id="pmSubtitle">${subtitle}</div>
                    </div>
                </div>
                <div class="progress-modal-body">
                    <div class="progress-counters">
                        <span class="pct" id="pmPct">0%</span>
                        <span class="eta" id="pmEta">Starting...</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" id="pmFill" style="width:0%"></div>
                    </div>
                    <div class="progress-status-text" id="pmStatus">Initialising...</div>
                    ${showLog ? '<div class="progress-log" id="pmLog"></div>' : ''}
                </div>
                <div class="progress-modal-footer" id="pmFooter" style="display:none">
                    <button class="btn btn-primary" id="pmClose">Done</button>
                </div>
            </div>
        `;

        document.body.appendChild(this._overlay);
        document.getElementById('pmClose')?.addEventListener('click', () => this.destroy());

        if (cancelable) {
            this._overlay.addEventListener('click', e => {
                if (e.target === this._overlay) this.destroy();
            });
        }
    }

    /** Set the total number of steps (for ETA calculation) */
    setTotal(total) {
        this._total = total || 100;
    }

    /** Update progress — current is the number of completed steps */
    update(current, statusText = '', total = null) {
        if (total !== null) this._total = total;
        this._current = current;
        const pct = Math.min(99, Math.round((current / this._total) * 100));
        const elapsed = (Date.now() - this._startTime) / 1000;
        const rate = current > 0 ? elapsed / current : 0;
        const remaining = Math.max(0, (this._total - current) * rate);

        const fill = document.getElementById('pmFill');
        const pctEl = document.getElementById('pmPct');
        const etaEl = document.getElementById('pmEta');
        const statusEl = document.getElementById('pmStatus');

        if (fill) fill.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        if (etaEl) etaEl.textContent = current > 0
            ? 'ETA: ' + this._formatTime(remaining)
            : 'Calculating ETA...';
        if (statusEl && statusText) statusEl.textContent = statusText;
    }

    /** Append a line to the live log */
    log(text, type = 'info', meta = '') {
        const log = document.getElementById('pmLog');
        if (!log) return;
        const iconMap = { ok: ['check', 'pl-ok'], fail: ['x', 'pl-fail'], info: ['circle-dot', 'pl-info'] };
        const [icn, cls] = iconMap[type] || iconMap.info;
        const entry = document.createElement('div');
        entry.className = 'progress-log-entry';
        entry.innerHTML = `
            <span class="pl-icon ${cls}"><i data-lucide="${icn}"></i></span>
            <span class="pl-text">${this._esc(text)}</span>
            ${meta ? `<span class="pl-meta">${this._esc(meta)}</span>` : ''}
        `;
        log.appendChild(entry);
        refreshIcons();
        log.scrollTop = log.scrollHeight;
    }

    /** Show completion state */
    finish(success, message = '') {
        const pct = success ? 100 : this._current > 0 ? Math.round((this._current / this._total) * 100) : 100;
        const fill = document.getElementById('pmFill');
        const pctEl = document.getElementById('pmPct');
        const etaEl = document.getElementById('pmEta');
        const statusEl = document.getElementById('pmStatus');
        const icon = document.getElementById('pmIcon');
        const footer = document.getElementById('pmFooter');

        if (fill) {
            fill.style.width = pct + '%';
            fill.classList.add(success ? 'fill-success' : 'fill-error');
        }
        if (pctEl) pctEl.textContent = pct + '%';
        const elapsed = ((Date.now() - this._startTime) / 1000).toFixed(1);
        if (etaEl) etaEl.textContent = `Done in ${elapsed}s`;
        if (statusEl) statusEl.textContent = message || (success ? 'Completed successfully' : 'Completed with errors');
        if (icon) {
            icon.classList.add(success ? 'icon-success' : 'icon-error');
            icon.innerHTML = `<i data-lucide="${success ? 'check' : 'x'}"></i>`;
            refreshIcons();
        }
        if (footer) footer.style.display = 'flex';
    }

    destroy() {
        this._overlay.remove();
    }

    _formatTime(s) {
        if (s < 60) return Math.ceil(s) + 's';
        return Math.ceil(s / 60) + 'm ' + Math.ceil(s % 60) + 's';
    }

    _esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
}


// SIMULATED PROGRESS

function simulatedProgress(title, fetchPromise, { subtitle = '', icon = 'satellite-dish' } = {}) {
    const pm = new ProgressModal({ title, subtitle, icon });
    pm.setTotal(100);

    
    let fakeVal = 0;
    let finished = false;
    const timers = [];
    const milestones = [
        { pct: 15, delay: 400,  msg: 'Connecting...' },
        { pct: 35, delay: 900,  msg: 'Sending request...' },
        { pct: 55, delay: 1600, msg: 'Waiting for response...' },
        { pct: 72, delay: 2400, msg: 'Processing data...' },
        { pct: 85, delay: 3500, msg: 'Almost done...' },
    ];

    milestones.forEach(({ pct, delay, msg }) => {
        timers.push(setTimeout(() => {
            if (!finished && fakeVal < pct) {
                fakeVal = pct;
                pm.update(pct, msg);
            }
        }, delay));
    });

    return fetchPromise
        .then(data => {
            finished = true;
            timers.forEach(clearTimeout);
            pm.finish(data.success !== false, data.message || data.error || '');
            return data;
        })
        .catch(err => {
            finished = true;
            timers.forEach(clearTimeout);
            pm.finish(false, 'Error: ' + err.message);
            throw err;
        });
}


//bulk operations via Server-Sent Events

function sseProgress(title, url, { subtitle = '', icon = 'layers', onDone = null } = {}) {
    const pm = new ProgressModal({ title, subtitle, icon, showLog: true });

    const es = new EventSource(url);

    es.onmessage = function(e) {
        let data;
        try { data = JSON.parse(e.data); } catch { return; }

        if (data.type === 'init') {
            pm.setTotal(data.total);
            pm.update(0, data.message || 'Starting...');
        }

        if (data.type === 'progress') {
            pm.setTotal(data.total);
            pm.update(data.current, `Processing: ${data.vehicle || 'vehicle ' + data.current} (${data.current}/${data.total})`);

            const logType = data.status === 'ok' || data.latra_status === 'sent' ? 'ok' : 'fail';
            const meta = data.inserted != null
                ? `+${data.inserted} msgs`
                : (data.latra_status || '');
            pm.log(data.vehicle || `Vehicle ${data.current}`, logType, meta);
        }

        if (data.type === 'done') {
            es.close();
            const success = (data.failed || 0) === 0;
            pm.finish(success, `${data.success || 0} succeeded, ${data.failed || 0} failed`);
            if (onDone) onDone(data);
        }

        if (data.type === 'error') {
            es.close();
            pm.finish(false, data.message || 'An error occurred');
        }
    };

    es.onerror = function() {
        es.close();
        pm.finish(false, 'Connection lost. Please try again.');
    };

    return pm;
}


// GLOBAL SEARCH

document.getElementById('globalSearch')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        const q = this.value.trim();
        if (q) location.href = 'vehicles?search=' + encodeURIComponent(q);
    }
});


// SYNC WIALON — global (topbar + vehicles page button)

function syncWialon(event) {
    Swal.fire({
        title: 'Sync Vehicles',
        text: 'Sync all vehicles from Wialon?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, sync'
    }).then((result) => {
        if (!result.isConfirmed) return;

        simulatedProgress(
            'Syncing from Wialon',
            apiJson('sync_wialon'),
            { subtitle: 'Fetching all vehicle units...', icon: 'rotate-cw' }
        ).then(data => {
            if (data.success !== false) {
                setTimeout(() => location.reload(), 1200);
            }
        }).catch(() => {});
    });
}


// CLOSE MODALS ON OVERLAY CLICK

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.remove();
    }
});


// LIVE TABLE SEARCH

document.getElementById('vehicleSearch')?.addEventListener('input', function() {
    const term = this.value.toLowerCase();
    document.querySelectorAll('#vehiclesTable tbody tr').forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        row.style.display = searchData.includes(term) ? '' : 'none';
    });
});


// SEARCHABLE DROPDOWNS

document.querySelectorAll('select.searchable-select').forEach(select => {
    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-dropdown';
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    select.hidden = true;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'form-input searchable-dropdown-button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    wrapper.appendChild(button);

    const menu = document.createElement('div');
    menu.className = 'searchable-dropdown-menu';
    menu.setAttribute('role', 'listbox');
    menu.innerHTML = '<input type="search" class="searchable-dropdown-input" placeholder="Search..." autocomplete="off">' +
        '<div class="searchable-dropdown-options"></div>';
    wrapper.appendChild(menu);

    const searchInput = menu.querySelector('.searchable-dropdown-input');
    const optionsContainer = menu.querySelector('.searchable-dropdown-options');

    function renderOptions(term = '') {
        const query = term.trim().toLowerCase();
        optionsContainer.innerHTML = '';
        Array.from(select.options).forEach(option => {
            if (query && !option.text.toLowerCase().includes(query)) return;
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'searchable-dropdown-option';
            item.textContent = option.text.trim();
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            item.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                closeMenu();
            });
            optionsContainer.appendChild(item);
        });

        if (!optionsContainer.children.length) {
            const empty = document.createElement('div');
            empty.className = 'searchable-dropdown-empty';
            empty.textContent = 'No matches found';
            optionsContainer.appendChild(empty);
        }
    }

    function updateButton() {
        const selected = select.options[select.selectedIndex];
        button.textContent = selected ? selected.text.trim() : 'Select an option';
    }

    function openMenu() {
        document.querySelectorAll('.searchable-dropdown.open').forEach(dropdown => {
            if (dropdown !== wrapper) dropdown.classList.remove('open');
        });
        wrapper.classList.add('open');
        button.setAttribute('aria-expanded', 'true');
        searchInput.value = '';
        renderOptions();
        searchInput.focus();
    }

    function closeMenu() {
        wrapper.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    }

    button.addEventListener('click', () => wrapper.classList.contains('open') ? closeMenu() : openMenu());
    searchInput.addEventListener('input', () => renderOptions(searchInput.value));
    select.addEventListener('change', updateButton);
    document.addEventListener('click', event => {
        if (!wrapper.contains(event.target)) closeMenu();
    });

    updateButton();
});


// =====================================================
// ENTERPRISE DASHBOARD INTERACTIONS
// =====================================================

// Header / toolbar dropdowns
document.addEventListener('click', function (e) {
    const dd = e.target.closest('[data-dd]');
    const btn = e.target.closest('[data-dd-btn]');
    if (btn && dd) {
        document.querySelectorAll('[data-dd].open').forEach(function (o) {
            if (o !== dd) o.classList.remove('open');
        });
        dd.classList.toggle('open');
    } else if (!dd) {
        document.querySelectorAll('[data-dd].open').forEach(function (o) {
            o.classList.remove('open');
        });
    }
});

// Customer-updates tabs
document.addEventListener('click', function (e) {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    document.querySelectorAll('[data-tab]').forEach(function (t) {
        t.classList.toggle('active', t === tab);
    });
    const feedId = tab.getAttribute('data-tab');
    document.querySelectorAll('[data-feed]').forEach(function (f) {
        f.style.display = (f.getAttribute('data-feed') === feedId) ? '' : 'none';
    });
    applyUnreadFilter();
});

// Only-unread toggle
document.addEventListener('click', function (e) {
    const tg = e.target.closest('[data-unread-toggle]');
    if (!tg) return;
    tg.classList.toggle('on');
    const box = tg.querySelector('input, [role="checkbox"]');
    if (box) box.setAttribute('aria-checked', String(tg.classList.contains('on')));
    else tg.setAttribute('aria-checked', String(tg.classList.contains('on')));
    applyUnreadFilter();
});

// Feed toolbar "All / Only new" selection
document.addEventListener('click', function (e) {
    const set = e.target.closest('[data-unread-set]');
    if (!set) return;
    const on = set.getAttribute('data-unread-set') === 'new';
    const tg = document.querySelector('[data-unread-toggle]');
    if (!tg) return;
    tg.classList.toggle('on', on);
    tg.setAttribute('aria-checked', String(on));
    const dd = set.closest('[data-dd]');
    if (dd) dd.classList.remove('open');
    const btn = dd?.querySelector('[data-dd-btn] span');
    if (btn) btn.textContent = on ? 'Only new' : 'All';
    applyUnreadFilter();
});

function applyUnreadFilter() {
    const active = document.querySelector('[data-unread-toggle].on');
    const onlyNew = !!active;
    const tab = document.querySelector('[data-tab].active');
    if (!tab) return;
    const feedId = tab.getAttribute('data-tab');
    document.querySelectorAll('[data-feed="' + feedId + '"] .f-item').forEach(function (item) {
        const unread = item.getAttribute('data-read') !== '1';
        item.style.display = (onlyNew && !unread) ? 'none' : '';
    });
}

// Mark a feed item as read on click
document.addEventListener('click', function (e) {
    const item = e.target.closest('.f-item');
    if (!item || item.getAttribute('data-read') === '1') return;
    item.setAttribute('data-read', '1');
    const tag = item.querySelector('.f-tag-new');
    if (tag) tag.remove();
    applyUnreadFilter();
});

// Mark all as read (feed toolbar icon)
function markFeedRead() {
    document.querySelectorAll('.f-item').forEach(function (item) {
        item.setAttribute('data-read', '1');
        const tag = item.querySelector('.f-tag-new');
        if (tag) tag.remove();
    });
    applyUnreadFilter();
}

// Map: Map / Satellite switching helper
function setupMapLayers(leafletMap, osmLayer, satLayer) {
    document.querySelectorAll('.map-seg button[data-layer]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.map-seg button[data-layer]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
            const sat = btn.getAttribute('data-layer') === 'sat';
            if (osmLayer && satLayer) {
                if (sat) {
                    if (leafletMap.hasLayer(osmLayer)) leafletMap.removeLayer(osmLayer);
                    if (!leafletMap.hasLayer(satLayer)) satLayer.addTo(leafletMap);
                } else {
                    if (leafletMap.hasLayer(satLayer)) leafletMap.removeLayer(satLayer);
                    if (!leafletMap.hasLayer(osmLayer)) osmLayer.addTo(leafletMap);
                }
            }
        });
    });
}

// Fleet map initializer (index.php publishes unit data via window.__fleetMapUnits).
// Runs here, not inline, so setupMapLayers is defined and the DOM is ready.
function initFleetMap() {
    const mapEl = document.getElementById('fleetMap');
    const units = window.__fleetMapUnits;
    if (!mapEl || !units) return;

    const map = L.map('fleetMap', { zoomControl: false }).setView([-6.7924, 39.2083], 11);
    L.control.zoom({ position: 'topright' }).addTo(map);

    const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: '&copy; Esri World Imagery'
    });

    setupMapLayers(map, osmLayer, satLayer);

    const now = Math.floor(Date.now() / 1000);
    const bounds = L.latLngBounds();
    units.forEach(function (u, idx) {
        if (!u.lat || !u.lng) return;
        const age = now - (u.last_message_time || 0);
        // Live tab (LATRA portal) supplies u.color; index.php falls back to age-based colours.
        const color = u.color || (age < 86400 ? '#2F9E57' : (age < 7 * 86400 ? '#2F6BEB' : '#9AA0A6'));
        const icon = idx === 0
            ? L.divIcon({
                className: '',
                html: '<div style="width:17px;height:17px;border-radius:50%;background:#2F6BEB;border:2px solid #fff;box-shadow:0 0 5px rgba(0,0,0,.45);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">1</div>',
                iconSize: [17, 17], iconAnchor: [8, 8]
            })
            : L.divIcon({
                className: '',
                html: '<div style="width:11px;height:11px;border-radius:50%;background:' + color + ';border:2px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.4)"></div>',
                iconSize: [11, 11], iconAnchor: [5, 5]
            });
        const m = L.marker([u.lat, u.lng], { icon }).addTo(map);
        const speedTxt = 'Speed: <b>' + (u.speed !== null && u.speed !== undefined ? u.speed : 0) + ' km/h</b>';
        const ageTxt = u.last_message_time ? new Date(u.last_message_time * 1000).toLocaleString() + ' (' + (age < 60 ? age + 's' : age < 3600 ? Math.floor(age / 60) + 'm' : age < 86400 ? Math.floor(age / 3600) + 'h' : Math.floor(age / 86400) + 'd') + ' ago)' : 'never';
        m.bindPopup('<strong>' + (u.vehicle_reg_no || 'Unknown') + '</strong><br><small>' + (u.name || '') + '</small><br>' + speedTxt + '<br>Satellites: ' + (u.satellite_count || 0) + '<br><small>Last msg: ' + ageTxt + '</small>');
        if (typeof bindGeoNameTooltip === 'function') {
            bindGeoNameTooltip(m, u.lat, u.lng, (u.vehicle_reg_no || 'Unknown') + (u.name ? ' — ' + u.name : ''));
        } else {
            m.bindTooltip((u.vehicle_reg_no || 'Unknown') + (u.name ? ' — ' + u.name : ''));
        }
        bounds.extend([u.lat, u.lng]);
    });

    if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [22, 22] });
        if (map.getZoom() > 15) map.setZoom(15);
    } else {
        map.setView([-6.7924, 39.2083], 9);
    }
}

document.addEventListener('DOMContentLoaded', initFleetMap);
if (document.readyState === 'interactive' || document.readyState === 'complete') {
    initFleetMap();
}


// =====================================================
// PREVIEW MODAL — generic detail/list preview
// Any element with [data-preview] opens a preview
// sourced from preview.php (read-only, session-guarded).
// =====================================================

const DashPreview = (function () {
    let overlay = null;

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    function shell(title, icon) {
        const o = document.createElement('div');
        o.className = 'modal-overlay preview-overlay';
        o.setAttribute('role', 'presentation');
o.innerHTML = '<div class="modal preview-modal" role="dialog" aria-modal="true">'
            + '<div class="modal-header"><h3><i data-lucide="' + esc(icon || 'info') + '"></i><span data-pv-head>' + esc(title || 'Preview') + '</span></h3>'
            + '<button type="button" class="icon-btn" data-pv-close aria-label="Close preview"><i data-lucide="x"></i></button></div>'
            + '<div class="modal-body"><div class="pv-subtitle" data-pv-subtitle></div>'
            + '<div class="pv-content" data-pv-content><div class="pv-empty"><i data-lucide="loader-circle"></i> Loading preview…</div></div></div>'
            + '<div class="modal-actions" data-pv-footer></div>'
            + '</div>';
        document.body.appendChild(o);
        refreshIcons();
        o.addEventListener('click', function (e) {
            if (e.target === o || e.target.closest('[data-pv-close]')) close();
        });
        overlay = o;
        return o;
    }

    function set(o, d, fallbackTitle, fallbackIcon) {
        if (!o || o !== overlay) return;
        const head = o.querySelector('[data-pv-head]');
        const sub = o.querySelector('[data-pv-subtitle]');
        const body = o.querySelector('[data-pv-content]');
        const foot = o.querySelector('[data-pv-footer]');
        if (head) head.textContent = (d && d.title) || fallbackTitle || 'Preview';
        const icon = o.querySelector('.modal-header h3 i');
        if (icon) {
            icon.setAttribute('data-lucide', esc((d && d.icon) || fallbackIcon || 'info'));
        }
        if (sub) sub.innerHTML = (d && d.subtitle) ? esc(d.subtitle) : '';
        if (body) body.innerHTML = (d && d.content) || '<div class="pv-empty">Nothing to show.</div>';
        if (foot) {
            foot.innerHTML = '';
            if (d && Array.isArray(d.footer)) {
                d.footer.forEach(function (f) {
                    const a = document.createElement('a');
                    a.className = 'btn btn-primary';
                    a.href = f.href;
                    a.innerHTML = '<i data-lucide="' + esc(f.icon || 'arrow-up-right-from-square') + '"></i>' + esc(f.label || 'Open page');
                    foot.appendChild(a);
                });
            }
        }
        refreshIcons();
    }

    function get(params, fallback) {
        close();
        const o = shell(fallback && fallback.title, fallback && fallback.icon);
        const q = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) q.append(k, params[k]);
        });
        fetch('preview.php?' + q.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () {
                    return { ok: false, error: 'Preview endpoint error (' + r.status + ')' };
                });
            })
            .then(function (d) { set(o, d, fallback && fallback.title, fallback && fallback.icon); })
            .catch(function (err) {
                set(o, {
                    ok: false,
                    icon: 'triangle-alert',
                    title: (fallback && fallback.title) || 'Preview',
                    subtitle: '',
                    content: '<div class="pv-empty pv-error"><i data-lucide="triangle-alert"></i> Could not load preview: ' + esc(err.message) + '</div>'
                });
            });
        return o;
    }

    function close() {
        if (overlay) overlay.remove();
        overlay = null;
    }

    function trigger(el) {
        if (!el) return;
        get({
            k: el.getAttribute('data-preview'),
            id: el.getAttribute('data-id'),
            col: el.getAttribute('data-col'),
            state: el.getAttribute('data-state'),
            span: el.getAttribute('data-span')
        }, { title: el.getAttribute('data-pv-title'), icon: el.getAttribute('data-pv-icon') });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay) close();
    });

    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-preview]');
        if (!el) return;
        e.preventDefault();
        trigger(el);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const el = e.target.closest && e.target.closest('[data-preview][tabindex]');
        if (!el) return;
        e.preventDefault();
        trigger(el);
    });

    // Let a landing page stop navigation (e.g. link-only fallback)
    window.DashPreview = { get: get, close: close, trigger: trigger };
    return { get, close, trigger };
})();

// Quick action: push an activity to a unit scope (Service / Speeding / Power / SOS)
function quickPush(activityId, activityName) {
    Swal.fire({
        title: 'Push ' + activityName + ' to LATRA',
        html: '<div class="swal-scope">'
            + '<label class="swal-scope-label" for="swalScope">Unit scope</label>'
            + '<select id="swalScope" class="swal-scope-select">'
            + '<option value="online">Online units now</option>'
            + '<option value="all">All tracked units</option>'
            + '</select>'
            + '<p class="swal-scope-hint">Logs <b>' + activityName + '</b> for each selected unit using its latest telemetry.</p>'
            + '</div>',
        showCancelButton: true,
        confirmButtonText: 'Push activity',
        focusConfirm: false,
        preConfirm: function () {
            const s = document.getElementById('swalScope');
            return s ? s.value : 'online';
        }
    }).then(function (r) {
        if (!r.isConfirmed) return;
        const scope = r.value || 'online';
        Swal.fire({ title: 'Preparing unit list…' });
        Swal.showLoading();
        fetch('preview.php?k=scope&scope=' + encodeURIComponent(scope), { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                Swal.close();
                if (!d || !d.ok || !d.ids || !d.ids.length) {
                    Swal.fire('No units', 'No units in the selected scope to push to.', 'warning');
                    return;
                }
                const url = sseUrl('bulk_push_activity', { vehicle_ids: d.ids.join(','), activity_id: activityId });
                sseProgress('Pushing ' + activityName + ' to LATRA', url, {
                    subtitle: d.count + ' unit(s) · ' + activityName,
                    icon: 'send'
                });
            })
            .catch(function () {
                Swal.close();
                Swal.fire('Error', 'Could not load the unit list.', 'error');
            });
    });
}




// Boot: render any server-side <i data-lucide> placeholders.
document.addEventListener("DOMContentLoaded", refreshIcons);
refreshIcons();
