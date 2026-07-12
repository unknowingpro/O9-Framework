/**
 * Shared utilities loaded on every page (see asset_module() in
 * app/Core/helpers.php). Plain ES module, no bundler, no dependencies —
 * local-first per the framework's asset convention.
 */

// Auto-dismiss flash messages (Core\Session::takeFlash(), rendered by both layouts).
export function initFlashes(root = document) {
    root.querySelectorAll('.flash').forEach((el) => {
        setTimeout(() => el.remove(), 6000);
    });
}

/**
 * Fetch wrapper for the framework's {ok,data,error,meta} response envelope
 * (Core\Response — see sdk/o9-sdk.js for the same contract server-side
 * clients use). Resolves to `data` on success; throws on a transport error,
 * a non-JSON body, or an envelope with ok !== true.
 */
export async function o9Fetch(path, options = {}) {
    const res = await fetch(path, {
        ...options,
        headers: { Accept: 'application/json', ...(options.headers || {}) },
    });

    let body;
    try {
        body = await res.json();
    } catch {
        throw new Error(`Request to ${path} did not return JSON (status ${res.status})`);
    }

    if (!res.ok || !body || body.ok !== true) {
        const message = body && body.error && body.error.message ? body.error.message : `Request to ${path} failed`;
        throw new Error(message);
    }

    return body.data;
}

document.addEventListener('DOMContentLoaded', () => initFlashes());
