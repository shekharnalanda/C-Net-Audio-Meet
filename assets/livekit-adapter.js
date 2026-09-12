/* LiveKit transport adapter for the advanced production UI.
 * Loaded after app.js/addon.js so existing room, waiting-room, chat and host
 * controls remain unchanged while audio moves from mesh WebRTC to LiveKit. */
(()=>{
  if(!window.LivekitClient||typeof start!=='function')return;
  const originalStart=start,originalLeave=leave;
  let lkRoom=null,connecting=false;
  const attachAudio=track=>{const el=track.attach();el.autoplay=true;el.dataset.livekit='1';document.querySelector('#audioBox')?.append(el)};
  async function connectTransport(){
    if(lkRoom||connecting)return;connecting=true;
    try{
      const response=await fetch('livekit-token.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({room,name:document.querySelector('#nameInput')?.value?.trim()||'Member',pid,token})});
      const auth=await response.json();if(!auth.ok)throw Error(auth.error||'LiveKit authorization failed');
      const {Room,RoomEvent}=LivekitClient;lkRoom=new Room({adaptiveStream:true,dynacast:true});
      lkRoom.on(RoomEvent.TrackSubscribed,track=>track.kind==='audio'&&attachAudio(track));
      lkRoom.on(RoomEvent.TrackUnsubscribed,track=>track.detach().forEach(el=>el.remove()));
      lkRoom.on(RoomEvent.Disconnected,()=>{document.querySelector('#status').textContent='Audio reconnect हो रहा है…'});
      await lkRoom.connect(auth.url,auth.token);
      stream?.getTracks().forEach(t=>t.stop());stream=null;
      await lkRoom.localParticipant.setMicrophoneEnabled(!muted);
      window.peers={};
    }finally{connecting=false}
  }
  makePeer=async()=>null;signal=async()=>{};drop=()=>{};
  start=async function(){try{await connectTransport();originalStart()}catch(e){document.querySelector('#lobbyError').textContent=e.message||'LiveKit audio connect नहीं हुआ'}};
  recoverAudio=async function(){if(!lkRoom)return;try{await lkRoom.localParticipant.setMicrophoneEnabled(!muted)}catch{}}
  const mic=document.querySelector('#micBtn');if(mic)mic.onclick=async()=>{muted=!muted;await lkRoom?.localParticipant.setMicrophoneEnabled(!muted);api('self',payload({field:'muted',value:muted}));mic.textContent=muted?'🔇 Unmute':'🎙️ Mute'};
  leave=async function(...args){try{await lkRoom?.disconnect()}catch{}lkRoom=null;return originalLeave(...args)};
  window.addEventListener('beforeunload',()=>lkRoom?.disconnect());
  document.documentElement.dataset.livekitAdapter='ready';
})();
