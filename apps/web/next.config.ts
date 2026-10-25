import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allows the production Docker image to run `node server.js` from
  // `.next/standalone` (see apps/web/Dockerfile).
  output: "standalone",
};

export default nextConfig;
