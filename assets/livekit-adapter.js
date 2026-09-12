/* LiveKit transport adapter for the advanced production UI.
 * Preserves the existing room, PIN, waiting-room, chat and host controls while
 * moving audio/video/screen media to LiveKit. */
(()=>{
  if(!window.LivekitClient||typeof start!=='function')return;
  const originalStart=start,originalLeave=leave;
  let lkRoom=null,connecting=false,currentMode='audio',canPublish=true,cameraOn=false,screenOn=false;

  const style=document.createElement('style');
  style.textContent=`
    #cnetMediaMode{display:grid;gap:7px;margin:12px 0;padding:12px;border:1px solid rgba(69,190,255,.28);border-radius:14px;background:rgba(8,28,54,.7)}
    #cnetMediaMode label{font-weight:700} #cnetMediaMode select{width:100%;padding:11px;border-radius:10px;background:#071a31;color:#fff;border:1px solid #2e76aa}
    #cnetLiveTier{font-size:12px;color:#9edbff} #livekitVideoGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,260px));justify-content:start;align-items:start;gap:12px;margin:14px 0}
    .lk-video{position:relative;width:100%;aspect-ratio:4/3;border-radius:16px;overflow:hidden;background:#050b13;border:1px solid #21496d}
    .lk-video video{width:100%!important;height:100%!important;min-width:0!important;min-height:0!important;max-width:100%!important;max-height:100%!important;object-fit:contain!important;display:block!important;transform:none!important}.lk-video span{position:absolute;left:9px;bottom:8px;padding:4px 8px;border-radius:8px;background:#0009;color:#fff;font-size:12px}
    .lk-media-btn[disabled]{opacity:.45;cursor:not-allowed}
  `;
  document.head.append(style);

  const lobbyButton=document.querySelector('#lobby button[type="submit"],#lobby #joinBtn,#lobby #startBtn')||document.querySelector('#lobby button');
  const modeBox=document.createElement('div'); modeBox.id='cnetMediaMode';
  modeBox.innerHTML='<label for="cnetModeSelect">Meeting mode</label><select id="cnetModeSelect"><option value="audio">Audio Meeting</option><option value="video">Video Meeting</option><option value="webinar">Webinar — केवल host broadcast</option></select><small id="cnetLiveTier">Capacity 10 / 20 / 30 / 50 / 100 / 1000 selectable • उपयोग server performance के अनुसार</small>';
  lobbyButton?.parentNode?.insertBefore(modeBox,lobbyButton);

  const videoGrid=document.createElement('section');videoGrid.id='livekitVideoGrid';videoGrid.setAttribute('aria-label','Live video participants');
  document.querySelector('#people')?.parentNode?.insertBefore(videoGrid,document.querySelector('#people'));

  const controls=document.querySelector('.controls');
  const cameraBtn=document.createElement('button');cameraBtn.type='button';cameraBtn.id='cameraBtn';cameraBtn.className='lk-media-btn';cameraBtn.textContent='📹 Camera';
  const screenBtn=document.createElement('button');screenBtn.type='button';screenBtn.id='screenBtn';screenBtn.className='lk-media-btn';screenBtn.textContent='🖥️ Share';
  controls?.insertBefore(cameraBtn,document.querySelector('#micBtn')?.nextSibling||null);
  controls?.insertBefore(screenBtn,cameraBtn.nextSibling);

  const removeTrack=track=>track.detach().forEach(el=>{el.closest('.lk-video')?.remove();if(!el.closest('.lk-video'))el.remove()});
  const attachTrack=(track,participant)=>{
    if(track.kind==='audio'){const el=track.attach();el.autoplay=true;el.dataset.livekit='1';document.querySelector('#audioBox')?.append(el);return}
    if(track.kind!=='video')return;
    const wrap=document.createElement('div');wrap.className='lk-video';wrap.dataset.sid=track.sid||Math.random().toString(36);
    const el=track.attach();el.autoplay=true;el.playsInline=true;
    const label=document.createElement('span');label.textContent=participant?.name||'Participant';
    wrap.append(el,label);videoGrid.append(wrap);
  };
  const attachLocalCamera=track=>{
    videoGrid.querySelector('[data-local="1"]')?.remove();if(!track)return;
    const wrap=document.createElement('div');wrap.className='lk-video';wrap.dataset.local='1';
    const el=track.attach();el.muted=true;el.autoplay=true;el.playsInline=true;
    const label=document.createElement('span');label.textContent='आप (Self View)';wrap.append(el,label);videoGrid.prepend(wrap);
  };
  const updateControls=()=>{
    const webinarAudience=currentMode==='webinar'&&!host;
    cameraBtn.disabled=!lkRoom||!canPublish||currentMode==='audio'||webinarAudience;
    screenBtn.disabled=!lkRoom||!canPublish||currentMode==='audio'||webinarAudience;
    modeBox.style.display=document.querySelector('#lobby')?.classList.contains('hidden')?'none':'grid';
  };

  async function connectTransport(){
    if(lkRoom||connecting)return;connecting=true;
    try{
      const requested=document.querySelector('#cnetModeSelect')?.value||'audio';
      const response=await fetch('livekit-token.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({room,name:document.querySelector('#nameInput')?.value?.trim()||'Member',pid,token,mode:requested})});
      const auth=await response.json();if(!auth.ok)throw Error(auth.error||'LiveKit authorization failed');
      currentMode=auth.mode||'audio';canPublish=auth.role!=='audience'||currentMode!=='webinar';
      const {Room,RoomEvent}=LivekitClient;
      lkRoom=new Room({adaptiveStream:true,dynacast:true,publishDefaults:{simulcast:true,videoCodec:'vp8',dtx:true,red:true}});
      lkRoom.on(RoomEvent.TrackSubscribed,(track,_publication,participant)=>attachTrack(track,participant));
      lkRoom.on(RoomEvent.TrackUnsubscribed,track=>removeTrack(track));
      lkRoom.on(RoomEvent.Disconnected,()=>{const s=document.querySelector('#status');if(s)s.textContent='LiveKit reconnect हो रहा है…'});
      await lkRoom.connect(auth.url,auth.token);
      stream?.getTracks().forEach(t=>t.stop());stream=null;
      if(canPublish)await lkRoom.localParticipant.setMicrophoneEnabled(!muted);
      peers={};updateControls();
      const s=document.querySelector('#status');if(s)s.dataset.transport='livekit';
    }finally{connecting=false}
  }

  makePeer=async()=>null;signal=async()=>{};drop=()=>{};
  start=async function(){try{await connectTransport();await originalStart()}catch(e){const x=document.querySelector('#lobbyError');if(x)x.textContent=e.message||'LiveKit connect नहीं हुआ'}};
  recoverAudio=async function(){if(!lkRoom||!canPublish)return;try{await lkRoom.localParticipant.setMicrophoneEnabled(!muted)}catch{}};

  const mic=document.querySelector('#micBtn');
  if(mic)mic.onclick=async()=>{if(!canPublish)return;muted=!muted;await lkRoom?.localParticipant.setMicrophoneEnabled(!muted);api('self',payload({field:'muted',value:muted}));mic.textContent=muted?'🔇 Unmute':'🎙️ Mute'};
  cameraBtn.onclick=async()=>{if(!lkRoom||!canPublish)return;cameraOn=!cameraOn;try{const publication=await lkRoom.localParticipant.setCameraEnabled(cameraOn);cameraBtn.textContent=cameraOn?'📷 Camera off':'📹 Camera';if(cameraOn){const track=publication?.track||Array.from(lkRoom.localParticipant.cameraTrackPublications?.values?.()||[]).find(p=>p.track)?.track;attachLocalCamera(track)}else videoGrid.querySelector('[data-local="1"]')?.remove()}catch(e){cameraOn=false;cameraBtn.textContent='📹 Camera';toast?.('Camera permission दीजिए।')}};
  screenBtn.onclick=async()=>{if(!lkRoom||!canPublish)return;screenOn=!screenOn;try{await lkRoom.localParticipant.setScreenShareEnabled(screenOn);screenBtn.textContent=screenOn?'⏹ Stop share':'🖥️ Share'}catch(e){screenOn=false;screenBtn.textContent='🖥️ Share'}};

  const shareInfo=document.querySelector('#shareInfo');if(shareInfo)shareInfo.onclick=()=>{if(!lkRoom)lobby('create');else screenBtn.click()};
  leave=async function(...args){try{await lkRoom?.disconnect()}catch{}lkRoom=null;videoGrid.replaceChildren();return originalLeave(...args)};
  window.addEventListener('beforeunload',()=>lkRoom?.disconnect());
  document.querySelector('#createBtn')?.addEventListener('click',()=>setTimeout(updateControls,0));
  document.querySelector('#joinOpen')?.addEventListener('click',()=>setTimeout(updateControls,0));
  const lobbyPanel=document.querySelector('#lobby');if(lobbyPanel)new MutationObserver(updateControls).observe(lobbyPanel,{attributes:true,attributeFilter:['class']});
  updateControls();document.documentElement.dataset.livekitAdapter='video-tile-selfview-ready';
})();
