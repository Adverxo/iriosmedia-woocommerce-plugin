// Tests for the store JS (npm test). Each test mounts its own page with jsdom (tests/js/harness.mjs).
//
// There is no coverage for this JS on purpose: the harness runs assets/js/irios-woocommerce.js with window.eval, so
// that it is tested exactly as it is published, and no coverage tool sees inside an eval. Measuring it means first
// splitting that file into ES modules the tests can import. The PHP coverage is bin/coverage.sh.
export default {
  test: {
    include: ["irios-woocommerce/tests/js/**/*.test.mjs"],
    environment: "node",
  },
};
