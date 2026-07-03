#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const project = process.env.BENCHMARK_COMPOSE_PROJECT ?? 'telegram-bot-dogfood';
const webPort = process.env.WEB_PORT ?? '18080';
const baseUrl = (process.env.BASE_URL ?? `http://127.0.0.1:${webPort}`).replace(/\/$/, '');
const outputDir = resolve(rootDir, process.env.BENCHMARK_OUTPUT_DIR ?? `artifacts/production-benchmark/${new Date().toISOString().replaceAll(':', '-')}`);
const adminEmail = process.env.DOGFOOD_ADMIN_EMAIL ?? 'benchmark-owner@example.test';
const adminPassword = process.env.DOGFOOD_ADMIN_PASSWORD ?? 'BenchmarkPassword123!';
const webhookSecret = process.env.TELEGRAM_WEBHOOK_SECRET ?? 'benchmark-secret';
const runStartedAt = new Date().toISOString();

const env = {
  ...process.env,
  APP_ENV: process.env.APP_ENV ?? 'production',
  APP_DEBUG: process.env.APP_DEBUG ?? 'false',
  APP_URL: process.env.APP_URL ?? 'https://benchmark.localhost.test',
  APP_KEY: process.env.APP_KEY ?? 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
  POSTGRES_PASSWORD: process.env.POSTGRES_PASSWORD ?? 'benchmark_postgres_password',
  TELEGRAM_BOT_TOKEN: process.env.TELEGRAM_BOT_TOKEN ?? 'benchmark-token',
  TELEGRAM_WEBHOOK_SECRET: webhookSecret,
  TELEGRAM_ADMIN_CHAT_ID: process.env.TELEGRAM_ADMIN_CHAT_ID ?? '700000001',
  TELEGRAM_ADMIN_USER_ID: process.env.TELEGRAM_ADMIN_USER_ID ?? '700000001',
  TELEGRAM_PAYMENT_MODE: process.env.TELEGRAM_PAYMENT_MODE ?? 'manual',
  DOGFOOD_TIMING_ENABLED: 'true',
  DOGFOOD_TIMING_LOG_CHANNEL: process.env.DOGFOOD_TIMING_LOG_CHANNEL ?? 'stderr',
  DOGFOOD_FAKE_TELEGRAM_OUTBOUND: process.env.DOGFOOD_FAKE_TELEGRAM_OUTBOUND ?? 'true',
  DOGFOOD_ADMIN_EMAIL: adminEmail,
  DOGFOOD_ADMIN_PASSWORD: adminPassword,
  SESSION_DRIVER: process.env.SESSION_DRIVER ?? 'cookie',
  WEB_PORT: webPort,
};

const budgets = {
  httpP95Ms: Number(process.env.BENCHMARK_HTTP_P95_MS ?? 1000),
  storefrontConcurrentP95Ms: Number(process.env.BENCHMARK_STOREFRONT_CONCURRENT_P95_MS ?? 500),
  adminLoginP95Ms: Number(process.env.BENCHMARK_ADMIN_LOGIN_P95_MS ?? 500),
  webhookAckP95Ms: Number(process.env.BENCHMARK_WEBHOOK_ACK_P95_MS ?? 500),
  handlerDrainMaxMs: Number(process.env.BENCHMARK_HANDLER_DRAIN_MAX_MS ?? 15000),
  appMemoryMaxMiB: Number(process.env.BENCHMARK_APP_MEMORY_MAX_MIB ?? 768),
};

const samples = [];
const commands = [];
const failures = [];
const expectedStatuses = new Map([
  ['health /up', [200]],
  ['storefront /', [200]],
  ['storefront warmup /', [200]],
  ['storefront concurrent /', [200]],
  ['admin login page', [200]],
  ['admin login page concurrent /admin/login', [200]],
  ['admin valid login', [302]],
  ['admin dashboard after login', [200]],
  ['webhook invalid secret', [403]],
  ['webhook unsupported update', [422]],
  ['webhook valid /start', [200]],
  ['webhook duplicate first', [200]],
  ['webhook duplicate second', [200]],
  ['handler product happy path', [200]],
  ['handler quantity happy path', [200]],
  ['handler checkout happy path', [200]],
  ['handler status command path', [200]],
]);

function run(command, args, options = {}) {
  return execFileSync(command, args, {
    cwd: rootDir,
    env,
    encoding: 'utf8',
    stdio: options.inherit ? 'inherit' : ['ignore', 'pipe', 'pipe'],
  });
}

