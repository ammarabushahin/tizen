const { chromium } = require('playwright');

const LOGIN_URL = 'https://xanalytica.online/auth/sign-in';
const USERS_URL = 'https://xanalytica.online/dashboard/users';
const TARGET_HOURS = new Set([1, 5, 9, 13, 17, 21]);
const TIME_ZONE = 'Europe/Stockholm';

function stockholmClock() {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: TIME_ZONE,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(new Date());

  const get = (type) => Number(parts.find((p) => p.type === type)?.value ?? 0);
  return { hour: get('hour'), minute: get('minute') };
}

async function main() {
  const force = process.env.FORCE_RUN === '1';
  const { hour, minute } = stockholmClock();

  if (!force && (!TARGET_HOURS.has(hour) || minute !== 0)) {
    console.log(JSON.stringify({
      status: 'skipped',
      reason: 'outside_target_hours',
      stockholm_time: `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`,
    }));
    return;
  }

  const email = process.env.XANALYTICA_EMAIL;
  const password = process.env.XANALYTICA_PASSWORD;
  if (!email || !password) {
    throw new Error('Missing XANALYTICA_EMAIL or XANALYTICA_PASSWORD environment variable.');
  }

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  const context = await browser.newContext({
    locale: 'ar',
    timezoneId: TIME_ZONE,
    viewport: { width: 1440, height: 1100 },
  });

  const page = await context.newPage();
  page.setDefaultTimeout(15000);

  const networkErrors = [];
  page.on('response', (response) => {
    const type = response.request().resourceType();
    if ((type === 'xhr' || type === 'fetch') && response.status() >= 400) {
      networkErrors.push({
        at: Date.now(),
        status: response.status(),
        method: response.request().method(),
        url: response.url(),
      });
    }
  });

  page.on('dialog', async (dialog) => {
    try { await dialog.dismiss(); } catch {}
  });

  try {
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });

    const visibleInputs = page.locator('input:visible');
    if (await visibleInputs.count() < 2) {
      throw new Error('Could not locate login inputs.');
    }

    let emailInput = page.locator('input[type="email"]:visible').first();
    if (await emailInput.count() === 0) emailInput = visibleInputs.nth(0);

    let passwordInput = page.locator('input[type="password"]:visible').first();
    if (await passwordInput.count() === 0) passwordInput = visibleInputs.nth(1);

    await emailInput.fill(email);
    await passwordInput.fill(password);

    let submit = page.locator('button[type="submit"]:visible').first();
    if (await submit.count() === 0) {
      submit = page.getByRole('button', { name: /دخول|تسجيل|sign in|login/i }).first();
    }

    await submit.click();
    await page.waitForTimeout(1500);

    if (page.url().includes('/auth/sign-in')) {
      await page.waitForTimeout(2500);
    }
    if (page.url().includes('/auth/sign-in')) {
      throw new Error('Login failed or authentication was rejected.');
    }

    await page.goto(USERS_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.getByRole('button', { name: 'تحديث X', exact: true }).first().waitFor({ timeout: 20000 });

    const labels = ['تحديث X', 'تحديث فيسبوك', 'تحديث إنستغرام'];
    const summary = {
      status: 'completed',
      issued: 0,
      failed: 0,
      failures: [],
      platforms: {},
    };

    for (const label of labels) {
      const buttons = page.getByRole('button', { name: label, exact: true });
      const count = await buttons.count();
      summary.platforms[label] = { found: count, issued: 0, failed: 0 };

      if (count !== 11) {
        throw new Error(`Expected 11 buttons for "${label}", found ${count}.`);
      }

      for (let i = 0; i < count; i++) {
        if (!page.url().includes('/dashboard/users')) {
          await page.goto(USERS_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
        }

        const currentButtons = page.getByRole('button', { name: label, exact: true });
        const button = currentButtons.nth(i);
        await button.scrollIntoViewIfNeeded();

        const row = button.locator('xpath=ancestor::tr[1]');
        let user = `user-${i + 1}`;
        if (await row.count()) {
          const text = (await row.innerText()).replace(/\s+/g, ' ').trim();
          if (text) user = text.slice(0, 180);
        }

        const before = networkErrors.length;
        try {
          await button.click({ timeout: 12000 });
          summary.issued++;
          summary.platforms[label].issued++;
          await page.waitForTimeout(1200);

          const newErrors = networkErrors.slice(before);
          if (newErrors.length > 0) {
            summary.failed++;
            summary.platforms[label].failed++;
            summary.failures.push({
              user,
              action: label,
              error: `HTTP ${newErrors[0].status}`,
            });
          }
        } catch (error) {
          summary.failed++;
          summary.platforms[label].failed++;
          summary.failures.push({
            user,
            action: label,
            error: String(error.message || error).slice(0, 250),
          });
        }
      }
    }

    summary.ok = summary.issued === 33 && summary.failed === 0;
    console.log(JSON.stringify(summary));

    if (!summary.ok) process.exitCode = 1;
  } finally {
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }
}

main().catch((error) => {
  console.error(JSON.stringify({
    status: 'failed',
    error: String(error.message || error),
  }));
  process.exit(1);
});
