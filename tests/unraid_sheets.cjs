// An Unraid sheet as CSS to inline in a file:// page: /webGui/styles font URLs point at the styles folder, relative ones sit beside it.
const fs = require('node:fs');
const path = require('node:path');

exports.inlineSheet = (styles, name) => {
  const file = path.join(styles, name);
  return fs.readFileSync(file, 'utf8').replace(/url\((['"]?)([^'")]+)\1\)/g, (match, quote, ref) => {
    if (/^(data|https?|file):/.test(ref)) return match;
    const bare = ref.split(/[?#]/)[0];
    const target = bare.startsWith('/webGui/styles/') ? path.join(styles, bare.slice('/webGui/styles/'.length)) : path.resolve(path.dirname(file), bare);
    return `url('file://${target}')`;
  });
};
