
export default async function run(page) {
  const out = {};

  await page.goto('http://127.0.0.1:8123/login');
  await page.fill('#email', 'qa.dev@example.com');
  await page.fill('#password', 'Password123!');
  await page.getByRole('button', { name: /Sign In/i }).click();
  await page.waitForLoadState('networkidle').catch(() => { });
  await page.waitForTimeout(700);

  // --- students index ---
  await page.goto('http://127.0.0.1:8123/meals/students');
  await page.waitForLoadState('networkidle').catch(() => { });
  await page.waitForTimeout(600);

  out.students = await page.evaluate(() => ({
    heading: document.querySelector('h3')?.innerText,
    headers: [...document.querySelectorAll('thead th')].map(th => th.innerText.trim()),
    rows: [...document.querySelectorAll('tbody tr')].map(tr =>
      [...tr.querySelectorAll('td')].map(td => td.innerText.trim().split('\n')[0]).slice(0, 7)
    ),
  }));

  // --- filter by department ---
  await page.selectOption('select >> nth=0', { label: 'Computer Science' });
  await page.waitForLoadState('networkidle').catch(() => { });
  await page.waitForTimeout(700);

  out.filteredByCS = await page.evaluate(() =>
    [...document.querySelectorAll('tbody tr')].map(tr => tr.querySelector('td')?.innerText.trim().split('\n')[0]).filter(Boolean)
  );

  // --- open a student detail page ---
  await page.goto('http://127.0.0.1:8123/meals/students');
  await page.waitForLoadState('networkidle').catch(() => { });
  await page.waitForTimeout(500);
  await page.locator('tbody tr td a').first().click();
  await page.waitForLoadState('networkidle').catch(() => { });
  await page.waitForTimeout(600);

  out.detail = await page.evaluate(() => ({
    url: location.pathname,
    name: document.querySelector('h3')?.innerText,
    stats: [...document.querySelectorAll('.rounded-xl.border-slate-200.bg-white.p-4')].map(c =>
      c.innerText.trim().replace(/\n+/g, ' | ')
    ),
    sections: [...document.querySelectorAll('h4')].map(h => h.innerText.trim()),
  }));

  return out;
}
