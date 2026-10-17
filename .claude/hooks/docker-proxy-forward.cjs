// Used by session-start.sh in Claude Code cloud sessions only.
// The session's HTTPS proxy listens on 127.0.0.1, which Docker containers
// can't reach. Forward connections from the Docker bridge gateway to it so
// containers (wp-env) can use it as their proxy.
//
// Usage: node docker-proxy-forward.cjs <listen-host> <listen-port> <proxy-url>
const net = require('net');

const [listenHost, listenPort, proxyUrl] = process.argv.slice(2);
const proxy = new URL(proxyUrl);

net
  .createServer((client) => {
    const upstream = net.connect(Number(proxy.port), proxy.hostname);
    client.pipe(upstream).pipe(client);
    client.on('error', () => upstream.destroy());
    upstream.on('error', () => client.destroy());
  })
  .listen(Number(listenPort), listenHost);
