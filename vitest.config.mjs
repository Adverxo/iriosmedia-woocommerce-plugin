// Tests for the store JS (npm test). Each test mounts its own page with jsdom (tests/js/harness.mjs).
export default {
  test: {
    include: ["irios-woocommerce/tests/js/**/*.test.mjs"],
    environment: "node",
  },
};
