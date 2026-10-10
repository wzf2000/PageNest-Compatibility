const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const encoder = fs.readFileSync(path.join(__dirname, '../assets/social-qrcode.js'), 'utf8');
const behavior = fs.readFileSync(path.join(__dirname, '../assets/social-share.js'), 'utf8');
const url = 'https://example.test/reading/%E7%AB%A0%E8%8A%82/?topic=math&part=1';
const fills = [];
let canvas;
const target = {
  dataset: { pagenestSocialQr: url },
  append: (value) => {
    canvas = value;
  },
};
const context = vm.createContext({
  document: {
    querySelectorAll: () => [target],
    createElement: () => ({
      setAttribute: () => {},
      getContext: () => ({ fillRect: (...args) => fills.push(args) }),
    }),
  },
});
vm.runInContext(encoder, context);
vm.runInContext(behavior, context);
assert(canvas.width === canvas.height && canvas.width > 100);
assert(fills.length > 100);
assert.equal(fills[0][2], canvas.width);
assert.equal(fills[0][3], canvas.height);
assert.equal(target.textContent, undefined);
// Encoder is local and exposes no external transport APIs.
assert(!/fetch\(|XMLHttpRequest|https?:\/\//.test(behavior));
const failed = { dataset: { pagenestSocialQr: url } };
context.document.querySelectorAll = () => [failed];
context.qrcode = () => {
  throw new Error('Capacity exceeded');
};
vm.runInContext(behavior, context);
assert.equal(failed.textContent, '请复制文章链接到微信。');
console.log('Social sharing: local QR generation and accessible fallback passed.');
