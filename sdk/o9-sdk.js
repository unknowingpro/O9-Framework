/**
 * O9SDK — minimal JS client for an O9-framework API.
 *
 * Talks the {ok, data, error, meta} envelope every endpoint returns and
 * throws an O9ApiError (carrying the canonical error.code) on failure, so
 * callers can branch on the code instead of parsing messages.
 *
 * Usage:
 *   const api = new O9SDK({ baseUrl: 'https://example.com/api/v1' });
 *   api.setToken(accessToken);
 *   const { status } = await api.get('/health');
 *   try {
 *     await api.post('/push/subscribe', { endpoint, keys });
 *   } catch (e) {
 *     if (e instanceof O9ApiError && e.code === 'unauthorized') { ... }
 *   }
 */
export class O9ApiError extends Error {
  constructor(code, message, status, details) {
    super(message);
    this.name = 'O9ApiError';
    this.code = code;
    this.status = status;
    this.details = details ?? null;
  }
}

export class O9SDK {
  /** @param {{baseUrl: string, token?: string, timeoutMs?: number}} opts */
  constructor(opts) {
    this.baseUrl = opts.baseUrl.replace(/\/+$/, '');
    this.token = opts.token ?? null;
    this.timeoutMs = opts.timeoutMs ?? 15000;
  }

  setToken(token) {
    this.token = token;
  }

  get(path, query = {}) {
    return this.request('GET', path, query);
  }

  post(path, body = {}) {
    return this.request('POST', path, null, body);
  }

  put(path, body = {}) {
    return this.request('PUT', path, null, body);
  }

  delete(path) {
    return this.request('DELETE', path);
  }

  /**
   * @param {string} method
   * @param {string} path
   * @param {Record<string, string>|null} query
   * @param {unknown} [body]
   */
  async request(method, path, query = null, body = undefined) {
    let url = this.baseUrl + (path.startsWith('/') ? path : `/${path}`);
    if (query && Object.keys(query).length > 0) {
      url += '?' + new URLSearchParams(query).toString();
    }

    const headers = { Accept: 'application/json' };
    if (this.token) {
      headers.Authorization = `Bearer ${this.token}`;
    }
    let payload;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    let res;
    try {
      res = await fetch(url, { method, headers, body: payload, signal: controller.signal });
    } finally {
      clearTimeout(timer);
    }

    const envelope = await res.json().catch(() => null);
    if (!envelope || typeof envelope.ok !== 'boolean') {
      throw new O9ApiError('bad_response', 'The server returned a non-envelope response.', res.status);
    }
    if (!envelope.ok) {
      const err = envelope.error ?? {};
      throw new O9ApiError(err.code ?? 'unknown_error', err.message ?? 'Request failed.', res.status, err.details ?? null);
    }
    return envelope.data;
  }
}
