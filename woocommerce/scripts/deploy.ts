/**
 * Deploy script for Zeno Crypto Payment Gateway WooCommerce plugin.
 * Validates the plugin and publishes to WordPress.org SVN and an external directory.
 */

import { execSync } from "node:child_process";
import { createInterface } from "node:readline";
import {
  existsSync,
  readFileSync,
  readdirSync,
  statSync,
  unlinkSync,
} from "node:fs";
import { resolve, dirname, join, extname } from "node:path";
import { fileURLToPath } from "node:url";
import { Command } from "commander";
import colors from "colors";

// ── Constants ────────────────────────────────────────────────────────────────

const PLUGIN_SLUG = "zeno-crypto-payment-gateway";
const PLUGIN_FILE = `${PLUGIN_SLUG}.php`;
const DEFAULT_ENDPOINT = "https://api.zenobank.io";
const TEXT_EXTENSIONS = new Set([
  ".php",
  ".js",
  ".txt",
  ".css",
  ".json",
  ".html",
]);
const JUNK_PATTERNS = [".DS_Store", "Thumbs.db"];

// Paths derived from script location (ESM)
const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = resolve(__dirname, "..");
const SVN_DIR = resolve(ROOT_DIR, "svn-plugin");
const PLUGIN_DIR = resolve(SVN_DIR, "trunk");
const MAIN_PHP = resolve(PLUGIN_DIR, PLUGIN_FILE);
const README = resolve(PLUGIN_DIR, "readme.txt");

// ── Helpers ──────────────────────────────────────────────────────────────────

function info(msg: string) {
  console.log(colors.cyan(`  ℹ  ${msg}`));
}
function success(msg: string) {
  console.log(colors.green(`  ✔  ${msg}`));
}
function warn(msg: string) {
  console.log(colors.yellow(`  ⚠  ${msg}`));
}
function fatal(msg: string): never {
  console.error(colors.red(`  ✖  ${msg}`));
  process.exit(1);
}

function run(cmd: string, cwd?: string) {
  execSync(cmd, { stdio: "inherit", cwd: cwd ?? ROOT_DIR });
}

let rl: ReturnType<typeof createInterface> | null = null;

function getRL() {
  if (!rl) {
    rl = createInterface({ input: process.stdin, output: process.stdout });
  }
  return rl;
}

function closeRL() {
  rl?.close();
  rl = null;
}

async function confirm(question: string): Promise<boolean> {
  return new Promise((res) => {
    getRL().question(`${colors.yellow("?")} ${question} [y/N] `, (answer) => {
      res(answer.trim().toLowerCase() === "y");
    });
  });
}

async function confirmOrExit(question: string): Promise<void> {
  if (!(await confirm(question))) {
    fatal("Aborted by user.");
  }
}

/** Recursively walk a directory, skipping hidden directories. */
function walk(dir: string): string[] {
  const results: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!entry.name.startsWith(".")) results.push(...walk(full));
    } else {
      results.push(full);
    }
  }
  return results;
}

// ── Parse CLI Args (commander) ───────────────────────────────────────────────

interface Options {
  force: boolean;
  noClean: boolean;
  endpoint: string;
}

function parseArgs(): Options {
  const program = new Command();

  program
    .name("deploy")
    .description("Validate and deploy the Zeno Crypto Payment Gateway plugin")
    .option("-y, --force", "Skip interactive confirmations", false)
    .option("--no-clean", "Skip cleaning .DS_Store / Thumbs.db files")
    .option("--endpoint <url>", "Expected API endpoint", DEFAULT_ENDPOINT)
    .parse();

  const opts = program.opts<{
    force: boolean;
    clean: boolean;
    endpoint: string;
  }>();

  return {
    force: opts.force,
    noClean: !opts.clean,
    endpoint: opts.endpoint,
  };
}

// ── Deploy Steps ─────────────────────────────────────────────────────────────

