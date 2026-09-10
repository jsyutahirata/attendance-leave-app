import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';

const root = new URL('../', import.meta.url);

async function filesUnder(relative) {
  const dir = new URL(relative, root);
  const entries = await readdir(dir, { withFileTypes: true });
  const result = [];
  for (const entry of entries) {
    const child = join(relative, entry.name).replaceAll('\\', '/');
    if (entry.isDirectory()) result.push(...await filesUnder(child + '/'));
    else result.push(child);
  }
  return result;
}

const phpFiles = (await Promise.all(['src/', 'views/', 'public/', 'scripts/'].map(filesUnder))).flat().filter(f => f.endsWith('.php'));
const failures = [];
for (const file of phpFiles) {
  const text = await readFile(new URL(file, root), 'utf8');
  if (!text.includes('<?php') && !text.includes('<?=')) failures.push(`${file}: missing PHP tag`);
  if (text.includes('$_POST[') && !text.includes('Csrf') && !file.includes('Controller.php')) failures.push(`${file}: POST handling outside central CSRF boundary`);
  for (const form of text.matchAll(/<form\s+method="post"[\s\S]*?<\/form>/gi)) {
    if (!form[0].includes('Csrf::field()')) failures.push(`${file}: POST form without CSRF field`);
  }
}

const controller = await readFile(new URL('src/Controller.php', root), 'utf8');
const layout = await readFile(new URL('views/layout.php', root), 'utf8');
const schema = await readFile(new URL('database/schema.sql', root), 'utf8');
for (const table of ['users','employees','leave_grants','leave_entries','leave_adjustments','attendance_events','attendance_notices','company_calendar_events','japanese_holidays','audit_logs','password_reset_tokens','remember_login_tokens','app_settings','push_subscriptions','push_preferences','push_notification_logs']) {
  if (!schema.includes(`CREATE TABLE ${table}`)) failures.push(`schema: missing ${table}`);
}
if ((schema.match(/grant_year SMALLINT/g) ?? []).length !== 2) failures.push('schema: leave grant year fields are incomplete');
if (!schema.includes('leave_renewal_month TINYINT')) failures.push('schema: employee leave renewal month is missing');
for (const route of ['login','leave/create','leave/cancel','calendar/personal/create','attendance/clock','notice/create','admin/users/create','admin/users/update','admin/users/password-reset','admin/users/logout-all','admin/view-grants/all/create','admin/leave/create','admin/leave/grant','admin/leave/review','admin/leave/import/template','admin/leave/import/preview','admin/leave/import/confirm','admin/settings/approval','admin/calendar','admin/calendar/save','admin/calendar/delete','admin/calendar/import/preview','admin/calendar/import/confirm','admin/calendar/import/cancel','push/subscribe','push/unsubscribe','push/preferences','admin/attendance']) {
  if (!controller.includes(`'${route}'`)) failures.push(`controller: missing ${route}`);
}
if (!layout.includes('Csrf::field()')) failures.push('layout: logout is not CSRF protected');
if (schema.includes('ip_address')) failures.push('schema: audit IP address must not be retained');
for (const file of ['public/manifest.webmanifest','public/service-worker.js','public/assets/app.js']) {
  try { await readFile(new URL(file, root), 'utf8'); } catch { failures.push(`missing platform file: ${file}`); }
}

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log(`Static checks passed (${phpFiles.length} PHP files, core schema and routes present).`);
