import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allows the production Docker image to run `node server.js` from
  // `.next/standalone` (see apps/web/Dockerfile).
  output: "standalone",

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
