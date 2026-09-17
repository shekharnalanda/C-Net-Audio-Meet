let deferredInstallPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferredInstallPrompt=e});
document.querySelectorAll('.install-app').forEach(btn=>btn.addEventListener('click',async()=>{
  if(deferredInstallPrompt){deferredInstallPrompt.prompt();await deferredInstallPrompt.userChoice;deferredInstallPrompt=null}
  else toast('Browser menu में “Install app” या “Add to Home screen” चुनिए।');
}));
if('serviceWorker' in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));
const inviteUrl=()=>location.origin+location.pathname+'?room='+room;
const readableMeetingId=id=>(id||'').replace(/(.{3})(?=.)/g,'$1 ').trim();
const readableDateTime=(date,time)=>{
  const value=new Date(`${date}T${time||'00:00'}:00`);
  if(Number.isNaN(value.getTime()))return `${date||''} ${time||''}`.trim();
  return new Intl.DateTimeFormat('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:true,timeZone:'Asia/Kolkata'}).format(value)+' India';
};
const scheduledInvite=a=>location.origin+location.pathname+'?room='+a.room;
const invitationText=a=>`${a.host||'SUJIT SHEKHAR'} is inviting you to a scheduled C-Net meeting.\n\nTopic: ${a.title||'C-Net Meeting'}\nTime: ${readableDateTime(a.date,a.time)}\n\nJoin C-Net Meeting\n${scheduledInvite(a)}\n\nMeeting ID: ${readableMeetingId(a.room)}${a.agenda?`\nAgenda: ${a.agenda}`:''}\n\n---\nOpen the meeting link above to join in one click.`;
document.querySelector('#shareBtn')?.addEventListener('click',async()=>{
  const saved=schedules().find(a=>a.room===room);
  const text=saved?invitationText(saved):`C-Net Meet में जुड़िए।\n\nJoin C-Net Meeting\n${inviteUrl()}\n\nMeeting ID: ${readableMeetingId(room)}`;
  const data={title:saved?.title||'C-Net Meet',text};
  if(navigator.share)await navigator.share(data);else navigator.clipboard.writeText(text).then(()=>toast('पूरा invitation copy हो गया।'));
});
document.querySelector('#copyBtn').onclick=()=>{
  const saved=schedules().find(a=>a.room===room);
  const text=saved?invitationText(saved):`C-Net Meet में जुड़िए।\n\nJoin C-Net Meeting\n${inviteUrl()}\n\nMeeting ID: ${readableMeetingId(room)}`;
  navigator.clipboard.writeText(text).then(()=>toast('पूरा invitation copy हो गया।'));
};

const sortedSchedules=()=>schedules().sort((a,b)=>(a.date+a.time).localeCompare(b.date+b.time));
const copyScheduled=i=>{const a=sortedSchedules()[i];if(!a?.room)return;navigator.clipboard.writeText(invitationText(a)).then(()=>toast('पूरा invitation copy हो गया।'))};
const shareScheduled=async i=>{const a=sortedSchedules()[i];if(!a?.room)return;const data={title:a.title,text:invitationText(a)};if(navigator.share)await navigator.share(data);else copyScheduled(i)};
window.copyScheduled=copyScheduled;window.shareScheduled=shareScheduled;
drawSchedules=()=>{const all=sortedSchedules(),today=new Date().toISOString().slice(0,10);const card=(a,i)=>`<div class="agenda-item"><time>${a.time}</time><div><b>${esc(a.title)}</b><small>${esc(a.agenda||'Audio meeting')} • ID: ${a.room||'—'}</small><div class="schedule-actions"><button onclick="copyScheduled(${i})">🔗 Copy Invite</button><button onclick="shareScheduled(${i})">📤 Share</button></div></div></div>`;document.querySelector('#todayMeetings').innerHTML=all.filter(x=>x.date===today).map(a=>card(a,all.indexOf(a))).join('')||'<div class="empty"><span>☂</span>आज कोई meeting scheduled नहीं है।</div>';document.querySelector('#allMeetings').innerHTML=all.map((a,i)=>`<div class="meeting-row"><div><b>${esc(a.title)}</b><p>${a.date} • ${a.time} • Meeting ID: ${a.room||'—'}</p><div class="schedule-actions"><button onclick="copyScheduled(${i})">🔗 Copy Invite</button><button onclick="shareScheduled(${i})">📤 Share</button></div></div><button onclick="startScheduled(${i})">Start</button></div>`).join('')||'<div class="empty">अभी कोई meeting scheduled नहीं है।</div>';document.querySelector('#schedulerMount').innerHTML=document.querySelector('#allMeetings').innerHTML};
document.querySelector('#scheduleForm').onsubmit=async e=>{e.preventDefault();const item={title:document.querySelector('#scheduleTitle').value.trim(),date:document.querySelector('#scheduleDate').value,time:document.querySelector('#scheduleTime').value,agenda:document.querySelector('#scheduleAgenda').value.trim(),waitingRoom:document.querySelector('#scheduleWaiting').checked,host:localStorage.getItem('cnetName')||'SUJIT SHEKHAR'};const r=await api('reserve',item);if(!r.ok){toast(r.error||'Schedule नहीं बन सकी।');return}item.room=r.room;item.hostKey=r.hostKey;saveHostKey(item.room,item.hostKey);const list=schedules();list.push(item);localStorage.setItem('cnetSchedules',JSON.stringify(list));e.target.reset();document.querySelector('#scheduleModal').classList.add('hidden');drawSchedules();toast('Meeting तैयार है—Copy Invite से पूरा संदेश भेजिए।')};
window.startScheduled=i=>{const a=sortedSchedules()[i];if(!a)return;withHostPin(()=>{mode='scheduled-host';room=a.room;document.querySelector('#roomInput').value=room;document.querySelector('#roomField').classList.add('hidden');document.querySelector('#lobbyTitle').textContent='Scheduled meeting शुरू कीजिए';document.querySelector('#lobby').classList.remove('hidden');document.querySelector('#lobby').dataset.hostKey=a.hostKey||'';document.querySelector('#nameInput').value=localStorage.getItem('cnetName')||''})};
const originalEnter=document.querySelector('#enterBtn').onclick;
document.querySelector('#enterBtn').onclick=async()=>{if(mode!=='scheduled-host')return originalEnter();try{const name=document.querySelector('#nameInput').value.trim();if(name.length<2)throw Error('अपना नाम लिखिए');localStorage.setItem('cnetName',name);const r=await api('join',{room,name,clientKey,hostKey:document.querySelector('#lobby').dataset.hostKey});if(!r.ok)throw Error(r.error);pid=r.pid;token=r.token;host=!!r.host;await getMic();start()}catch(e){document.querySelector('#lobbyError').textContent=e.message||'Meeting शुरू नहीं हुई'}};
drawSchedules();

/* Permanent quick meeting 9334779133 */
(()=>{
  const permanentRoom='9334779133',lobbyBox=document.querySelector('#lobby'),enter=document.querySelector('#enterBtn');
  const invite=()=>location.origin+location.pathname+'?room='+permanentRoom;
  const nav=document.querySelector('#sidebar nav');
  if(nav&&!document.querySelector('#permanentMeetingStart')){
    const startBtn=document.createElement('button');
    startBtn.type='button';startBtn.id='permanentMeetingStart';startBtn.className='nav permanent-meeting-action';
    startBtn.innerHTML='<span>▶</span><span><b>Permanent Meeting</b><small>ID: 933 477 9133</small></span>';
    const copyBtn=document.createElement('button');
    copyBtn.type='button';copyBtn.id='permanentMeetingCopy';copyBtn.className='nav permanent-meeting-copy';
    copyBtn.innerHTML='<span>🔗</span><span><b>Copy Invitation</b><small>Permanent link</small></span>';
    nav.append(startBtn,copyBtn);
    startBtn.onclick=()=>withHostPin(()=>{
      lobby('create');room=permanentRoom;document.querySelector('#roomInput').value=permanentRoom;
      lobbyBox.dataset.permanent='host';document.querySelector('#lobbyTitle').textContent='Permanent meeting शुरू कीजिए';
    });
    copyBtn.onclick=()=>navigator.clipboard.writeText(`C-Net Meet में जुड़िए।\n\nJoin C-Net Meeting\n${invite()}\n\nMeeting ID: 933 477 9133`).then(()=>toast('Permanent meeting invitation copy हो गया।'));
  }
  const linked=new URLSearchParams(location.search).get('room')?.replace(/\W/g,'')===permanentRoom;
  if(linked)lobbyBox.dataset.permanent='guest';
  const previousEnter=enter.onclick;
  enter.onclick=async()=>{
    const permanent=lobbyBox.dataset.permanent;
    if(!permanent)return previousEnter();
    try{
      const name=document.querySelector('#nameInput').value.trim();
      if(name.length<2)throw Error('अपना नाम लिखिए');
      localStorage.setItem('cnetName',name);
      if(permanent==='host'&&!await api('host_status').then(x=>x.authorized))throw Error('Host PIN दोबारा सत्यापित कीजिए');
      let r;
      if(permanent==='host'){await getMic();r=await api('start_permanent',{name,clientKey})}
      else r=await api('join',{room:permanentRoom,name,clientKey});
      if(!r.ok)throw Error(r.error||'Permanent meeting उपलब्ध नहीं है');
      room=permanentRoom;if(r.hostKey)saveHostKey(room,r.hostKey);
      if(r.waiting){waitForHost(r);return}
      pid=r.pid;token=r.token;host=permanent==='host'||!!r.host;
      if(!stream)await getMic();start();
    }catch(e){document.querySelector('#lobbyError').textContent=e.message||'Meeting शुरू नहीं हुई'}
  };
  document.documentElement.dataset.permanentMeeting='9334779133-ready';
})();
