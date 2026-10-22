// Playwright runs in Docker, on the store's network: "localhost" (the WordPress URL) resolves to the container.
export default {
  testMatch: "*.spec.mjs",
  workers: 1,
  reporter: "line",
  use: { launchOptions: { args: ["--host-resolver-rules=MAP localhost wordpress"] } },
};