/** Step 1: Verify plugin directory, main PHP file, and readme.txt exist. */
function step1_verifyFiles() {
  info("Verifying plugin structure...");

  if (!existsSync(PLUGIN_DIR) || !statSync(PLUGIN_DIR).isDirectory()) {
    fatal(`Plugin directory not found: ${PLUGIN_DIR}`);
  }
  if (!existsSync(MAIN_PHP)) {
    fatal(`Main plugin file not found: ${MAIN_PHP}`);
  }
  if (!existsSync(README)) {
    fatal(`readme.txt not found: ${README}`);
  }

  success("Plugin directory, main PHP file, and readme.txt all present.");
}

/** Step 2: Clean .DS_Store, ._*, and Thumbs.db from plugin dir. */
function step2_clean() {
  info("Cleaning junk files from plugin directory...");
  let removed = 0;

  for (const file of walk(PLUGIN_DIR)) {
    const name = file.split("/").pop()!;
    if (JUNK_PATTERNS.includes(name) || name.startsWith("._")) {
      unlinkSync(file);
      warn(`Removed: ${file}`);
      removed++;
    }
  }

  success(
    removed > 0 ? `Removed ${removed} junk file(s).` : "No junk files found."
  );
}

/** Step 3: Scan plugin text files for localhost references. */
function step3_localhostCheck() {
  info("Scanning for localhost references...");
  const hits: string[] = [];

  for (const file of walk(PLUGIN_DIR)) {
    const ext = extname(file).toLowerCase();
    if (!TEXT_EXTENSIONS.has(ext)) continue;

    const content = readFileSync(file, "utf-8");
    const lines = content.split("\n");
    for (let i = 0; i < lines.length; i++) {
      if (/localhost/i.test(lines[i])) {
        hits.push(`  ${file}:${i + 1}: ${lines[i].trim()}`);
      }
    }
  }

  if (hits.length > 0) {
    console.log(colors.red("\nLocalhost references found:"));
    hits.forEach((h) => console.log(colors.red(h)));
    fatal("Remove all localhost references before deploying.");
  }

  success("No localhost references found.");
}

/** Step 4: Extract ZCPG_API_ENDPOINT from PHP and verify it matches expected. */
function step4_verifyEndpoint(expectedEndpoint: string) {
  info("Verifying API endpoint...");

  const content = readFileSync(MAIN_PHP, "utf-8");
  const lines = content.split("\n");

  // Line 20 (0-indexed: 19)
  const endpointLine = lines[19] ?? "";
  const match = endpointLine.match(
    /define\(\s*'ZCPG_API_ENDPOINT'\s*,\s*'([^']+)'/
  );

  if (!match) {
    fatal(`Could not extract ZCPG_API_ENDPOINT from ${PLUGIN_FILE}:20`);
  }

  const actual = match[1];
  if (actual !== expectedEndpoint) {
    fatal(
      `API endpoint mismatch!\n` +
        `  Expected: ${expectedEndpoint}\n` +
        `  Found:    ${actual}\n` +
        `  File:     ${PLUGIN_FILE}:20`
    );
  }

  success(`API endpoint verified: ${actual}`);
}

/** Step 5: Interactive confirmations (skipped with --force). */
async function step5_interactiveChecks() {
  info("Interactive checks...");
  await confirmOrExit(
    "Have you run the WordPress Plugin Checker and resolved all issues?"
  );
  await confirmOrExit(
    "Have you updated the readme.txt description and changelog?"
  );
  success("Interactive checks passed.");
}

