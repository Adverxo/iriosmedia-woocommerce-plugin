// Adds up what every test page covered (build/js-coverage/*.json), writes the HTML report and fails below MIN.
import { readdirSync, readFileSync } from "node:fs";
// These are CommonJS packages: imported through their default export
import coverage from "istanbul-lib-coverage";
import report from "istanbul-lib-report";
import reports from "istanbul-reports";

const { createCoverageMap } = coverage;
const { createContext } = report;
const { create } = reports;

const dir = new URL("../irios-woocommerce/build/js-coverage/", import.meta.url);
const map = createCoverageMap({});
let pages = 0;
for (const file of readdirSync(dir).filter((f) => f.endsWith(".json"))) {
  for (const page of JSON.parse(readFileSync(new URL(file, dir), "utf8"))) {
    map.merge(page);
    pages++;
  }
}

const context = createContext({ coverageMap: map, dir: new URL("html/", dir).pathname });
create("html").execute(context);

const summary = map.getCoverageSummary();
const lines = summary.lines.pct;
console.log(`Statements : ${summary.statements.pct}%  Branches: ${summary.branches.pct}%  ` +
  `Functions: ${summary.functions.pct}%  Lines: ${lines}%  (${pages} test pages)`);
const min = Number(process.env.MIN || 50);
console.log(`==> Lines: ${lines}% (minimum ${min}%), report in irios-woocommerce/build/js-coverage/html/index.html`);
if (lines < min) {
  console.error("ERROR: coverage below the minimum");
  process.exit(1);
}
