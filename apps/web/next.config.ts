import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allows the production Docker image to run `node server.js` from
  // `.next/standalone` (see apps/web/Dockerfile).
  output: "standalone",

  // Security headers (M5 review): the app serves HTML, JSON and the badge
  // SVG — nothing needs to be embedded cross-origin, so framing and
  // referrer leakage are locked down. The API is same-origin via the
  // /api/v1 proxy, so connect-src stays at default.
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
        ],
      },
      {
        // The README badge is user-facing SVG: keep it scriptless.
        source: "/api/badge/:path*",
        headers: [
          { key: "Content-Security-Policy", value: "default-src 'none'; style-src 'unsafe-inline'" },
          { key: "X-Content-Type-Options", value: "nosniff" },
        ],
      },
    ];
  },

  // Tailwind CSS v4 is compiled by Turbopack instead of PostCSS.
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
