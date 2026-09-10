import fs from 'node:fs/promises';
import path from 'node:path';
import { Workbook } from '@oai/artifact-tool';

const outputDir = path.resolve('outputs/leave-import-tests-20260908/files');
const months = ['2026/2', '2026/3', '2026/4', '2026/5', '2026/6', '2026/7', '2026/8', '2026/9'];

const csvCell = value => {
  const text = String(value ?? '');
  return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
};
const csv = rows => rows.map(row => row.map(csvCell).join(',')).join('\r\n') + '\r\n';

function legacyCsv(employees) {
  const dateRow = Array(9).fill('');
  const header = ['', '', '入社年', '入社月', '更新月', '前年残り', '今年付与', '総数', ''];
  for (const month of months) {
    dateRow.push(month, '');
    header.push('残数', '使用');
  }
  const rows = [dateRow, header];
  for (const employee of employees) {
    const row = ['', employee.name, employee.hireYear, employee.hireMonth, employee.renewalMonth,
      employee.previous, employee.current, employee.total, ''];
    for (const month of months) {
      const values = employee.months?.[month] ?? {};
      row.push(values.balance ?? employee.balance ?? '', values.usage ?? '');
    }
    rows.push(row);
  }
  return csv(rows);
}

if (process.argv.includes('--ten-only')) {
  const names = ['Aさん', 'Bさん', 'Cさん', 'テスト社員04', 'テスト社員05', 'テスト社員06', 'テスト社員07', 'テスト社員08', 'テスト社員09', 'テスト社員10'];
  const employees = names.map((name, index) => {
    const previous = index % 3;
    const current = 10 + (index % 4);
    const total = previous + current;
    const used = index % 5;
    return {
      name,
      hireYear: 2020,
      hireMonth: (index % 12) + 1,
      renewalMonth: ((index + 6) % 12) + 1,
      previous,
      current,
      total,
      balance: total - used,
    };
  });
  const content = legacyCsv(employees);
  const workbook = await Workbook.fromCSV(content, { sheetName: 'TenEmployees' });
  const inspected = await workbook.inspect({ kind: 'table', sheetId: 'TenEmployees', range: 'A1:Y12', tableMaxRows: 12, tableMaxCols: 25, maxChars: 8000 });
  if (!inspected.ndjson.includes('テスト社員10')) throw new Error('10人目まで出力されていません。');
  const tenOutput = path.resolve('outputs/leave-import-tests-20260908/有給インポート_10人テスト.csv');
  await fs.mkdir(path.dirname(tenOutput), { recursive: true });
  await fs.writeFile(tenOutput, '\uFEFF' + content, 'utf8');
  console.log(`Created and validated ${employees.length} employees: ${tenOutput}`);
  process.exit(0);
}

