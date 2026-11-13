// Tests for the store JS (npm test). Each test mounts its own page with jsdom (tests/js/harness.mjs).
//
// The harness runs assets/js/irios-woocommerce.js with window.eval, so that it is tested exactly as it is published,
// and no coverage tool sees inside an eval. bin/coverage-js.sh instruments it first and adds up what each page
// covered; bin/coverage.sh does the PHP.
export default {
  test: {
    include: ["irios-woocommerce/tests/js/**/*.test.mjs"],
    environment: "node",
  },
};
