/**
 * Admin-panel-only enhancement. Polls Admin\MonitorController::summary()
 * (/api/v1/admin/monitor/summary) and re-renders the worker-liveness table
 * in app/Views/admin/dashboard.php in place, so it stays live without a
 * manual reload. No-ops on any page without a #monitor-body element.
 */
import { o9Fetch } from './core.js';

const REFRESH_MS = 10_000;

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}

function renderRows(samples) {
    const tbody = document.getElementById('monitor-body');
    const table = document.getElementById('monitor-table');
    const empty = document.getElementById('monitor-empty');
    if (!tbody) {
        return;
    }

    if (samples.length === 0) {
        table?.setAttribute('hidden', '');
        if (empty) {
            empty.hidden = false;
        }
        return;
    }

    table?.removeAttribute('hidden');
    if (empty) {
        empty.hidden = true;
    }
    tbody.innerHTML = samples
        .map((s) => `<tr><td>${escapeHtml(s.name)}</td><td>${escapeHtml(s.labels?.worker ?? '—')}</td><td>${escapeHtml(s.value)}</td></tr>`)
        .join('');
}

async function refresh() {
    try {
        const data = await o9Fetch('/api/v1/admin/monitor/summary');
        renderRows(data.samples ?? []);
    } catch {
        // Transient failure — the next tick retries; don't spam the console.
    }
}

if (document.getElementById('monitor-body')) {
    setInterval(refresh, REFRESH_MS);
}