const cases = [
  {
    file: '01_今年付与から先に消化.csv',
    expected: 'Aさん：今年付与0日、前年繰越3日',
    content: legacyCsv([{ name: 'Aさん', hireYear: 2018, hireMonth: 6, renewalMonth: 12, previous: 9, current: 20, total: 29, balance: 3 }]),
  },
  {
    file: '02_前年繰越なし.csv',
    expected: 'Bさん：今年付与5日、前年繰越0日',
    content: legacyCsv([{ name: 'Bさん', hireYear: 2024, hireMonth: 4, renewalMonth: 10, previous: 0, current: 12, total: 12, balance: 5 }]),
  },
  {
    file: '03_初回付与前の前借4日.csv',
    expected: 'Cさん：前借4日、2026-10-01付与予定10日、付与後残6日',
    content: legacyCsv([{ name: 'Cさん', hireYear: 2026, hireMonth: 4, renewalMonth: 10, previous: 0, current: 10, total: 10,
      months: { '2026/2': { balance: 0 }, '2026/3': { balance: 0 }, '2026/4': { balance: 0 }, '2026/5': { balance: 0 },
        '2026/6': { balance: 0 }, '2026/7': { balance: 0 }, '2026/8': { balance: 0, usage: -4 }, '2026/9': { balance: 0 } } }]),
  },
  {
    file: '04_半日単位.csv',
    expected: 'Aさん：今年付与9.5日、前年繰越1.5日',
    content: legacyCsv([{ name: 'Aさん', hireYear: 2018, hireMonth: 6, renewalMonth: 12, previous: 1.5, current: 10, total: 11.5, balance: 11 }]),
  },
  {
    file: '05_更新月の境界.csv',
    expected: '2026年9月時点：Aさんは更新前、Bさんは更新当月、Cさんは更新後として付与年度を判定',
    content: legacyCsv([
      { name: 'Aさん', hireYear: 2020, hireMonth: 4, renewalMonth: 10, previous: 0, current: 10, total: 10, balance: 10 },
      { name: 'Bさん', hireYear: 2020, hireMonth: 3, renewalMonth: 9, previous: 0, current: 10, total: 10, balance: 10 },
      { name: 'Cさん', hireYear: 2020, hireMonth: 2, renewalMonth: 8, previous: 0, current: 10, total: 10, balance: 10 },
    ]),
  },
  {
    file: '06_前借が付与日数を超過.csv',
    expected: 'Cさん：前借12日、付与予定10日、付与後残マイナス2日',
    content: legacyCsv([{ name: 'Cさん', hireYear: 2026, hireMonth: 4, renewalMonth: 10, previous: 0, current: 10, total: 10,
      months: { '2026/2': { balance: 0 }, '2026/3': { balance: 0 }, '2026/4': { balance: 0 }, '2026/5': { balance: 0 },
        '2026/6': { balance: 0 }, '2026/7': { balance: 0 }, '2026/8': { balance: 0, usage: -6 }, '2026/9': { balance: 0, usage: -6 } } }]),
  },
  {
    file: '07_通常の残数0.csv',
    expected: 'Aさん：今年付与・前年繰越とも0日でスキップ',
    content: legacyCsv([{ name: 'Aさん', hireYear: 2018, hireMonth: 6, renewalMonth: 6, previous: 0, current: 10, total: 10, balance: 0 }]),
  },
  {
    file: '08_未登録社員.csv',
    expected: '未登録テストさん：社員未登録エラー。ただし残数7日は表示',
    content: legacyCsv([{ name: '未登録テストさん', hireYear: 2020, hireMonth: 1, renewalMonth: 1, previous: 2, current: 10, total: 12, balance: 7 }]),
  },
  {
    file: '09_同姓同名.csv',
    expected: '同姓同名テストを2名登録した環境では、社員を特定できないエラー',
    content: legacyCsv([{ name: '同姓同名テスト', hireYear: 2020, hireMonth: 1, renewalMonth: 1, previous: 0, current: 10, total: 10, balance: 10 }]),
  },
  {
    file: '10_氏名の空白違い.csv',
    expected: '登録名Aさんに対し「 A　さん 」でも照合成功',
    content: legacyCsv([{ name: ' A　さん ', hireYear: 2018, hireMonth: 6, renewalMonth: 12, previous: 0, current: 10, total: 10, balance: 8 }]),
  },
  {
    file: '11_不正値.csv',
    expected: '更新月13、残数9.7のためエラー。画面は例外終了しない',
    content: legacyCsv([{ name: 'Aさん', hireYear: 2018, hireMonth: 6, renewalMonth: 13, previous: 0, current: 10, total: 10, balance: 9.7 }]),
  },
  {
    file: '12_標準CSVと期限上限.csv',
    expected: '1行目は3.5日で正常。2行目は有効期限が2年上限を超えるためエラー',
    content: csv([
      ['氏名', '対象年度', '残日数', '付与日', '有効期限', '更新月', '備考'],
      ['Aさん', 2026, 3.5, '2026-04-01', '2028-03-31', 4, '正常'],
      ['Aさん', 2026, 1, '2026-04-01', '2028-04-01', 4, '期限超過'],
    ]),
  },
];

await fs.rm(path.resolve('outputs/leave-import-tests-20260908'), { recursive: true, force: true });
await fs.mkdir(outputDir, { recursive: true });
const checks = [];
for (const testCase of cases) {
  const workbook = await Workbook.fromCSV(testCase.content, { sheetName: 'Test' });
  const inspected = await workbook.inspect({ kind: 'table', sheetId: 'Test', range: 'A1:Z20', tableMaxRows: 20, tableMaxCols: 26, maxChars: 4000 });
  if (!inspected.ndjson || !inspected.ndjson.includes('Test')) throw new Error(`CSV validation failed: ${testCase.file}`);
  await fs.writeFile(path.join(outputDir, testCase.file), '\uFEFF' + testCase.content, 'utf8');
  checks.push([testCase.file, testCase.expected, '確認画面まで。取り込み確定はしない']);
}

const expectedCsv = csv([['ファイル', '期待結果', '操作'], ...checks]);
const expectedWorkbook = await Workbook.fromCSV(expectedCsv, { sheetName: 'Expected' });
const expectedInspect = await expectedWorkbook.inspect({ kind: 'table', sheetId: 'Expected', range: 'A1:C20', tableMaxRows: 20, tableMaxCols: 3, maxChars: 8000 });
if (!expectedInspect.ndjson.includes('12_標準CSVと期限上限.csv')) throw new Error('期待結果一覧の検証に失敗しました。');
await fs.writeFile(path.join(outputDir, '00_期待結果一覧.csv'), '\uFEFF' + expectedCsv, 'utf8');

console.log(`Created and validated ${cases.length} test CSV files plus expected results.`);
