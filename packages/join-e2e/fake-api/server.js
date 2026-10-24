// Fake third-party APIs for the E2E suite.
//
// Inside the E2E Docker network the hostnames of the services the plugin talks
// to (api.stripe.com, actionnetwork.org, ...) resolve to this container, which
// answers over HTTPS with a test certificate the WordPress containers trust
// (see docker-compose.yml). The plugin's real code and SDKs run unchanged.
//
// Every request is recorded. Tests read and clear them, and can override
// responses, through the control API on port 8080 (localhost:8890 from the
// host); see tests/settings/fake-api.ts.
//
// No dependencies: Node's standard library only.

const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');
const { URL } = require('url');

let requests = [];
let overrides = [];
let stripe = freshStripe();

function freshStripe() {
  return { counter: 0, objects: { products: [], prices: [], customers: [], subscriptions: [] } };
}

function parseBody(raw, contentType) {
  if (!raw) return null;
  if ((contentType || '').includes('application/json')) {
    try {
      return JSON.parse(raw);
    } catch {
      return raw;
    }
  }
  if ((contentType || '').includes('application/x-www-form-urlencoded')) {
    return parseFormEncoded(raw);
  }
  return raw;
}

/** Stripe-style form encoding, with nested keys like metadata[membership_plan]. */
function parseFormEncoded(raw) {
  const result = {};
  for (const [key, value] of new URLSearchParams(raw)) {
    const parts = key.replace(/\]/g, '').split('[');
    let target = result;
    parts.forEach((part, index) => {
      if (index === parts.length - 1) {
        target[part] = value;
      } else {
        target[part] = target[part] || {};
        target = target[part];
      }
    });
  }
  return result;
}

/** A tiny in-memory Stripe: enough for the plugin's product and price set-up. */
function fakeStripe(method, url, body) {
  const match = url.pathname.match(/^\/v1\/([a-z_]+)(?:\/([^/]+))?$/);
  if (!match) return null;
  const [, type, idOrAction] = match;
  const store = stripe.objects[type];
  if (!store) return null;
  const prefix = { products: 'prod', prices: 'price', customers: 'cus', subscriptions: 'sub' }[type];

  if (method === 'GET' && idOrAction === 'search') {
    const query = url.searchParams.get('query') || '';
    // e.g. active:'true' AND metadata['membership_plan']:'standard_monthly_gbp'
    const conditions = [...query.matchAll(/(metadata\['([^']+)'\]|[a-z_]+):'([^']*)'/g)].map(
      ([, field, metadataKey, value]) => ({ field, metadataKey, value }),
    );
    const data = store.filter((object) =>
      conditions.every(({ field, metadataKey, value }) => {
        if (metadataKey) return (object.metadata || {})[metadataKey] === value;
        if (field === 'active') return String(object.active) === value;
        if (field === 'type') return (object.recurring ? 'recurring' : 'one_time') === value;
        return String(object[field]) === value;
      }),
    );
    return { status: 200, body: { object: 'search_result', url: url.pathname, has_more: false, data } };
  }
  if (method === 'POST' && !idOrAction) {
    stripe.counter += 1;
    const object = { id: `${prefix}_e2e_${stripe.counter}`, object: type.replace(/s$/, ''), active: true, livemode: false, ...body };
    if (body && body.unit_amount) object.unit_amount = Number(body.unit_amount);
    store.push(object);
    return { status: 200, body: object };
  }
  if (method === 'GET' && idOrAction) {
    const object = store.find((candidate) => candidate.id === idOrAction);
    return object ? { status: 200, body: object } : { status: 404, body: { error: { type: 'invalid_request_error', message: 'No such object' } } };
  }
  return null;
}

function respond(host, method, url, body) {
  const override = overrides.find(
    (rule) =>
      (!rule.host || rule.host === host) &&
      (!rule.method || rule.method === method) &&
      (!rule.pathPattern || rule.pathPattern.test(url.pathname)),
  );
  if (override) return { status: override.status || 200, body: override.body ?? {} };
  if (host === 'api.stripe.com') {
    const result = fakeStripe(method, url, body);
    if (result) return result;
  }
  return { status: 200, body: {} };
}

function handleService(request, response) {
  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', () => {
    const host = (request.headers.host || '').split(':')[0];
    const url = new URL(request.url, `https://${host}`);
    const raw = Buffer.concat(chunks).toString('utf8');
    const body = parseBody(raw, request.headers['content-type']);
    requests.push({
      time: new Date().toISOString(),
      host,
      method: request.method,
      path: url.pathname,
      query: Object.fromEntries(url.searchParams),
      headers: request.headers,
      body,
    });
    const result = respond(host, request.method, url, body);
    response.writeHead(result.status, { 'Content-Type': 'application/json' });
    response.end(typeof result.body === 'string' ? result.body : JSON.stringify(result.body));
  });
}

function handleControl(request, response) {
  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', () => {
    const send = (status, body) => {
      response.writeHead(status, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify(body));
    };
    // A bad request from a test gets a 400, rather than crashing the fake for
    // every test after it.
    const readJson = () => {
      try {
        return JSON.parse(Buffer.concat(chunks).toString('utf8'));
      } catch (error) {
        send(400, { error: `invalid JSON: ${error.message}` });
        return undefined;
      }
    };
    if (request.method === 'GET' && request.url === '/requests') return send(200, requests);
    if (request.method === 'POST' && request.url === '/reset') {
      requests = [];
      overrides = [];
      stripe = freshStripe();
      return send(200, { ok: true });
    }
    if (request.method === 'POST' && request.url === '/overrides') {
      const rule = readJson();
      if (rule === undefined) return;
      try {
        rule.pathPattern = rule.path ? new RegExp(rule.path) : null;
      } catch (error) {
        return send(400, { error: `invalid path pattern: ${error.message}` });
      }
      overrides.unshift(rule);
      return send(200, { ok: true });
    }
    if (request.method === 'GET' && request.url === '/health') return send(200, { ok: true });
    send(404, { error: 'unknown control endpoint' });
  });
}

const certs = path.join(__dirname, 'certs');
https
  .createServer({ key: fs.readFileSync(path.join(certs, 'server.key')), cert: fs.readFileSync(path.join(certs, 'server.crt')) }, handleService)
  .listen(443);
http.createServer(handleService).listen(80);
http.createServer(handleControl).listen(8080);
console.log('Fake APIs listening on 443 and 80; control API on 8080');
