// Loaded via NODE_OPTIONS in Claude Code cloud sessions only (see session-start.sh).
// The cloud container has no DNS: all egress goes through an HTTPS proxy. wp-env
// decides whether it is online with a raw DNS lookup of WordPress.org, and when
// that fails it skips downloading WordPress. Answer that one lookup; the real
// requests still go through the proxy (NODE_USE_ENV_PROXY=1).
const dns = require('dns');

const resolve = dns.promises.resolve;
dns.promises.resolve = (host, ...rest) =>
  /^wordpress\.org$/i.test(host)
    ? Promise.resolve(['127.0.0.1'])
    : resolve(host, ...rest);
