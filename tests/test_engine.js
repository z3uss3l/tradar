const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(__dirname + '/../index.html', 'utf8');
const scriptRegex = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
let match;
let execCount = 0;

while ((match = scriptRegex.exec(html)) !== null) {
  const code = match[2];
  new vm.Script(code);
  execCount++;
}

const idRegex = /\bid=["']([^"']+)["']/gi;
const ids = [];
while ((match = idRegex.exec(html)) !== null) {
  ids.push(match[1]);
}
const dupes = ids.filter((id, idx) => ids.indexOf(id) !== idx);
if (dupes.length > 0) {
  throw new Error('Duplicate static HTML IDs: ' + dupes.join(', '));
}

console.log(`JS/HTML CHECK PASSED: ${execCount} scripts, ${ids.length} unique IDs.`);
