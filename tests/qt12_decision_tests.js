const assert = require('node:assert/strict');
const ASSETS={SPY:['SPDR S&P 500','etf','USD']};
const E={RESULT_VERSION:'11.3.0',metrics:a=>a?.length?({n:40,logloss:.35,ece:.08,accuracy:.62}):null};
const now=Date.now();
const state={sets:{},results:{},eventGuards:{},marketHealth:{},qt12Recommendations:{}};
function setup(){
 const last=new Date(now-86400000).toISOString().slice(0,10);
 const bars=Array.from({length:500},(_,i)=>({date:new Date(now-(500-i)*86400000).toISOString().slice(0,10),close:100}));
 bars.at(-1).date=last;
 state.sets.SPY={bars,basis:'adjusted',source:'unit',fingerprint:'fp'};
 state.results.SPY={version:'11.3.0',fingerprint:'fp',source:'unit',basis:'adjusted',ensemble:Array.from({length:40},(_,i)=>({i,y:i%3,p:[.1,.1,.8]})),oos:Array.from({length:5},()=>[]),p:[.1,.1,.8],currentP:Array.from({length:5},()=>[.1,.1,.8]),baseline:{logloss:.5},currentStructuralBreak:false,currentHighVolProbability:.1,trainedAt:'test'};
 state.eventGuards.SPY={at:new Date(now-1000).toISOString(),would_hold:false};
 state.marketHealth.SPY={mode:'live',at:new Date(now-1000).toISOString()};
}
setup();
const QT12={version:'12.0.1',running:false,stop:false,etfs:Object.entries(ASSETS).filter(([,v])=>v[1]==='etf').map(([k])=>k),
 delay:ms=>new Promise(resolve=>setTimeout(resolve,ms)),
 currentResult(asset){const r=state.results?.[asset],set=state.sets?.[asset];const validP=p=>Array.isArray(p)&&p.length===3&&p.every(v=>Number.isFinite(v)&&v>=0&&v<=1)&&Math.abs(p.reduce((a,b)=>a+b,0)-1)<=1e-5;return r&&set&&r.version===E.RESULT_VERSION&&r.fingerprint===set.fingerprint&&r.source===set.source&&r.basis===set.basis&&Array.isArray(r.oos)&&r.oos.length===5&&Array.isArray(r.currentP)&&r.currentP.length===5&&r.currentP.every(validP)&&validP(r.p)&&Array.isArray(r.ensemble)?r:null;},
 assess(asset){
  const set=state.sets?.[asset],r=this.currentResult(asset);if(!set||!r)return {asset,action:'NO_SIGNAL',direction:'NICHT BESTIMMBAR',strength:'KEIN SIGNAL',up:null,down:null,asof:set?.bars?.at(-1)?.date||null,reason:'Keine aktuelle, zum Kursdatensatz passende Modellberechnung.'};
  const p=r.p;if(!Array.isArray(p)||p.length!==3||p.some(x=>!Number.isFinite(x)||x<0||x>1)||Math.abs(p.reduce((a,b)=>a+b,0)-1)>1e-5)return {asset,action:'NO_SIGNAL',direction:'NICHT BESTIMMBAR',strength:'KEIN SIGNAL',up:null,down:null,asof:set.bars.at(-1).date,reason:'Ungültige oder nicht normalisierte Wahrscheinlichkeitsausgabe.'};
  const up=p[2],down=p[0],edge=up-down,dir=Math.abs(edge)<.02?'SEITWÄRTS':edge>0?'STEIGEND':'FALLEND';
  const modelRows=Array.isArray(r.currentP)?r.currentP.filter(x=>Array.isArray(x)&&x.length===3&&x.every(v=>Number.isFinite(v)&&v>=0&&v<=1)&&Math.abs(x.reduce((a,b)=>a+b,0)-1)<=1e-5):[];
  const votes=modelRows.length===5?(Math.abs(edge)<.02?modelRows.filter(x=>Math.abs(x[2]-x[0])<.02).length:modelRows.filter(x=>x[2]!==x[0]&&(edge>0?x[2]>x[0]:x[0]>x[2])).length):0;
  const m=E.metrics(r.ensemble),base=r.baseline;
  const lastBarTime=Date.parse(set.bars.at(-1).date+'T23:59:59Z');const age=(Date.now()-lastBarTime)/86400000;
  const dataReady=Number.isFinite(lastBarTime)&&age>=0&&age<=5;
  const marketHealth=state.marketHealth?.[asset],marketTime=Date.parse(marketHealth?.at||''),marketAge=Date.now()-marketTime;
  const marketFresh=marketHealth?.mode==='live'&&Number.isFinite(marketTime)&&marketAge>=0&&marketAge<=2*3600000;
  const enough=m&&m.n>=30&&Number.isFinite(m.logloss)&&Number.isFinite(m.ece);
  const betterThanBase=!!(m&&base&&Number.isFinite(base.logloss)&&m.logloss<base.logloss);
  const calibrated=!!(m&&m.ece<=.20);
  const priceBasisReady=set.basis==='adjusted';const quality=!!(enough&&betterThanBase&&calibrated&&votes>=3&&dataReady&&marketFresh&&set.bars.length>=500&&priceBasisReady);
  const structural=!!r.currentStructuralBreak;
  const highVol=Number.isFinite(r.currentHighVolProbability)&&r.currentHighVolProbability>=.65;
  const guard=state.eventGuards?.[asset];const guardTime=Date.parse(guard?.at||'');const guardAge=Date.now()-guardTime;const guardFresh=!!guard&&Number.isFinite(guardTime)&&guardAge>=0&&guardAge<=24*3600000&&typeof guard.would_hold==='boolean';const eventVeto=guardFresh&&guard.would_hold===true;const eventClear=guardFresh&&guard.would_hold===false;
  let action='NO_SIGNAL',reason=[];
  if(!dataReady)reason.push('Kursdaten-Zeitstempel liegt in der Zukunft oder ist älter als fünf Tage');
  if(!marketFresh)reason.push('kein erfolgreicher Live-Kursabruf innerhalb der letzten zwei Stunden');
  if(!priceBasisReady)reason.push('Kursbasis nicht als split- und ausschüttungsbereinigt deklariert');
  else reason.push('Preisbereinigung deklariert; keine unabhängige externe Attestierung');
  if(!enough)reason.push('zu wenige OOS-Testfälle oder fehlende Qualitätsmetriken');
  if(enough&&!betterThanBase)reason.push('Ensemble schlägt die kausale Baseline im LogLoss nicht');
  if(enough&&!calibrated)reason.push('Kalibrierungsfehler über 20 %');
  if(votes<3)reason.push('weniger als drei von fünf Modellen stimmen überein');
  if(structural)reason.push('CUSUM-Strukturbruch aktiv');
  if(highVol)reason.push('hohes Volatilitätsregime');
  if(eventVeto)reason.push('Nachrichten-Event-Guard blockiert wegen materiellem Warnereignis');
  if(!eventClear)reason.push('keine frische, unauffällige Event-Guard-Antwort');
  if(quality&&!structural&&!highVol&&eventClear&&!eventVeto){
   action='HOLD';
   if(up>=.56&&edge>=.14)action='BUY';
   else if(down>=.56&&edge<=-.14)action='SELL';
   else reason.push('Qualitätsgate erfüllt, aber kein ausreichend großer gerichteter Vorteil');
  }else if(!reason.length)reason.push('Signalbedingungen nicht vollständig erfüllt');
  if(action==='BUY'||action==='SELL')reason.unshift('OOS-Qualitätsgate erfüllt');
  const strength=action==='NO_SIGNAL'?'KEIN SIGNAL':quality&&!structural&&!highVol&&eventClear&&!eventVeto&&Math.abs(edge)>=.25&&votes>=4?'STARK':quality&&!structural&&!highVol&&eventClear&&!eventVeto&&Math.abs(edge)>=.14?'MITTEL':'SCHWACH';
  return {asset,action,direction:dir,strength,up,down,edge,votes,asof:set.bars.at(-1).date,ageDays:age,marketFresh,
   accuracy:m?.accuracy??null,logloss:m?.logloss??null,ece:m?.ece??null,n:m?.n??0,quality,reason:reason.join(' · '),
   highVol,structural,eventVeto,guardFresh,trainedAt:r.trainedAt,basis:set.basis,source:set.source,fingerprint:set.fingerprint};
 },
 render(){
  const rows=this.etfs.map(a=>{const fresh=this.assess(a);if(state.qt12Recommendations)state.qt12Recommendations[a]=fresh;return fresh;});
  const tbody=$('qt12Rows');if(!tbody)return;
  tbody.innerHTML=rows.map(x=>`<tr><td><b>${esc(x.asset)}</b><br><small>${esc(ASSETS[x.asset][0])}</small></td><td><span class="qt12-pill ${x.action==='BUY'?'buy':x.action==='SELL'?'sell':x.action==='NO_SIGNAL'?'none':'hold'}">${x.action==='BUY'?'KAUFEN':x.action==='SELL'?'VERKAUFEN':x.action==='NO_SIGNAL'?'KEIN SIGNAL':'HALTEN'}</span></td><td class="qt12-strong ${x.direction==='STEIGEND'?'green':x.direction==='FALLEND'?'red':'muted'}">${x.direction}</td><td><b>${x.strength}</b><br><small>${x.votes??0}/5 Modelle</small></td><td>${x.up===null?'—':pct(x.up,1)}</td><td>${x.down===null?'—':pct(x.down,1)}</td><td>${esc(x.asof||'—')}</td><td class="qt12-note">${esc(x.reason||'—')}</td></tr>`).join('');
  const selected=this.assess(state.asset);if(this.etfs.includes(state.asset)){state.qt12Recommendations||={};state.qt12Recommendations[state.asset]=selected;}
  const current=$('qt12Current');if(current){if(!selected||!this.etfs.includes(state.asset)){current.innerHTML='<div class="qt12-empty">Aktives Instrument ist kein ETF.</div>';}else{current.innerHTML=`<div class="qt12-hero"><div><div class="sub">${esc(selected.asset)} · ${esc(ASSETS[selected.asset][0])} · MODELLVERMUTUNG</div><div class="qt12-action ${selected.action==='BUY'?'buy':selected.action==='SELL'?'sell':selected.action==='NO_SIGNAL'?'none':'hold'}">${selected.action==='BUY'?'KAUFEN':selected.action==='SELL'?'VERKAUFEN':selected.action==='NO_SIGNAL'?'KEIN SIGNAL':'HALTEN'}</div><div class="qt12-direction ${selected.direction==='STEIGEND'?'green':selected.direction==='FALLEND'?'red':''}">${selected.direction}</div><div class="sub">Signalstärke: <b>${selected.strength}</b> · Stand ${esc(selected.asof||'—')}</div></div><div><div class="qt12-statgrid"><div class="qt12-stat"><small>Vermutung STEIGEND</small><b class="green">${selected.up===null?'—':pct(selected.up,1)}</b></div><div class="qt12-stat"><small>Vermutung FALLEND</small><b class="red">${selected.down===null?'—':pct(selected.down,1)}</b></div><div class="qt12-stat"><small>Modellkonsens</small><b>${selected.votes??0}/5</b></div><div class="qt12-stat"><small>OOS-Testfälle</small><b>${selected.n??0}</b></div></div><p class="sub">${esc(selected.reason||'Keine Zusatzhinweise.')}</p></div></div>`;}}
 },
 async waitForTraining(asset,previous){const deadline=Date.now()+30*60*1000;while(Date.now()<deadline){if(this.stop)throw Error('Gesamtlauf vom Nutzer gestoppt.');if(!busy){const r=state.results?.[asset];return !!(r&&r!==previous&&r.trainedAt!==previous?.trainedAt&&this.currentResult(asset));}await this.delay(350);}throw Error('Zeitlimit beim Training von '+asset+'.');},
 async run(){if(this.running||busy||marketLoading||sourceLoading)return toast('Ein anderer Lauf ist aktiv.');this.running=true;this.stop=false;const original=state.asset;const results={...(state.qt12Recommendations||{})};const button=$('qt12Run'),stop=$('qt12Stop');button.disabled=true;stop.disabled=false;let done=0,failed=0;
  try{for(const asset of this.etfs){if(this.stop)break;state.asset=asset;$('asset').value=asset;render();$('qt12Status').textContent=`ETF ${done+1}/${this.etfs.length}: ${asset} · Kursdaten werden aktualisiert …`;
    let set=state.sets?.[asset];const oldResult=state.results?.[asset];
    await loadMarket();
    if(this.stop)break;
    $('qt12Status').textContent=`ETF ${done+1}/${this.etfs.length}: ${asset} · Nachrichten, Makro- und Ereignisquellen werden geprüft …`;
    if(!sourceLoading&&!busy){try{await loadSources(false);}catch(sourceError){state.eventGuards||={};state.eventGuards[asset]={at:new Date().toISOString(),would_hold:null,status:'unavailable',reason:cleanError(sourceError)};log('warn','QuantTrend '+asset+': Quellenabruf fehlgeschlagen: '+cleanError(sourceError));}}
    if(this.stop)break;
    set=state.sets?.[asset];if(!set){results[asset]={asset,action:'NO_SIGNAL',direction:'NICHT BESTIMMBAR',strength:'KEIN SIGNAL',up:null,down:null,asof:null,reason:'Keine echte Kursreihe verfügbar.'};failed++;state.qt12Recommendations=results;this.render();continue;}
    if(this.currentResult(asset)){
      // Keep current model only if data fingerprint and source still match.
    }else{
      const before=state.results?.[asset]||null;
      $('qt12Status').textContent=`ETF ${done+1}/${this.etfs.length}: ${asset} · fünf Modelle werden separat trainiert …`;
      await startTrain();
      if(!busy&&!this.currentResult(asset)){results[asset]={asset,action:'NO_SIGNAL',direction:'NICHT BESTIMMBAR',strength:'KEIN SIGNAL',up:null,down:null,asof:set.bars.at(-1).date,reason:'Training konnte nicht gestartet werden oder wurde vorzeitig abgebrochen.'};failed++;state.qt12Recommendations=results;this.render();continue;}
      const completed=await this.waitForTraining(asset,before);
      if(!completed){results[asset]={asset,action:'NO_SIGNAL',direction:'NICHT BESTIMMBAR',strength:'KEIN SIGNAL',up:null,down:null,asof:set.bars.at(-1).date,reason:'Training fehlgeschlagen oder kein aktuelles Ergebnis erzeugt.'};failed++;state.qt12Recommendations=results;this.render();continue;}
    }
    const recommendation=this.assess(asset);results[asset]=recommendation;state.qt12Recommendations=results;done++;this.render();await save();
    $('qt12Status').textContent=`Lauf ${done}/${this.etfs.length} abgeschlossen · ${failed} ohne verwertbare Daten/Training · zuletzt ${asset}.`;
  }
  state.qt12Recommendations=results;state.qt12LastRun=new Date().toISOString();
  $('qt12Status').textContent=`${this.stop?'Abgebrochen':'Gesamtlauf beendet'} · ${done} ETFs bewertet · ${failed} ohne vollständiges Modell. Empfehlungen sind Vermutungen auf Basis der jeweils letzten verfügbaren Daten.`;
  }catch(e){$('qt12Status').textContent='Gesamtlauf unterbrochen: '+cleanError(e);log('error','QuantTrend 12: '+cleanError(e));}
  finally{state.asset=original;$('asset').value=original;this.running=false;button.disabled=false;stop.disabled=true;render();this.render();await save();}
 }
};
function decision(){return QT12.assess('SPY');}
let r=decision();assert.equal(r.action,'BUY');assert.equal(r.direction,'STEIGEND');assert.equal(r.votes,5);
const savedEnsemble=state.results.SPY.ensemble;state.results.SPY.ensemble=[];r=decision();assert.equal(r.direction,'STEIGEND');assert.equal(r.up,.8);assert.equal(r.n,0);state.results.SPY.ensemble=savedEnsemble;
state.eventGuards.SPY.at=new Date(now+3600000).toISOString();r=decision();assert.equal(r.action,'NO_SIGNAL');assert.equal(r.strength,'KEIN SIGNAL');
state.eventGuards.SPY.at=new Date(now-1000).toISOString();
state.sets.SPY.bars.at(-1).date=new Date(now+3*86400000).toISOString().slice(0,10);r=decision();assert.equal(r.action,'NO_SIGNAL');
state.sets.SPY.bars.at(-1).date=new Date(now-86400000).toISOString().slice(0,10);
state.results.SPY.p=[.5,.5,.5];r=decision();assert.equal(r.action,'NO_SIGNAL');
state.results.SPY.p=[.1,.1,.8];state.results.SPY.currentP=[[.1,.1,.8],[.1,.1,.8],[.1,.1,.8]];r=decision();assert.equal(r.action,'NO_SIGNAL');assert.equal(r.votes,0);
state.results.SPY.currentP=Array.from({length:5},()=>[.1,.1,.8]);state.eventGuards.SPY.would_hold=true;r=decision();assert.equal(r.action,'NO_SIGNAL');assert.equal(r.eventVeto,true);
state.eventGuards.SPY.would_hold=false;state.results.SPY.p=[.34,.32,.34];state.results.SPY.currentP=Array.from({length:5},()=>[.34,.32,.34]);r=decision();assert.equal(r.action,'HOLD');assert.equal(r.direction,'SEITWÄRTS');
state.marketHealth.SPY.mode='error';r=decision();assert.equal(r.action,'NO_SIGNAL');
console.log('PASS: 8 QuantTrend-12 decision regression cases');
