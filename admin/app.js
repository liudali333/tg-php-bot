'use strict';
let config = null, selection = {type:'page',id:'home'}, csrf = '', changed = false;
const $ = id => document.getElementById(id);
function el(tag, attrs={}, txt) { const e=document.createElement(tag); for(const [k,v] of Object.entries(attrs)) { if(k==='class') e.className=v; else e.setAttribute(k,v); } if(txt!==undefined) e.textContent=txt; return e; }
function announce(msg,bad=false) { const n=$('notice'); n.textContent=msg; n.classList.remove('hidden'); n.classList.toggle('bad',bad); window.scrollTo({top:0,behavior:'smooth'}); }
async function req(path, opts={}) { const res=await fetch(path,{credentials:'same-origin',...opts}); const body=await res.json(); if(!res.ok) throw new Error(body.error||'请求失败'); return body; }
function markDirty(){ changed=true;$('dirty').classList.remove('hidden'); }
function setClean(){ changed=false;$('dirty').classList.add('hidden'); }
function makeField(label,value,onInput,area=false,readonly=false,placeholder='') {
 const wrap=el('label',{class:'field'}); wrap.append(el('span',{},label));
 const input=el(area?'textarea':'input',{placeholder}); input.value=value||''; if(readonly){input.readOnly=true;input.classList.add('idfield');}else{input.addEventListener('input',()=>{onInput(input.value);markDirty();refreshSide();refreshPreview();});}
 wrap.append(input); return wrap;
}
function navButton(id,item,type){const label=type==='page'?item.title:(item.text||'未设置文字').split('\n')[0].slice(0,22);const b=el('button',{class:'navitem'+(selection.type===type&&selection.id===id?' active':'')});b.append(el('span',{},type==='page'?'▤':'✉'),el('span',{},label||id));b.title=id;b.addEventListener('click',()=>select(type,id));return b;}
function refreshSide(){const p=$('pages'),r=$('replies');p.replaceChildren();r.replaceChildren();Object.entries(config.pages).forEach(([id,item])=>p.append(navButton(id,item,'page')));Object.entries(config.replies).forEach(([id,item])=>r.append(navButton(id,item,'reply')));}
function select(type,id){selection={type,id};refreshSide();renderEditor();refreshPreview();$('notice').classList.add('hidden');}
function validateId(id){return /^[A-Za-z0-9_-]{1,48}$/.test(id)}
function addItem(type){ const id=prompt('请输入英文 ID（字母、数字、下划线或连字符，创建后不能修改）',type==='page'?'new_menu':'new_reply');if(id===null)return;if(!validateId(id)){alert('ID 格式不正确');return;}const collection=type==='page'?config.pages:config.replies;if(collection[id]){alert('这个 ID 已存在');return;}collection[id]=type==='page'?{title:'新菜单',text:'请输入菜单说明',buttons:[]}:{text:'请输入自动回复内容'};markDirty();select(type,id);}
function deleteItem(){const {type,id}=selection;if(type==='page'&&id==='home'){alert('首页 home 不能删除');return;}const collection=type==='page'?config.pages:config.replies;const used=[];Object.entries(config.pages).forEach(([pid,page])=>page.buttons.forEach(btn=>{if(btn[type]===id)used.push(pid);}));if(used.length){alert('该项目仍被菜单按钮引用：'+[...new Set(used)].join(', ')+'\n请先删除或修改引用按钮。');return;}if(!confirm(`确认删除 ${id}？`))return;delete collection[id];markDirty();select('page','home');}
function inlineButton(label,fn,danger=false){const b=el('button',{type:'button',class:'mini'+(danger?' danger':'')},label);b.onclick=fn;return b;}
function createButtonRow(page,index){const b=page.buttons[index],outer=el('div',{class:'buttonrow'}),grid=el('div',{class:'btn-grid'});
 const name=el('label',{},'按钮名称');const nameInput=el('input');nameInput.value=b.label||'';nameInput.oninput=()=>{b.label=nameInput.value;markDirty();refreshPreview();};name.append(nameInput);
 const typeLabel=el('label',{},'按钮类型'),types=el('select');[['page','跳转菜单'],['reply','发送回复'],['url','打开链接']].forEach(([v,t])=>{const o=el('option',{value:v},t);types.append(o);});const active=b.page!==undefined?'page':b.reply!==undefined?'reply':'url';types.value=active;
 const destLabel=el('label',{},'目标'),dest=el('select');
 function populate(){const type=types.value;destLabel.replaceChildren(el('span',{},type==='url'?'HTTPS 网址':'目标 ID'));const current=b[type]||'';const input=type==='url'?el('input',{placeholder:'https://example.com'}):el('select');if(type==='url'){input.value=current;input.oninput=()=>{b.url=input.value;markDirty();refreshPreview();};}else{const options=type==='page'?Object.keys(config.pages):Object.keys(config.replies);for(const id of options)input.append(el('option',{value:id},id));if(!options.length)input.append(el('option',{value:''},'请先创建目标'));input.value=options.includes(current)?current:(options[0]||'');b[type]=input.value;input.onchange=()=>{b[type]=input.value;markDirty();refreshPreview();};}destLabel.append(input);}
 types.onchange=()=>{delete b.page;delete b.reply;delete b.url;b[types.value]='';populate();markDirty();refreshPreview();};typeLabel.append(types);populate();grid.append(name,typeLabel,destLabel);
 const actions=el('div',{class:'btn-actions'});actions.append(inlineButton('↑ 上移',()=>moveButton(page,index,-1)),inlineButton('↓ 下移',()=>moveButton(page,index,1)),inlineButton('删除',()=>{if(!confirm('确认删除此按钮？'))return;page.buttons.splice(index,1);markDirty();renderEditor();refreshPreview();},true));outer.append(grid,actions);return outer;
}
function moveButton(page,index,delta){const j=index+delta;if(j<0||j>=page.buttons.length)return;[page.buttons[index],page.buttons[j]]=[page.buttons[j],page.buttons[index]];markDirty();renderEditor();refreshPreview();}
function renderEditor(){const box=$('editorContent');box.replaceChildren();const {type,id}=selection;const item=config[type==='page'?'pages':'replies'][id];if(!item)return;
 $('crumb').textContent='机器人 / '+(type==='page'?'导航菜单':'自动回复')+' / '+id;$('itemTitle').textContent=type==='page'?item.title:'自动回复：'+id;$('itemDesc').textContent=type==='page'?'编辑本菜单的文字、下一级按钮和链接':'编辑点击按钮后发送的文字及媒体文件名';
 box.append(el('h2',{class:'section-title'},type==='page'?'菜单基本信息':'自动回复内容'));
 box.append(makeField('唯一 ID（不可修改）',id,()=>{},false,true));
 if(type==='page'){
 box.append(makeField('菜单标题',item.title,v=>{item.title=v;$('itemTitle').textContent=v;}));
 box.append(makeField('菜单说明文字',item.text,v=>item.text=v,true));
 const header=el('div',{class:'subsection'});header.append(el('h3',{},'菜单按钮 ('+item.buttons.length+')'),inlineButton('＋ 添加按钮',()=>{item.buttons.push({label:'新按钮',page:'home'});markDirty();renderEditor();refreshPreview();}));box.append(header);
 if(!item.buttons.length)box.append(el('div',{class:'empty'},'还没有按钮，点击上方「添加按钮」开始配置'));
 item.buttons.forEach((_,i)=>box.append(createButtonRow(item,i)));
 }else{
 box.append(makeField('自动回复文字',item.text||'',v=>item.text=v,true));
 const mediaTitle=el('div',{class:'subsection'});mediaTitle.append(el('h3',{},'媒体回复'));box.append(mediaTitle);
 const help=el('p',{class:'hint'},'媒体文件需先手动上传至 NAS 的 media 文件夹，只填写文件名，例如 product.jpg。');box.append(help);
 for(const kind of ['photo','document','video'])box.append(makeField(({photo:'图片文件名',document:'文档文件名',video:'视频文件名'})[kind],item[kind]||'',v=>{if(v.trim())item[kind]=v.trim();else delete item[kind];},false,false,({photo:'example.jpg',document:'guide.pdf',video:'demo.mp4'})[kind]));
 }
 const foot=el('div',{class:'subsection'});foot.append(el('h3',{},'项目操作'),inlineButton('删除当前'+(type==='page'?'菜单':'回复'),deleteItem,true));box.append(foot);
}
function refreshPreview(){const {type,id}=selection,item=config[type==='page'?'pages':'replies'][id];if(!item)return;$('previewText').textContent=type==='page'?((item.title||'')+'\n\n'+(item.text||'')):(item.text||'')+'\n'+(['photo','document','video'].filter(k=>item[k]).map(k=>'📎 '+item[k]).join('\n'));const buttons=$('previewButtons');buttons.replaceChildren();if(type==='page')item.buttons.forEach(b=>buttons.append(el('div',{class:'tgbutton'},b.label||'未命名按钮')));}
async function boot(){try{const sess=await req('/api/session');csrf=sess.csrf;if(!sess.authenticated){$('login').classList.remove('hidden');return;}const result=await req('/api/config');config=result.config;$('dashboard').classList.remove('hidden');refreshSide();select('page','home');}catch(e){$('login').classList.remove('hidden');$('loginError').textContent=e.message;}}
$('loginForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target);fd.append('csrf',csrf);try{await req('/api/login',{method:'POST',body:fd});location.reload();}catch(err){$('loginError').textContent=err.message;}};
$('logoutBtn').onclick=async()=>{await req('/api/logout',{method:'POST',headers:{'X-CSRF-Token':csrf}});location.reload();};
$('addPage').onclick=()=>addItem('page');$('addReply').onclick=()=>addItem('reply');
$('saveBtn').onclick=async()=>{try{const res=await req('/api/save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(config)});config=res.config;refreshSide();renderEditor();refreshPreview();setClean();announce('发布成功！未连接的菜单已补充到首页，请在机器人发送 /start 查看。备份：'+res.backup);}catch(e){announce('保存失败：'+e.message,true);}};
$('exportBtn').onclick=()=>{if(!config)return;const blob=new Blob([JSON.stringify(config,null,2)],{type:'application/json;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='menus-export.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
window.addEventListener('beforeunload',e=>{if(changed){e.preventDefault();e.returnValue='';}});
boot();
