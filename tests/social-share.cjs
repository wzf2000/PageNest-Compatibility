// The QR encoder is local. Dialog interaction is exercised by social-browser.cjs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const encoder = fs.readFileSync(path.join(__dirname, '../assets/social-qrcode.js'), 'utf8');
const context = vm.createContext({});
vm.runInContext(encoder, context);
const matrix = (url) => {
  const code = context.qrcode(0, 'M');
  code.addData(url, 'Byte');
  code.make();
  const size = code.getModuleCount();
  assert(size >= 21 && (size - 21) % 4 === 0);
  return Array.from({ length: size }, (_, row) =>
    Array.from({ length: size }, (_, column) => code.isDark(row, column)),
  );
};
const url = 'https://example.test/reading/%E7%AB%A0%E8%8A%82/?topic=math&part=1';
const first = matrix(url);
assert.deepEqual(first, matrix(url));
assert.notDeepEqual(first, matrix(url + '#stage-2'));
assert(first.flat().filter(Boolean).length > 100);
assert(!/fetch\(|XMLHttpRequest/.test(encoder));
console.log('Local QR encoder: deterministic link encoding and distinct fragment passed.');
