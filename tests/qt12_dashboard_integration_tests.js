'use strict';
const fs=require('fs');
const s=fs.readFileSync(require('path').join(__dirname,'..','index.html'),'utf8');
const checks=[
 ['dashboard signal slot exists',s.includes('id="qt12DashboardSignal"')],
 ['dashboard slot is fed from QT12 assessment',s.includes('const dashboardStrip=$(\'qt12DashboardSignal\')')],
 ['legacy decision panel receives ETF recommendation',s.includes('const dashboardDecision=$(\'decision\')')],
 ['training setup errors are persisted',s.includes('state.qt12TrainingErrors[state.asset]=String(e?.message||e)')],
 ['worker errors are persisted',s.includes('state.qt12TrainingErrors[asset]=String(e.message||\'Unbekannter Workerfehler\')')],
 ['training error is shown in ETF row',s.includes("'Training fehlgeschlagen: '+trainingError")],
 ['current result validates five current model probabilities',s.includes('Array.isArray(r.currentP)&&r.currentP.length===5')],
 ['current result does not confuse OOS ensemble row count with model count',!s.includes('r.ensemble?.length===5')],
 ['no fake probability fallback',!s.includes('up:0.5,down:0.5')]
];
let failed=0;for(const [name,ok] of checks){console.log((ok?'PASS':'FAIL')+': '+name);if(!ok)failed++;}
if(failed)process.exit(1);
