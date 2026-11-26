'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const html = fs.readFileSync(new URL('../index.html', `file://${__filename}`), 'utf8');
const bridge = fs.readFileSync(new URL('../quanttrend_bridge.py', `file://${__filename}`), 'utf8');
const expected = ['EUNL.DE', 'XMAW.DE', 'IQQE.DE'];
const priority = html.match(/const ETF_PRIORITY=\[(.*?)\];/s)?.[1];
assert.ok(priority, 'ETF_PRIORITY registry exists');
assert.deepEqual([...priority.matchAll(/'([^']+)'/g)].map(x => x[1]), expected, 'priority order is stable');
for (const [asset, isin, wkn, symbol] of [
  ['EUNL.DE', 'IE00B4L5Y983', 'A0RPWH', 'EUNL.DE'],
  ['XMAW.DE', 'IE00BGHQ0G80', 'A1W8SB', 'XMAW.DE'],
  ['IQQE.DE', 'IE00B0M63177', 'A0HGWC', 'IQQE.DE'],
]) {
  assert.match(html, new RegExp(`['"]${asset}['"]:\\['[^']+','etf','EUR'\\]`), `${asset} is a selectable ETF`);
  assert.ok(html.includes(`isin:'${isin}'`) && html.includes(`wkn:'${wkn}'`), `${asset} issuer identifiers exist`);
  assert.ok(bridge.includes(`"${asset}": {"yahoo": "${symbol}"`), `${asset} exists in local bridge`);
}
assert.match(html, /etfs:\[\.\.\.ETF_PRIORITY,\.\.\.Object\.entries\(ASSETS\)/, 'all other ETFs remain after priority group');
assert.match(html, /regularRows=rows\.filter\(x=>!ETF_PRIORITY\.includes\(x\.asset\)\)/, 'regular ETF table remains');
assert.match(html, /QT12\.etfs\.map\(a=>/, 'CSV export consumes full ordered ETF universe');
assert.match(html, /async function loadSources\(retryOnly=false,options=\{\}\)/, 'source loader accepts portfolio-scoped options');
assert.match(html, /if\(!options\.assetOnly\)await fetchQualityFeeds\(asset,epoch\)/, 'global quality feeds are fetched only in the first portfolio pass');
assert.match(html, /!options\.assetOnly\|\|assetKeys\.includes\(k\)/, 'subsequent ETF passes still fetch asset-specific news categories');
assert.match(html, /assetOnly:globalSourcesLoaded/, 'portfolio runner switches to asset-only source refresh after the first ETF');
assert.match(html, /onProgress:progress=>/, 'portfolio runner exposes granular source progress');
console.log('PASS: 3 prioritized ETFs, issuer IDs, bridge symbols, complete ETF universe and CSV ordering');
