/* LiveKit transport adapter for the advanced production UI.
 * Preserves the existing room, PIN, waiting-room, chat and host controls while
 * moving audio/video/screen media to LiveKit. */
(()=>{
  if(!window.LivekitClient||typeof start!=='function')return;
  const originalStart=start,originalLeave=leave;
  let lkRoom=null,connecting=false,currentMode='audio',canPublish=true,cameraOn=false,screenOn=false,prejoinMic=localStorage.getItem('cnetPrejoinMic')!=='0',prejoinCamera=localStorage.getItem('cnetPrejoinCamera')==='1',gridSize=Number(localStorage.getItem('cnetGridSize')||8),gridPage=0;

  const style=document.createElement('style');
  style.textContent=`
    #cnetMediaMode{display:grid;gap:7px;margin:12px 0;padding:12px;border:1px solid rgba(69,190,255,.28);border-radius:14px;background:rgba(8,28,54,.7)}
    #cnetMediaMode label{font-weight:700} #cnetMediaMode select{width:100%;padding:11px;border-radius:10px;background:#071a31;color:#fff;border:1px solid #2e76aa} #cnetPrejoinDevices{display:flex;gap:8px;flex-wrap:wrap} #cnetPrejoinDevices button{flex:1;min-width:135px;padding:10px;border-radius:10px;background:#0a2440;color:#fff;border:1px solid #2e76aa} #cnetJoinVerified{color:#9edbff;font-size:13px}
    #cnetLiveTier{font-size:12px;color:#9edbff} #cnetGridToolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0} #cnetGridToolbar select,#cnetGridToolbar button{padding:7px 10px;border-radius:9px;background:#0a2440;color:#fff;border:1px solid #2e76aa} #cnetGridPage{font-size:12px;color:#b9dcf7} #livekitVideoGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,260px));justify-content:start;align-items:start;gap:12px;margin:14px 0}
    .lk-video{position:relative;width:100%;aspect-ratio:4/3;border-radius:16px;overflow:hidden;background:#050b13;border:1px solid #21496d}
    .lk-placeholder{position:absolute;inset:0;display:grid;place-items:center;font-size:42px;font-weight:800;color:#7ccfff;background:linear-gradient(145deg,#0b2744,#06111e)} .lk-video video{width:100%!important;height:100%!important;min-width:0!important;min-height:0!important;max-width:100%!important;max-height:100%!important;object-fit:contain!important;display:block!important;transform:none!important}.lk-video span{position:absolute;left:9px;bottom:8px;padding:4px 8px;border-radius:8px;background:#0009;color:#fff;font-size:12px}
    .lk-media-btn[disabled]{opacity:.45;cursor:not-allowed}
    .controls{display:flex!important;flex-wrap:nowrap!important;align-items:center!important;justify-content:center!important;gap:6px!important;overflow-x:auto!important;padding:8px!important;max-height:64px}
    .controls>button{flex:0 0 auto!important;white-space:nowrap;padding:8px 11px!important;font-size:13px!important}.cnet-tool-panel{position:fixed;z-index:1200;bottom:76px;right:18px;display:none;min-width:220px;padding:10px;border:1px solid #285778;border-radius:14px;background:#07182a;box-shadow:0 18px 48px #0008}.cnet-tool-panel.open{display:grid;gap:7px}.cnet-tool-panel button{width:100%;text-align:left;padding:10px;border-radius:9px}
    #cnetReactionPanel{grid-template-columns:repeat(3,1fr);min-width:180px}#cnetReactionPanel button{text-align:center;font-size:23px}
    #cnetParticipantDrawer{position:fixed;z-index:1190;top:0;right:0;bottom:0;width:min(360px,92vw);display:none;background:#07182a;border-left:1px solid #285778;box-shadow:-18px 0 48px #0008;padding:16px;overflow:auto}#cnetParticipantDrawer.open{display:block}#cnetParticipantDrawer header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}#cnetParticipantDrawer #people{display:grid!important;gap:8px!important}#cnetParticipantDrawer .person{min-height:0!important;padding:10px!important}
    #cnetReactionStage{position:fixed;z-index:1300;left:50%;top:18%;transform:translateX(-50%);pointer-events:none}.cnet-reaction-pop{font-size:42px;animation:cnetReact 2.4s ease forwards;text-align:center;text-shadow:0 4px 12px #000}.cnet-reaction-pop small{display:block;font-size:12px;color:#fff}@keyframes cnetReact{0%{opacity:0;transform:translateY(20px) scale(.7)}20%{opacity:1;transform:none}100%{opacity:0;transform:translateY(-70px) scale(1.2)}}
    @media(max-width:760px){.controls{justify-content:flex-start!important}.controls>button{font-size:0!important}.controls>button::first-letter{font-size:18px}#livekitVideoGrid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.lk-video{aspect-ratio:3/4}}
  `;
  document.head.append(style);

  const lobbyButton=document.querySelector('#lobby button[type="submit"],#lobby #joinBtn,#lobby #startBtn')||document.querySelector('#lobby button');
  const modeBox=document.createElement('div'); modeBox.id='cnetMediaMode';
  modeBox.innerHTML='<label id="cnetSessionLabel" for="cnetModeSelect">Session type — केवल Host तय करेगा</label><select id="cnetModeSelect"><option value="video">Meeting — Audio/Video controls सहित</option><option value="webinar">Webinar — Host controlled audience</option></select><small id="cnetLiveTier">Capacity 10 / 20 / 30 / 50 / 100 / 1000 selectable • उपयोग server performance के अनुसार</small><small id="cnetJoinVerified">Invitation से session type अपने-आप निर्धारित होगा।</small><div id="cnetPrejoinDevices"><button type="button" id="cnetPrejoinMic">🎙️ Mic on</button><button type="button" id="cnetPrejoinCamera">📹 Camera off</button></div>';
  lobbyButton?.parentNode?.insertBefore(modeBox,lobbyButton);

  const prejoinMicBtn=modeBox.querySelector('#cnetPrejoinMic'),prejoinCameraBtn=modeBox.querySelector('#cnetPrejoinCamera');
  const refreshPrejoin=()=>{prejoinMicBtn.textContent=prejoinMic?'🎙️ Mic on':'🔇 Mic off';prejoinCameraBtn.textContent=prejoinCamera?'📷 Camera on':'📹 Camera off'};
  prejoinMicBtn.onclick=()=>{prejoinMic=!prejoinMic;localStorage.setItem('cnetPrejoinMic',prejoinMic?'1':'0');refreshPrejoin()};
  prejoinCameraBtn.onclick=()=>{prejoinCamera=!prejoinCamera;localStorage.setItem('cnetPrejoinCamera',prejoinCamera?'1':'0');refreshPrejoin()};refreshPrejoin();

  const videoGrid=document.createElement('section');videoGrid.id='livekitVideoGrid';videoGrid.setAttribute('aria-label','Live video participants');
  document.querySelector('#people')?.parentNode?.insertBefore(videoGrid,document.querySelector('#people'));
  const gridToolbar=document.createElement('div');gridToolbar.id='cnetGridToolbar';gridToolbar.innerHTML='<label for="gridSizeSelect">Grid</label><select id="gridSizeSelect"><option value="4">4</option><option value="8">8</option><option value="16">16</option><option value="25">25</option></select><button type="button" id="gridPrev">‹ Previous</button><span id="cnetGridPage">Page 1 / 1</span><button type="button" id="gridNext">Next ›</button>';
  videoGrid.parentNode?.insertBefore(gridToolbar,videoGrid);const gridSelect=gridToolbar.querySelector('#gridSizeSelect');gridSelect.value=String([4,8,16,25].includes(gridSize)?gridSize:8);

  const controls=document.querySelector('.controls');
  const cameraBtn=document.createElement('button');cameraBtn.type='button';cameraBtn.id='cameraBtn';cameraBtn.className='lk-media-btn';cameraBtn.textContent='📹 Camera';
  const screenBtn=document.createElement('button');screenBtn.type='button';screenBtn.id='screenBtn';screenBtn.className='lk-media-btn';screenBtn.textContent='🖥️ Share';
  controls?.insertBefore(cameraBtn,document.querySelector('#micBtn')?.nextSibling||null);
  controls?.insertBefore(screenBtn,cameraBtn.nextSibling);

  const panel=(id)=>{const x=document.createElement('div');x.id=id;x.className='cnet-tool-panel';document.body.append(x);return x};
  const makeButton=(id,text)=>{const x=document.createElement('button');x.type='button';x.id=id;x.textContent=text;return x};
  const adminBtn=makeButton('adminToolsBtn','🛡️ Admin Tools'),reactionBtn=makeButton('reactionBtn','😊 Reactions'),participantsBtn=makeButton('participantsBtn','👥 Participants 0'),moreBtn=makeButton('moreBtn','⋯ More');
  const adminPanel=panel('cnetAdminPanel'),reactionPanel=panel('cnetReactionPanel'),morePanel=panel('cnetMorePanel');
  const participantDrawer=document.createElement('aside');participantDrawer.id='cnetParticipantDrawer';participantDrawer.innerHTML='<header><strong>Participants</strong><button type="button" id="participantClose">×</button></header>';document.body.append(participantDrawer);
  const reactionStage=document.createElement('div');reactionStage.id='cnetReactionStage';document.body.append(reactionStage);
  const legacyPeople=document.querySelector('#people');if(legacyPeople)participantDrawer.append(legacyPeople);
  [screenBtn,document.querySelector('#waitingBtn'),document.querySelector('#muteAllBtn'),document.querySelector('#unmuteAllBtn'),document.querySelector('#lockBtn')].filter(Boolean).forEach(x=>adminPanel.append(x));
  [document.querySelector('#renameBtn'),document.querySelector('#photoBtn')].filter(Boolean).forEach(x=>morePanel.append(x));
  const chatBtn=document.querySelector('#chatBtn'),handBtn=document.querySelector('#handBtn'),endBtn=document.querySelector('#endBtn');
  controls?.replaceChildren(document.querySelector('#micBtn'),cameraBtn,adminBtn,chatBtn,reactionBtn,participantsBtn,moreBtn,endBtn);
  const closePanels=except=>[adminPanel,reactionPanel,morePanel].forEach(x=>{if(x!==except)x.classList.remove('open')});
  const togglePanel=x=>{const opening=!x.classList.contains('open');closePanels(x);x.classList.toggle('open',opening)};
  adminBtn.onclick=()=>togglePanel(adminPanel);reactionBtn.onclick=()=>togglePanel(reactionPanel);moreBtn.onclick=()=>togglePanel(morePanel);
  participantsBtn.onclick=()=>participantDrawer.classList.toggle('open');participantDrawer.querySelector('#participantClose').onclick=()=>participantDrawer.classList.remove('open');
  const updateParticipantCount=()=>{const count=legacyPeople?.querySelectorAll('.person').length||0;participantsBtn.textContent=`👥 Participants ${count}`;participantDrawer.querySelector('strong').textContent=`Participants (${count})`};
  if(legacyPeople)new MutationObserver(updateParticipantCount).observe(legacyPeople,{childList:true,subtree:true});updateParticipantCount();
  const showReaction=(emoji,name='Participant')=>{const pop=document.createElement('div');pop.className='cnet-reaction-pop';pop.innerHTML=`${emoji}<small>${name}</small>`;reactionStage.append(pop);setTimeout(()=>pop.remove(),2500)};
  const sendReaction=async emoji=>{showReaction(emoji,'आप');try{const bytes=new TextEncoder().encode(JSON.stringify({type:'reaction',emoji,name:lkRoom?.localParticipant?.name||'Participant'}));await lkRoom?.localParticipant?.publishData(bytes,{reliable:true,topic:'reactions'})}catch{}};
  ['👍','👏','❤️','🎉','😂','✋'].forEach(emoji=>{const b=makeButton('',emoji);b.onclick=()=>{sendReaction(emoji);reactionPanel.classList.remove('open');if(emoji==='✋')handBtn?.click()};reactionPanel.append(b)});

  const paginate=()=>{
    const tiles=[...videoGrid.querySelectorAll('.lk-video')],pages=Math.max(1,Math.ceil(tiles.length/gridSize));gridPage=Math.min(gridPage,pages-1);
    tiles.forEach((tile,index)=>tile.hidden=index<gridPage*gridSize||index>=(gridPage+1)*gridSize);
    gridToolbar.querySelector('#cnetGridPage').textContent=`Page ${gridPage+1} / ${pages} • ${tiles.length} participant`;
    gridToolbar.querySelector('#gridPrev').disabled=gridPage===0;gridToolbar.querySelector('#gridNext').disabled=gridPage>=pages-1;
    const columns=gridSize<=4?2:gridSize<=16?4:5;videoGrid.style.gridTemplateColumns=`repeat(${columns},minmax(0,1fr))`;
  };
  gridSelect.onchange=()=>{gridSize=Number(gridSelect.value);gridPage=0;localStorage.setItem('cnetGridSize',String(gridSize));paginate()};
  gridToolbar.querySelector('#gridPrev').onclick=()=>{if(gridPage>0){gridPage--;paginate()}};
  gridToolbar.querySelector('#gridNext').onclick=()=>{gridPage++;paginate()};
  const participantTile=(participant,self=false)=>{
    const identity=participant?.identity||'local',selector=`[data-participant="${CSS.escape(identity)}"]`;
    let wrap=videoGrid.querySelector(selector);if(wrap)return wrap;
    wrap=document.createElement('div');wrap.className='lk-video';wrap.dataset.participant=identity;
    const person=(typeof lastPeople!=='undefined'?lastPeople:[]).find(p=>p.id===identity),avatar=person?.avatar;
    const placeholder=document.createElement('div');placeholder.className='lk-placeholder';
    if(avatar){const img=document.createElement('img');img.src=`avatar.php?room=${encodeURIComponent(room)}&id=${encodeURIComponent(identity)}&v=${encodeURIComponent(avatar)}`;img.alt=`${participant?.name||person?.name||'Participant'} profile photo`;img.style.cssText='width:100%;height:100%;object-fit:cover';placeholder.append(img)}else placeholder.textContent=(participant?.name||person?.name||'P').slice(0,1).toUpperCase();
    const label=document.createElement('span');label.textContent=self?'आप (Self View)':participant?.name||'Participant';
    wrap.append(placeholder,label);videoGrid.append(wrap);paginate();return wrap;
  };
  const ensureTilePlaceholder=wrap=>{if(!wrap||wrap.querySelector('video')||wrap.querySelector('.lk-placeholder'))return;const identity=wrap.dataset.participant||'',person=(typeof lastPeople!=='undefined'?lastPeople:[]).find(p=>p.id===identity);const p=document.createElement('div');p.className='lk-placeholder';if(person?.avatar){const img=document.createElement('img');img.src=`avatar.php?room=${encodeURIComponent(room)}&id=${encodeURIComponent(identity)}&v=${encodeURIComponent(person.avatar)}`;img.alt=`${person.name||'Participant'} profile photo`;img.style.cssText='width:100%;height:100%;object-fit:cover';p.append(img)}else p.textContent=(person?.name||'P').slice(0,1).toUpperCase();wrap.prepend(p)};
  const syncTileAvatars=()=>{videoGrid.querySelectorAll('.lk-video').forEach(wrap=>{if(wrap.querySelector('video'))return;wrap.querySelector('.lk-placeholder')?.remove();ensureTilePlaceholder(wrap)})};
  const removeTrack=track=>track.detach().forEach(el=>{const wrap=el.closest('.lk-video');el.remove();if(wrap){wrap.classList.remove('has-video');ensureTilePlaceholder(wrap)}paginate()});
  const attachTrack=(track,participant)=>{
    if(track.kind==='audio'){const el=track.attach();el.autoplay=true;el.dataset.livekit='1';document.querySelector('#audioBox')?.append(el);return}
    if(track.kind!=='video')return;
    const wrap=participantTile(participant);wrap.querySelector('video')?.remove();wrap.querySelector('.lk-placeholder')?.remove();wrap.classList.add('has-video');
    const el=track.attach();el.autoplay=true;el.playsInline=true;wrap.prepend(el);paginate();
  };
  const attachLocalCamera=track=>{
    const participant=lkRoom?.localParticipant,wrap=participantTile(participant,true);wrap.querySelector('video')?.remove();
    if(!track){paginate();return}wrap.querySelector('.lk-placeholder')?.remove();wrap.classList.add('has-video');
    const el=track.attach();el.muted=true;el.autoplay=true;el.playsInline=true;wrap.prepend(el);videoGrid.prepend(wrap);paginate();
  };
  const updateControls=()=>{
    const webinarAudience=currentMode==='webinar'&&!host;
    cameraBtn.disabled=!lkRoom||!canPublish||currentMode==='audio'||webinarAudience;
    screenBtn.disabled=!lkRoom||!canPublish||currentMode==='audio'||webinarAudience;
    const lobbyVisible=!document.querySelector('#lobby')?.classList.contains('hidden'),creating=typeof mode!=='undefined'&&mode==='create';
    modeBox.style.display=lobbyVisible?'grid':'none';
    modeBox.querySelector('#cnetSessionLabel').hidden=!creating;modeBox.querySelector('#cnetModeSelect').hidden=!creating;modeBox.querySelector('#cnetLiveTier').hidden=!creating;modeBox.querySelector('#cnetJoinVerified').hidden=creating;
    const enter=document.querySelector('#enterBtn');if(enter&&!creating)enter.textContent='Join Meeting';
    const videoMode=currentMode!=='audio';videoGrid.hidden=!videoMode;gridToolbar.hidden=!videoMode;adminBtn.classList.toggle('hidden',!host);
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
      lkRoom.on(RoomEvent.ParticipantConnected,participant=>participantTile(participant));
      lkRoom.on(RoomEvent.ParticipantDisconnected,participant=>{videoGrid.querySelector(`[data-participant="${CSS.escape(participant.identity)}"]`)?.remove();paginate()});
      lkRoom.on(RoomEvent.DataReceived,(payload,participant)=>{try{const data=JSON.parse(new TextDecoder().decode(payload));if(data.type==='reaction')showReaction(data.emoji,participant?.name||data.name)}catch{}});
      lkRoom.on(RoomEvent.Disconnected,()=>{const s=document.querySelector('#status');if(s)s.textContent='LiveKit reconnect हो रहा है…'});
      await lkRoom.connect(auth.url,auth.token);
      stream?.getTracks().forEach(t=>t.stop());stream=null;
      if(canPublish){await lkRoom.localParticipant.setMicrophoneEnabled(prejoinMic&&!muted);muted=!prejoinMic;if(currentMode==='video'&&prejoinCamera){const publication=await lkRoom.localParticipant.setCameraEnabled(true);cameraOn=true;const localTrack=publication?.track||Array.from(lkRoom.localParticipant.cameraTrackPublications?.values?.()||[]).find(p=>p.track)?.track;attachLocalCamera(localTrack)}}
      participantTile(lkRoom.localParticipant,true);lkRoom.remoteParticipants.forEach(participant=>participantTile(participant));
      peers={};updateControls();paginate();
      const s=document.querySelector('#status');if(s)s.dataset.transport='livekit';
    }finally{connecting=false}
  }

  makePeer=async()=>null;signal=async()=>{};drop=()=>{};
  start=async function(){try{await connectTransport();await originalStart()}catch(e){const x=document.querySelector('#lobbyError');if(x)x.textContent=e.message||'LiveKit connect नहीं हुआ'}};
  recoverAudio=async function(){if(!lkRoom||!canPublish)return;try{await lkRoom.localParticipant.setMicrophoneEnabled(!muted)}catch{}};

  const mic=document.querySelector('#micBtn');
  if(mic)mic.onclick=async()=>{if(!canPublish)return;muted=!muted;await lkRoom?.localParticipant.setMicrophoneEnabled(!muted);api('self',payload({field:'muted',value:muted}));mic.textContent=muted?'🔇 Unmute':'🎙️ Mute'};
  cameraBtn.onclick=async()=>{if(!lkRoom||!canPublish)return;cameraOn=!cameraOn;try{const publication=await lkRoom.localParticipant.setCameraEnabled(cameraOn);cameraBtn.textContent=cameraOn?'📷 Camera off':'📹 Camera';const wrap=participantTile(lkRoom.localParticipant,true);if(cameraOn){const track=publication?.track||Array.from(lkRoom.localParticipant.cameraTrackPublications?.values?.()||[]).find(p=>p.track)?.track;attachLocalCamera(track)}else{wrap.querySelector('video')?.remove();wrap.classList.remove('has-video');ensureTilePlaceholder(wrap);paginate()}}catch(e){cameraOn=false;cameraBtn.textContent='📹 Camera';toast?.('Camera permission दीजिए।')}};
  screenBtn.onclick=async()=>{if(!lkRoom||!canPublish)return;screenOn=!screenOn;try{await lkRoom.localParticipant.setScreenShareEnabled(screenOn);screenBtn.textContent=screenOn?'⏹ Stop share':'🖥️ Share'}catch(e){screenOn=false;screenBtn.textContent='🖥️ Share'}};

  const shareInfo=document.querySelector('#shareInfo');if(shareInfo)shareInfo.onclick=()=>{if(!lkRoom)lobby('create');else screenBtn.click()};
  leave=async function(...args){try{await lkRoom?.disconnect()}catch{}lkRoom=null;gridPage=0;videoGrid.replaceChildren();participantDrawer.classList.remove('open');closePanels();return originalLeave(...args)};
  window.addEventListener('beforeunload',()=>lkRoom?.disconnect());
  document.querySelector('#createBtn')?.addEventListener('click',()=>setTimeout(updateControls,0));
  document.querySelector('#joinOpen')?.addEventListener('click',()=>setTimeout(updateControls,0));
  const lobbyPanel=document.querySelector('#lobby');if(lobbyPanel)new MutationObserver(updateControls).observe(lobbyPanel,{attributes:true,attributeFilter:['class']});
  if(legacyPeople)new MutationObserver(()=>setTimeout(syncTileAvatars,0)).observe(legacyPeople,{childList:true,subtree:true});
  updateControls();document.documentElement.dataset.livekitAdapter='professional-join-avatar-ready';
})();