/** Step 6: Verify version consistency across readme.txt and main PHP file. Returns the version. */
function step6_versionConsistency(): string {
  info("Checking version consistency...");

  const readmeContent = readFileSync(README, "utf-8");
  const phpContent = readFileSync(MAIN_PHP, "utf-8");
  const readmeLines = readmeContent.split("\n");
  const phpLines = phpContent.split("\n");

  // Stable tag from readme.txt line 7 (0-indexed: 6)
  const stableTagLine = readmeLines[6] ?? "";
  const stableMatch = stableTagLine.match(/Stable tag:\s*(.+)/);
  if (!stableMatch) fatal("Could not extract Stable tag from readme.txt:7");
  const stableTag = stableMatch[1].trim();

  // Version from PHP header line 6 (0-indexed: 5)
  const versionLine = phpLines[5] ?? "";
  const versionMatch = versionLine.match(/Version:\s*(.+)/);
  if (!versionMatch) fatal(`Could not extract Version from ${PLUGIN_FILE}:6`);
  const headerVersion = versionMatch[1].trim();

  // ZCPG_VERSION from PHP line 19 (0-indexed: 18)
  const zcpgVersionLine = phpLines[18] ?? "";
  const zcpgMatch = zcpgVersionLine.match(
    /define\(\s*'ZCPG_VERSION'\s*,\s*'([^']+)'/
  );
  if (!zcpgMatch)
    fatal(`Could not extract ZCPG_VERSION from ${PLUGIN_FILE}:19`);
  const zcpgVersion = zcpgMatch[1];

  console.log(`  Stable tag (readme.txt:7):     ${stableTag}`);
  console.log(`  Header Version (php:6):         ${headerVersion}`);
  console.log(`  ZCPG_VERSION (php:19):          ${zcpgVersion}`);

  if (stableTag !== headerVersion || headerVersion !== zcpgVersion) {
    fatal(
      `Version mismatch detected!\n` +
        `  Stable tag:      ${stableTag}\n` +
        `  Header Version:  ${headerVersion}\n` +
        `  ZCPG_VERSION:    ${zcpgVersion}\n` +
        `All three must match.`
    );
  }

  success(`Version ${stableTag} is consistent across all files.`);
  return stableTag;
}

/** Step 7: Stage changes, commit trunk, and create a version tag in SVN. */
async function step7_svnPublish(version: string, force: boolean) {
  if (!existsSync(PLUGIN_DIR)) {
    warn(`SVN trunk not found: ${PLUGIN_DIR}`);
    warn("Run 'svn checkout' first. Skipping SVN publish.");
    return;
  }

  // Stage new/changed files
  info("Staging SVN changes...");
  run("svn add --force .", PLUGIN_DIR);

  // Remove files deleted from trunk
  try {
    const status = execSync("svn status", {
      cwd: PLUGIN_DIR,
      encoding: "utf-8",
    });
    const missing = status
      .split("\n")
      .filter((line) => line.startsWith("!"))
      .map((line) => line.replace(/^!\s+/, "").trim())
      .filter(Boolean);

    for (const file of missing) {
      run(`svn rm "${file}"`, PLUGIN_DIR);
    }
  } catch {
    // No missing files
  }

  // Show pending changes
  run("svn status", SVN_DIR);

  if (!force) {
    const shouldPublish = await confirm(
      `Publish version ${version} to WordPress.org SVN?`
    );
    if (!shouldPublish) {
      info("Skipping SVN publish.");
      return;
    }
  }

  info("Committing trunk...");
  run(`svn commit trunk -m "Deploy version ${version}"`, SVN_DIR);

  info(`Creating tag ${version}...`);
  run(`svn cp trunk "tags/${version}"`, SVN_DIR);

  info(`Committing tag ${version}...`);
  run(`svn commit "tags/${version}" -m "Tag version ${version}"`, SVN_DIR);

  success(`Version ${version} published to WordPress.org SVN.`);
}

// ── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const opts = parseArgs();

  console.log(colors.bold("\n--- Zeno Crypto Payment Gateway — Deploy ---\n"));

  // Step 1: Verify files
  step1_verifyFiles();

  // Step 2: Clean junk files
  if (!opts.noClean) {
    step2_clean();
  } else {
    info("Skipping junk file cleanup (--no-clean).");
  }

  // Step 3: Localhost check
  step3_localhostCheck();

  // Step 4: Verify API endpoint
  step4_verifyEndpoint(opts.endpoint);

  // Step 5: Interactive confirmations
  if (!opts.force) {
    await step5_interactiveChecks();
  } else {
    info("Skipping interactive checks (--force).");
  }

  // Step 6: Version consistency
  const version = step6_versionConsistency();

  // Step 7: SVN publish
  await step7_svnPublish(version, opts.force);

  closeRL();
  console.log(colors.bold(colors.green("\nDeploy complete.\n")));
}

// Graceful Ctrl+C handling
process.on("SIGINT", () => {
  closeRL();
  console.log(colors.yellow("\n\nAborted."));
  process.exit(130);
});

main().catch((err) => {
  closeRL();
  fatal(err instanceof Error ? err.message : String(err));
});
