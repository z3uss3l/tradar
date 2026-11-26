'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const html=fs.readFileSync(path.join(__dirname,'..','index.html'),'utf8');
const marker='/* Cross-ETF dependency diagnostics.';
const at=html.indexOf(marker);
assert.notEqual(at,-1,'embedded dependency engine exists');
const open=html.lastIndexOf('<script>',at);
const close=html.indexOf('</script>',at);
assert.ok(open>=0&&close>at,'dependency script boundaries exist');
const source=html.slice(open+8,close);

const start=Date.UTC(2025,0,1);
const sets={};
for(const asset of ['EUNL.DE','XMAW.DE','IQQE.DE']){
  let closePrice=100;
  const bars=[];
  for(let i=0;i<260;i++){
    const common=Math.sin(i*.17)*.004+Math.cos(i*.037)*.002;
    const ret=asset==='EUNL.DE'?common:
      asset==='XMAW.DE'?common*.9+Math.sin(i*.31)*.0004:
      common*.45+Math.cos(i*.13)*.003;
    closePrice*=Math.exp(ret);
    bars.push({date:new Date(start+i*86400000).toISOString().slice(0,10),close:closePrice});
  }
  sets[asset]={bars,basis:'adjusted'};
}
const context={
  state:{sets},
  QT12:{assess:()=>({action:'HOLD',direction:'STEIGEND'}),render:()=>{}},
  document:{getElementById:()=>null}
};
vm.runInNewContext(source+'\nthis.__dependency=QT12Dependency;',context);
const result=context.__dependency.compute();
assert.equal(result.pairs.length,3);
assert.ok(result.pairs.every(p=>p.n===259));
assert.ok(result.pairs.find(p=>p.a==='EUNL.DE'&&p.b==='XMAW.DE').c60>.95,
  'highly co-moving synthetic World / AC World pair is detected');
assert.ok(result.pairs.find(p=>p.a==='EUNL.DE'&&p.b==='IQQE.DE').c60<.9,
  'less correlated synthetic EM pair is distinguished');
assert.ok(result.neff>=1&&result.neff<=3);
assert.equal(result.dates.length,3);

const broken={...sets,'IQQE.DE':{bars:sets['IQQE.DE'].bars.slice(0,8),basis:'raw'}};
context.state.sets=broken;
const short=context.__dependency.compute();
assert.ok(short.pairs.some(p=>p.c60===null),'insufficient common samples do not produce a correlation');
assert.equal(short.dates.find(x=>x.asset==='IQQE.DE').basis,'raw');

console.log('QT12 dependency diagnostics: all assertions passed');
