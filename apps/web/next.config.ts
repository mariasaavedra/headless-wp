import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";

import type { NextConfig } from "next";

/**
 * Which commit this build came from.
 *
 * Vercel hands it over as an environment variable; a local build has to ask
 * git. Either can be absent — a tarball with no .git, a CI runner that did
 * not check out history — and "unknown" is a better answer on screen than a
 * build that fails over a caption.
 */
function commit(): string {
  if (process.env.VERCEL_GIT_COMMIT_SHA) {
    return process.env.VERCEL_GIT_COMMIT_SHA.slice(0, 7);
  }

  try {
    return execSync("git rev-parse --short HEAD", {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    }).trim();
  } catch {
    return "unknown";
  }
}

function version(): string {
  try {
    return JSON.parse(readFileSync("./package.json", "utf8")).version ?? "0.0.0";
  } catch {
    return "0.0.0";
  }
}

const nextConfig: NextConfig = {
  output: process.env.VERCEL ? undefined : 'standalone',
  agentRules: false,

  /*
   * Frozen at build time, which is the whole point: these describe the
   * bundle, not the request. Read at runtime they would report whenever the
   * server happened to answer, which on Vercel is a different lambda's cold
   * start each time and tells nobody anything.
   */
  env: {
    BUILD_VERSION: version(),
    BUILD_COMMIT: commit(),
    BUILD_TIME: new Date().toISOString(),
  },
};

export default nextConfig;
