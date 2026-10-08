const instruments={
 guitar:{name:"Violão",icon:"🎸",tuning:[["E2",82.41],["A2",110],["D3",146.83],["G3",196],["B3",246.94],["E4",329.63]]},
 bass:{name:"Baixo",icon:"🎸",tuning:[["E1",41.20],["A1",55],["D2",73.42],["G2",98]]},
 ukulele:{name:"Ukulele",icon:"🪕",tuning:[["G4",392],["C4",261.63],["E4",329.63],["A4",440]]},
 violin:{name:"Violino",icon:"🎻",tuning:[["G3",196],["D4",293.66],["A4",440],["E5",659.25]]},
 viola:{name:"Viola",icon:"🎻",tuning:[["C3",130.81],["G3",196],["D4",293.66],["A4",440]]},
 cello:{name:"Violoncelo",icon:"🎻",tuning:[["C2",65.41],["G2",98],["D3",146.83],["A3",220]]},
 mandolin:{name:"Bandolim",icon:"🪕",tuning:[["G3",196],["D4",293.66],["A4",440],["E5",659.25]]},
 cavaquinho:{name:"Cavaquinho",icon:"🎸",tuning:[["D4",293.66],["G4",392],["B4",493.88],["D5",587.33]]},
 chromatic:{name:"Cromático",icon:"🎼",tuning:null}
};
let current="guitar",audioCtx=null,analyser=null,source=null,stream=null,raf=0,running=false;
const $=s=>document.querySelector(s),grid=$("#instrumentGrid"),list=$("#stringList"),labels=$("#stringLabels");
function renderInstruments(){
 grid.innerHTML=Object.entries(instruments).map(([id,x])=>'<button class="inst '+(id===current?"active":"")+'" data-id="'+id+'"><span class="ico">'+x.icon+'</span>'+x.name+'</button>').join("");
 grid.querySelectorAll(".inst").forEach(b=>b.onclick=()=>selectInstrument(b.dataset.id));
}
function selectInstrument(id){current=id;renderInstruments();renderTuning();$("#statusPill").textContent="PRONTO PARA AFINAR";$("#verdict").textContent="Toque uma corda ou nota para começar"}
function renderTuning(){
 const t=instruments[current].tuning;
 list.innerHTML=t?t.map((x,i)=>'<div class="string-row" data-i="'+i+'"><b>'+x[0]+'</b><small>'+x[1].toFixed(2)+' Hz</small></div>').join(""):'<div class="privacy">Modo cromático: o JADIEL PLAY identifica a nota musical mais próxima automaticamente.</div>';
 labels.innerHTML=t?t.map(x=>'<span>'+x[0]+'</span>').join(""):"";
 list.querySelectorAll(".string-row").forEach((r,i)=>r.onclick=()=>highlight(i));
}
function highlight(i){list.querySelectorAll(".string-row").forEach(x=>x.classList.remove("active"));const r=list.querySelectorAll(".string-row")[i];if(r)r.classList.add("active")}
function pitchFromBuffer(buf,sampleRate){
 let rms=0;for(let i=0;i<buf.length;i++)rms+=buf[i]*buf[i];rms=Math.sqrt(rms/buf.length);
 if(rms<0.008)return{pitch:-1,confidence:0};
 const size=buf.length,minLag=Math.floor(sampleRate/1000),maxLag=Math.min(Math.floor(sampleRate/35),size-2);
 let bestOffset=-1,bestCorr=0;
 for(let lag=minLag;lag<=maxLag;lag++){
  let corr=0,n=0;
  for(let i=0;i<size-lag;i+=2){corr+=buf[i]*buf[i+lag];n++}
  corr/=n;
  if(corr>bestCorr){bestCorr=corr;bestOffset=lag}
 }
 if(bestOffset<0)return{pitch:-1,confidence:0};
 const c=lag=>{let v=0,n=0;for(let i=0;i<size-lag;i+=2){v+=buf[i]*buf[i+lag];n++}return v/n};
 let refined=bestOffset;
 if(bestOffset>minLag&&bestOffset<maxLag){const y1=c(bestOffset-1),y2=c(bestOffset),y3=c(bestOffset+1),d=y1-2*y2+y3;if(d)refined=bestOffset+(y1-y3)/(2*d)}
 return{pitch:sampleRate/refined,confidence:Math.max(0,Math.min(1,bestCorr*3))}
}
function noteFromFreq(f){
 const midi=Math.round(69+12*Math.log2(f/440)),freq=440*Math.pow(2,(midi-69)/12),cents=1200*Math.log2(f/freq);
 const names=["C","C♯","D","D♯","E","F","F♯","G","G♯","A","A♯","B"];
 return{name:names[(midi+120)%12],octave:Math.floor(midi/12)-1,freq,cents,midi}
}
function findTarget(pitch){
 const t=instruments[current].tuning;
 if(!t)return noteFromFreq(pitch);
 let best=t[0],diff=Math.abs(1200*Math.log2(pitch/t[0][1]));
 t.forEach(x=>{const d=Math.abs(1200*Math.log2(pitch/x[1]));if(d<diff){diff=d;best=x}});
 return{name:best[0],freq:best[1],cents:1200*Math.log2(pitch/best[1])}
}
async function startMic(){
 if(running)return;
 if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){$("#verdict").textContent="Este navegador não oferece acesso ao microfone.";return}
 try{
  stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:false,noiseSuppression:false,autoGainControl:false}});
  audioCtx=new(window.AudioContext||window.webkitAudioContext)();
  source=audioCtx.createMediaStreamSource(stream);analyser=audioCtx.createAnalyser();analyser.fftSize=4096;analyser.smoothingTimeConstant=.05;source.connect(analyser);
  running=true;$("#micBtn").disabled=true;$("#stopBtn").disabled=false;$("#statusPill").textContent="OUVINDO O INSTRUMENTO";loop();
 }catch(e){$("#verdict").textContent="Não foi possível acessar o microfone. Permita o acesso e tente novamente.";$("#statusPill").textContent="MICROFONE BLOQUEADO"}
}
function stopMic(){running=false;cancelAnimationFrame(raf);if(stream)stream.getTracks().forEach(t=>t.stop());if(audioCtx)audioCtx.close();$("#micBtn").disabled=false;$("#stopBtn").disabled=true;$("#statusPill").textContent="PAUSADO"}
function loop(){
 if(!running)return;
 const buf=new Float32Array(analyser.fftSize);analyser.getFloatTimeDomainData(buf);
 const r=pitchFromBuffer(buf,audioCtx.sampleRate);
 if(r.pitch>0&&r.pitch<1200&&r.confidence>.12)update(r.pitch,r.confidence);
 raf=requestAnimationFrame(loop);
}
function update(pitch,conf){
 const n=findTarget(pitch),cents=Math.max(-50,Math.min(50,n.cents));
 $("#note").textContent=n.name;$("#frequency").textContent=pitch.toFixed(1)+" Hz";$("#targetNote").textContent=n.name;$("#targetHz").textContent=n.freq.toFixed(1)+" Hz";
 $("#needle").style.transform="translateX(-50%) rotate("+(cents/50*45)+"deg)";
 $("#confidenceBar").style.width=(conf*100)+"%";$("#confidenceText").textContent=Math.round(conf*100)+"%";
 const ok=Math.abs(cents)<=5,close=Math.abs(cents)<=18;
 $("#statusPill").textContent=ok?"AFINAÇÃO PERFEITA":close?"QUASE LÁ":cents<0?"MUITO GRAVE":"MUITO AGUDO";
 $("#statusPill").style.color=ok?"#39ffb0":"#ffd36b";
 $("#verdict").textContent=ok?"✓ Está afinado! Pode seguir.":cents<0?"↑ Suba a afinação — "+Math.abs(cents).toFixed(1)+" cents abaixo do alvo":"↓ Baixe a afinação — "+Math.abs(cents).toFixed(1)+" cents acima do alvo";
 if(instruments[current].tuning){const idx=instruments[current].tuning.findIndex(x=>x[0]===n.name);if(idx>=0)highlight(idx)}
}
$("#micBtn").onclick=startMic;$("#stopBtn").onclick=stopMic;renderInstruments();renderTuning();