function compose(args, options = {}) {
  return run('docker', ['compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.benchmark.yml', '-p', project, ...args], options);
}

function timedCommand(name, args, service = 'app') {
  const started = performance.now();
  const result = spawnSync('docker', ['compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.benchmark.yml', '-p', project, 'exec', '-T', service, ...args], {
    cwd: rootDir,
    env,
    encoding: 'utf8',
  });
  const ms = performance.now() - started;
  commands.push({
    name,
    service,
    exit_code: result.status,
    ms: round(ms),
    stdout: result.stdout.trim().slice(0, 2000),
    stderr: result.stderr.trim().slice(0, 2000),
  });
  if (result.status !== 0) {
    failures.push(`${name} exited ${result.status}`);
  }
}

async function waitForSchedulerHealth() {
  const name = 'ops:health --scheduler-heartbeat=180';
  const args = ['php', 'artisan', 'ops:health', '--scheduler-heartbeat=180'];
  const started = performance.now();
  const deadline = Date.now() + Number(process.env.BENCHMARK_SCHEDULER_HEALTH_TIMEOUT_MS ?? 120000);
  let result;

  do {
    result = spawnSync('docker', ['compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.benchmark.yml', '-p', project, 'exec', '-T', 'scheduler', ...args], {
      cwd: rootDir,
      env,
      encoding: 'utf8',
    });
    if (result.status === 0) {
      break;
    }
    await new Promise((resolve) => setTimeout(resolve, 5000));
  } while (Date.now() < deadline);

  commands.push({
    name,
    service: 'scheduler',
    exit_code: result.status,
    ms: round(performance.now() - started),
    stdout: result.stdout.trim().slice(0, 2000),
    stderr: result.stderr.trim().slice(0, 2000),
  });

  if (result.status !== 0) {
    failures.push(`${name} exited ${result.status}`);
  }
}

async function timedFetch(name, method, path, options = {}) {
  const started = performance.now();
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: options.headers,
    body: options.body,
    redirect: options.redirect ?? 'follow',
  });
  const body = await response.text();
  const sample = {
    name,
    method,
    path,
    status: response.status,
    ms: round(performance.now() - started),
  };
  samples.push(sample);
  assertExpectedStatus(sample);

  return { response, body, sample };
}

async function timedConcurrentFetch(name, method, path, total, concurrency, options = {}) {
  let next = 0;
  const workers = Array.from({ length: concurrency }, async () => {
    while (next < total) {
      next++;
      await timedFetch(name, method, path, options);
    }
  });

  await Promise.all(workers);
}

function assertExpectedStatus(sample) {
  const expected = expectedStatuses.get(sample.name);

  if (expected && ! expected.includes(sample.status)) {
    failures.push(`${sample.name} returned HTTP ${sample.status}; expected ${expected.join(' or ')}`);
  }
}

function percentile(values, p) {
  if (values.length === 0) {
    return null;
  }
  const sorted = [...values].sort((a, b) => a - b);
  return round(sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)]);
}

function summarize(filter) {
  const selected = samples.filter(filter);
  const values = selected.map((sample) => sample.ms);
  const statusCounts = {};
  for (const sample of selected) {
    statusCounts[sample.status] = (statusCounts[sample.status] ?? 0) + 1;
  }

  return {
    count: selected.length,
    status_counts: statusCounts,
    p50_ms: percentile(values, 50),
    p95_ms: percentile(values, 95),
    max_ms: values.length === 0 ? null : round(Math.max(...values)),
  };
}

function round(value) {
  return Math.round(value * 1000) / 1000;
}

function setCookies(headers) {
  if (typeof headers.getSetCookie === 'function') {
    return headers.getSetCookie();
  }

  const header = headers.get('set-cookie');

  return header ? [header] : [];
}

