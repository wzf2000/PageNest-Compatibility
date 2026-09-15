const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const prettier = require('prettier');
const beautify = require('js-beautify').html;
const root = path.resolve(__dirname, '..');
const write = process.argv.includes('--write');
const generated = /assets\/[^/]+-[a-f0-9]{12}\.(css|js)$/;
const htmlOptions = {
  indent_size: 4,
  wrap_line_length: 0,
  wrap_attributes: 'preserve',
  templating: ['php'],
  preserve_newlines: true,
  extra_liners: [],
  end_with_newline: true,
  content_unformatted: ['pre', 'textarea', 'script', 'style'],
};
function run(command, args) {
  return execFileSync(command, args, { cwd: root, stdio: 'inherit' });
}
async function format() {
  const files = execFileSync(
    'git',
    ['ls-files', '--cached', '--others', '--exclude-standard', '-z'],
    { cwd: root },
  )
    .toString()
    .split('\0')
    .filter((file) => file && fs.existsSync(path.join(root, file)));
  const web = files.filter(
    (file) => /\.(php|js|cjs|css|json|md)$/.test(file) && !generated.test(file),
  );
  const python = files.filter((file) => file.endsWith('.py'));
  const changed = [];
  for (const file of web) {
    const location = path.join(root, file);
    const original = fs.readFileSync(location, 'utf8');
    const options = { ...(await prettier.resolveConfig(location)), filepath: location };
    let result = original;
    // Mixed templates may need another pass after PHP changes line lengths.
    for (let pass = 0; pass < 8; pass++) {
      const html =
        file.endsWith('.php') && result.includes('?>') ? beautify(result, htmlOptions) : result;
      const spaced = file.endsWith('.md') ? await require('./format-markdown.cjs')(html) : html;
      const next = (await prettier.format(spaced, options)).replace(/\n+$/, '\n');
      if (next === result) break;
      result = next;
      if (pass === 7) throw new Error(`Formatting did not stabilize: ${file}`);
    }
    if (result !== original) {
      changed.push(file);
      if (write) fs.writeFileSync(location, result);
    }
  }
  if (!write && changed.length) throw new Error(`Run npm run format for:\n${changed.join('\n')}`);
  run('python3', ['-m', 'black', ...(write ? [] : ['--check']), ...python]);
  if (write && fs.existsSync(path.join(root, 'build-assets.py')))
    run('python3', ['build-assets.py']);
  if (!fs.existsSync(path.join(root, 'assets/manifest.php'))) {
    console.log(`${web.length} web files checked; ${changed.length} formatting changes.`);
    return;
  }
  const assets = path.join(root, 'assets');
  const manifest = JSON.parse(
    execFileSync(
      'php',
      ['-r', 'echo json_encode(include $argv[1]);', path.join(assets, 'manifest.php')],
      { cwd: root },
    ),
  );
  for (const [key, name] of Object.entries({
    css: 'layout.css',
    js: 'navigation.js',
    reading: 'reading.js',
    login: 'login.css',
  })) {
    const content = fs.readFileSync(path.join(assets, name));
    const hash = crypto.createHash('sha256').update(content).digest('hex').slice(0, 12);
    const expected = `${path.parse(name).name}-${hash}${path.extname(name)}`;
    if (manifest[key] !== expected || !content.equals(fs.readFileSync(path.join(assets, expected))))
      throw new Error(`Rebuild assets: ${name}`);
  }
  console.log(
    `${web.length} web files checked; ${changed.length} ${write ? 'formatted' : 'need formatting'}; generated assets verified.`,
  );
}
format().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
