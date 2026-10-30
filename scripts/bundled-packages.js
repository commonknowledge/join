#!/usr/bin/env node
// Prints how many modules of each named package end up in the production
// join-flow bundle. 0 means the package is build tooling only and never ships
// to users. Usage: scripts/bundled-packages.js <package> [<package>...]
const { execFileSync } = require("child_process");
const fs = require("fs");
const os = require("os");
const path = require("path");

const packages = process.argv.slice(2);
if (packages.length === 0) {
  console.error("Usage: scripts/bundled-packages.js <package> [<package>...]");
  process.exit(2);
}

const joinFlow = path.resolve(__dirname, "../packages/join-flow");
const statsFile = path.join(
  fs.mkdtempSync(path.join(os.tmpdir(), "join-flow-stats-")),
  "stats.json"
);
execFileSync(
  path.join(joinFlow, "node_modules/.bin/webpack"),
  ["--config", "./webpack/production.js", `--json=${statsFile}`],
  { cwd: joinFlow, stdio: ["ignore", "ignore", "inherit"] }
);
const stats = JSON.parse(fs.readFileSync(statsFile, "utf8"));

// Concatenated modules hold the modules they were built from in `modules`.
const flatten = (modules) =>
  modules.flatMap((m) => [m, ...flatten(m.modules || [])]);
const modules = flatten(stats.modules);

for (const name of packages) {
  const count = modules.filter((m) =>
    (m.name || "").includes(`node_modules/${name}/`)
  ).length;
  console.log(`${name}\t${count}`);
}