async function waitForHealth() {
  const deadline = Date.now() + Number(process.env.BENCHMARK_HEALTH_TIMEOUT_MS ?? 180000);
  let lastError = '';
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/up`);
      if (response.ok) {
        return;
      }
      lastError = `HTTP ${response.status}`;
    } catch (error) {
      lastError = error.message;
    }
    await new Promise((resolve) => setTimeout(resolve, 3000));
  }
  throw new Error(`Timed out waiting for ${baseUrl}/up: ${lastError}`);
}

function state() {
  return JSON.parse(compose(['exec', '-T', 'app', 'php', 'scripts/benchmark-production-state.php']));
}

async function waitForQueueEmpty() {
  const started = performance.now();
  const deadline = Date.now() + budgets.handlerDrainMaxMs;
  let current = state();
  while (Date.now() < deadline) {
    if (current.queue_depth === 0) {
      return { ms: round(performance.now() - started), state: current };
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
    current = state();
  }

  return { ms: round(performance.now() - started), state: current, timed_out: true };
}

function updatePayload(updateId, text, fixtures) {
  return {
    update_id: updateId,
    message: {
      message_id: updateId,
      from: { id: fixtures.telegram_user_id, is_bot: false, first_name: 'Benchmark', username: 'benchmark_dogfood' },
      chat: { id: fixtures.telegram_chat_id, type: 'private' },
      date: Math.floor(Date.now() / 1000),
      text,
    },
  };
}

function callbackPayload(updateId, data, fixtures) {
  return {
    update_id: updateId,
    callback_query: {
      id: `benchmark-${updateId}`,
      from: { id: fixtures.telegram_user_id, is_bot: false, first_name: 'Benchmark', username: 'benchmark_dogfood' },
      message: { message_id: updateId, chat: { id: fixtures.telegram_chat_id, type: 'private' }, date: Math.floor(Date.now() / 1000) },
      data,
    },
  };
}

async function postTelegram(name, payload, secret = webhookSecret) {
  return timedFetch(name, 'POST', '/api/webhook/telegram', {
    headers: {
      accept: 'application/json',
      'content-type': 'application/json',
      'x-telegram-bot-api-secret-token': secret,
    },
    body: JSON.stringify(payload),
  });
}

async function adminLogin() {
  const login = await fetch(`${baseUrl}/admin/login`, { redirect: 'manual' });
  const html = await login.text();
  const cookies = setCookies(login.headers).map((cookie) => cookie.split(';')[0]).join('; ');
  const token = html.match(/name="_token"\s+value="([^"]+)"/)?.[1];
  if (!token) {
    samples.push({ name: 'admin valid login', method: 'POST', path: '/admin/login', status: 0, ms: 0 });
    failures.push('admin login CSRF token was not found');
    return;
  }

  const postStarted = performance.now();
  const response = await fetch(`${baseUrl}/admin/login`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'content-type': 'application/x-www-form-urlencoded', cookie: cookies },
    body: new URLSearchParams({ _token: token, email: adminEmail, password: adminPassword }),
  });
  await response.text();

  const sample = { name: 'admin valid login', method: 'POST', path: '/admin/login', status: response.status, ms: round(performance.now() - postStarted) };
  samples.push(sample);
  assertExpectedStatus(sample);

  const location = response.headers.get('location');
  if (response.status >= 300 && response.status < 400 && location) {
    const nextCookies = [...setCookies(login.headers), ...setCookies(response.headers)].map((cookie) => cookie.split(';')[0]).join('; ');
    const dashboardStarted = performance.now();
    const followed = await fetch(new URL(location, baseUrl), { headers: { cookie: nextCookies } });
    await followed.text();
    const dashboardSample = { name: 'admin dashboard after login', method: 'GET', path: '/admin', status: followed.status, ms: round(performance.now() - dashboardStarted) };
    samples.push(dashboardSample);
    assertExpectedStatus(dashboardSample);
  }
}

function containerMemory() {
  const ps = compose(['ps', '--format', 'json']).trim();
  const rows = ps.startsWith('[') ? JSON.parse(ps) : ps.split('\n').filter(Boolean).map((line) => JSON.parse(line));
  const names = rows.map((row) => row.Name).filter(Boolean);
  if (names.length === 0) {
    return [];
  }
  const stats = run('docker', ['stats', '--no-stream', '--format', '{{json .}}', ...names]).trim();
  return stats.split('\n').filter(Boolean).map((line) => {
    const row = JSON.parse(line);
    const used = row.MemUsage.split('/')[0].trim();
    return { name: row.Name, memory: row.MemUsage, memory_used_mib: parseMemoryMiB(used) };
  });
}

function parseMemoryMiB(value) {
  const match = value.match(/^([0-9.]+)\s*([KMGT]?i?)B$/i);
  if (!match) {
    return null;
  }
  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();
  const multipliers = { b: 1 / 1024 / 1024, kb: 1 / 1024, kib: 1 / 1024, mb: 1, mib: 1, gb: 1024, gib: 1024, tb: 1024 * 1024, tib: 1024 * 1024 };
  return round(amount * (multipliers[unit] ?? 1));
}

function markdown(report) {
  const lines = [
    '# Production Benchmark Report',
    '',
    `Date: ${report.started_at}`,
    `Compose project: ${report.compose_project}`,
    `Base URL: ${report.base_url}`,
    '',
    '## Summary',
    '',
    `- Health: ${report.health.ok ? 'passed' : 'failed'}`,
    `- HTTP p95: ${report.summaries.http.p95_ms} ms`,
    `- Storefront concurrency p95: ${report.summaries.storefront_concurrent.p95_ms} ms`,
    `- Admin login p95: ${report.summaries.admin_login.p95_ms} ms`,
    `- Webhook ACK p95: ${report.summaries.webhook_ack.p95_ms} ms`,
    `- Handler drain: ${report.handler_drain.ms} ms`,
    `- Queue depth after run: ${report.final_state.queue_depth}`,
    `- Failed jobs after run: ${report.final_state.failed_jobs}`,
    `- Budget status: ${report.passed ? 'passed' : 'failed'}`,
    '',
    '## Container Memory',
    '',
    '| Container | Memory |',
    '| --- | ---: |',
    ...report.container_memory.map((row) => `| ${row.name} | ${row.memory} |`),
    '',
    '## Samples',
    '',
    '| Scenario | Status | ms |',
    '| --- | ---: | ---: |',
    ...report.samples.map((sample) => `| ${sample.name} | ${sample.status} | ${sample.ms} |`),
    '',
    '## Commands',
    '',
    '| Command | Exit | ms |',
    '| --- | ---: | ---: |',
    ...report.commands.map((command) => `| ${command.name} | ${command.exit_code} | ${command.ms} |`),
  ];

  if (report.failures.length > 0) {
    lines.push('', '## Failures', '', ...report.failures.map((failure) => `- ${failure}`));
  }

  return `${lines.join('\n')}\n`;
}

async function main() {
  mkdirSync(outputDir, { recursive: true });

  console.log(`Benchmark output: ${outputDir}`);
  console.log(`Starting Docker Compose project ${project} on ${baseUrl}`);
  compose(['up', '-d', '--build', '--force-recreate'], { inherit: true });
  await waitForHealth();

  timedCommand('ops:health --db --queue-schema --supervisor', ['php', 'artisan', 'ops:health', '--db', '--queue-schema', '--supervisor']);
  await waitForSchedulerHealth();
  const fixtures = JSON.parse(compose(['exec', '-T', 'app', 'php', 'scripts/benchmark-production-fixtures.php']));

  await timedFetch('health /up', 'GET', '/up');
  await timedFetch('storefront /', 'GET', '/');
  await timedFetch('storefront warmup /', 'GET', '/');
  await timedConcurrentFetch('storefront concurrent /', 'GET', '/', 100, 10);
  await timedFetch('admin login page', 'GET', '/admin/login');
  await timedConcurrentFetch('admin login page concurrent /admin/login', 'GET', '/admin/login', 100, 10);
  await adminLogin();

  const baseUpdateId = Math.floor(Date.now() / 1000) * 1000;
  await postTelegram('webhook invalid secret', updatePayload(baseUpdateId + 1, '/start', fixtures), 'bad-secret');
  await postTelegram('webhook unsupported update', { update_id: baseUpdateId + 2, edited_message: {} });
  await postTelegram('webhook valid /start', updatePayload(baseUpdateId + 3, '/start', fixtures));
  const duplicate = updatePayload(baseUpdateId + 4, '/menu', fixtures);
  await postTelegram('webhook duplicate first', duplicate);
  await postTelegram('webhook duplicate second', duplicate);
  await postTelegram('handler product happy path', callbackPayload(baseUpdateId + 5, `product:${fixtures.product_id}`, fixtures));
  await postTelegram('handler quantity happy path', callbackPayload(baseUpdateId + 6, `qty:${fixtures.product_id}:1`, fixtures));
  await postTelegram('handler checkout happy path', callbackPayload(baseUpdateId + 7, 'checkout', fixtures));
  await postTelegram('handler status command path', updatePayload(baseUpdateId + 8, '/status', fixtures));

  const handlerDrain = await waitForQueueEmpty();

  timedCommand('orders:expire-pending', ['php', 'artisan', 'orders:expire-pending']);
  timedCommand('pakasir:reconcile --chunk=5', ['php', 'artisan', 'pakasir:reconcile', '--chunk=5']);
  timedCommand('ops:check-failed-jobs --max=0', ['php', 'artisan', 'ops:check-failed-jobs', '--max=0']);

  const finalState = state();
  const memory = containerMemory();

  const summaries = {
    http: summarize((sample) => ['health /up', 'storefront /', 'admin login page', 'admin valid login'].includes(sample.name)),
    storefront_concurrent: summarize((sample) => sample.name === 'storefront concurrent /'),
    admin_login: summarize((sample) => ['admin login page', 'admin login page concurrent /admin/login', 'admin valid login'].includes(sample.name)),
    webhook_ack: summarize((sample) => sample.path === '/api/webhook/telegram'),
    all: summarize(() => true),
  };

  if (summaries.http.p95_ms !== null && summaries.http.p95_ms > budgets.httpP95Ms) {
    failures.push(`HTTP p95 ${summaries.http.p95_ms} ms exceeded budget ${budgets.httpP95Ms} ms`);
  }
  if (summaries.storefront_concurrent.p95_ms !== null && summaries.storefront_concurrent.p95_ms > budgets.storefrontConcurrentP95Ms) {
    failures.push(`Storefront concurrency p95 ${summaries.storefront_concurrent.p95_ms} ms exceeded budget ${budgets.storefrontConcurrentP95Ms} ms`);
  }
  if (summaries.admin_login.p95_ms !== null && summaries.admin_login.p95_ms > budgets.adminLoginP95Ms) {
    failures.push(`Admin login p95 ${summaries.admin_login.p95_ms} ms exceeded budget ${budgets.adminLoginP95Ms} ms`);
  }
  if (summaries.webhook_ack.p95_ms !== null && summaries.webhook_ack.p95_ms > budgets.webhookAckP95Ms) {
    failures.push(`Webhook ACK p95 ${summaries.webhook_ack.p95_ms} ms exceeded budget ${budgets.webhookAckP95Ms} ms`);
  }
  if (handlerDrain.timed_out || handlerDrain.ms > budgets.handlerDrainMaxMs) {
    failures.push(`Handler drain ${handlerDrain.ms} ms exceeded budget ${budgets.handlerDrainMaxMs} ms`);
  }
  if (finalState.queue_depth !== 0) {
    failures.push(`Queue depth after run is ${finalState.queue_depth}`);
  }
  if (finalState.failed_jobs !== 0) {
    failures.push(`Failed jobs after run is ${finalState.failed_jobs}`);
  }
  const appMemory = memory.find((row) => row.name.includes('app'));
  if (appMemory?.memory_used_mib !== null && appMemory?.memory_used_mib > budgets.appMemoryMaxMiB) {
    failures.push(`App memory ${appMemory.memory_used_mib} MiB exceeded budget ${budgets.appMemoryMaxMiB} MiB`);
  }

  const report = {
    started_at: runStartedAt,
    compose_project: project,
    base_url: baseUrl,
    budgets,
    synthetic_fixtures: {
      fake_telegram_outbound: env.DOGFOOD_FAKE_TELEGRAM_OUTBOUND === 'true',
      real_external_telegram_messages: false,
      real_pakasir: env.TELEGRAM_PAYMENT_MODE === 'pakasir',
    },
    health: { ok: true },
    summaries,
    samples,
    commands,
    handler_drain: handlerDrain,
    final_state: finalState,
    container_memory: memory,
    failures,
    passed: failures.length === 0,
  };

  writeFileSync(resolve(outputDir, 'report.json'), `${JSON.stringify(report, null, 2)}\n`);
  writeFileSync(resolve(outputDir, 'report.md'), markdown(report));
  console.log(markdown(report));

  if (!report.passed) {
    process.exit(1);
  }
}

main().catch((error) => {
  mkdirSync(outputDir, { recursive: true });
  const report = {
    started_at: runStartedAt,
    compose_project: project,
    base_url: baseUrl,
    health: { ok: false, error: error.message },
    failures: [error.stack ?? error.message],
    passed: false,
  };
  writeFileSync(resolve(outputDir, 'report.json'), `${JSON.stringify(report, null, 2)}\n`);
  console.error(error);
  process.exit(1);
});
