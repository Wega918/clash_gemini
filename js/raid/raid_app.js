(function(){
  class RaidApp {
    constructor(root){
      this.root = root;
      this.state = {phase:'search', bootstrap:null, target:null, raid:null, scene:null, hoveredLane:1, hoveredSegment:1, timer:0, scoutEndsAt:0, result:null, searching:false};
      this.canvas = root.querySelector('#raid-scene');
      this.overlay = root.querySelector('#raid-scene-overlay');
      this.renderer = new window.RaidRenderer(this.canvas, msg=>this.feed(msg));
      this.bind();
      this._boundFit = () => this.fitStage();
    }
    bind(){
      this.root.querySelector('#raid-exit-btn')?.addEventListener('click', ()=>this.goHome());
      this.root.querySelector('#raid-reroll-btn')?.addEventListener('click', ()=>this.search(true));
      this.root.querySelector('#raid-attack-btn')?.addEventListener('click', ()=>this.startBattle());
      this.root.querySelector('#raid-end-btn')?.addEventListener('click', ()=>this.finishBattle());
      this.root.querySelector('#raid-ability-btn')?.addEventListener('click', ()=>{ if(this.state.scene?.activateHeroAbility()) this.refreshArmyUi(); });
      this.canvas.addEventListener('mousemove', e=>this.onCanvasMove(e));
      this.canvas.addEventListener('click', e=>this.onCanvasClick(e));
      window.addEventListener('resize', this._boundFit);
      window.addEventListener('orientationchange', this._boundFit);
    }
    async mount(){
      const boot = await window.RaidApi.bootstrap();
      this.state.bootstrap = boot;
      this.renderPlayer(boot.player, boot.next_cost);
      this.renderArmyStrip(boot.army);
      this.fitStage();
      this.setOverlay('Ищем противника…','Облачный фронт двигается, подбираем новую цель.', true);
      this.search(false);
      this.loop();
    }

    fitStage(){
      const frame = this.root.querySelector('.raid-stage-frame');
      if (!frame) return;
      const baseW = 920;
      const baseH = 700;
      const safeW = window.innerWidth <= 540 ? window.innerWidth : window.innerWidth - 16;
      const safeH = window.innerHeight <= 540 ? window.innerHeight : window.innerHeight - 16;
      const scale = Math.min(1, safeW / baseW, safeH / baseH);
      frame.style.transform = `scale(${scale})`;
    }
    async search(reroll){
      if (this.state.searching) return;
      this.state.searching = true;
      this.setPhase('search', 'Поиск противника', reroll ? 'Меняем цель' : 'Готовимся к вылету');
      this.setOverlay('Ищем противника…', reroll ? 'Облака сгущаются. Ищем другую базу.' : 'Облака рассеиваются, база проявляется впереди.', true);
      try {
        const data = await window.RaidApi.searchOpponent(!!reroll);
        this.state.bootstrap = data;
        this.state.target = data.target;
        this.renderPlayer(data.player, data.next_cost);
        this.renderTarget(data.target);
        this.renderArmyStrip(data.army);
        setTimeout(()=>{
          this.enterScout();
        }, 850);
      } catch (e) {
        this.feed(e.message || 'Не удалось найти цель');
      } finally { this.state.searching = false; }
    }
    enterScout(){
      this.setPhase('scout', 'Разведка', 'Осмотрите базу и выберите направление штурма');
      this.state.timer = 30;
      this.state.scoutEndsAt = performance.now() + 30000;
      this.setOverlay('Разведка: 30 сек','Изучите стены, ПВО и ядро базы. Затем начните атаку.', false);
      this.root.querySelector('#raid-attack-btn').disabled = false;
      this.updateThreats();
      this.drawScoutPreview();
    }
    drawScoutPreview(){
      const scene={buildings:this.state.target.base.buildings,walls:this.state.target.base.walls,laneYs:[112,220,328],hoveredLane:this.state.hoveredLane,hoveredSegment:this.state.hoveredSegment,getWallPosition:w=>({x:360+w.segment*56,y:[112,220,328][w.lane]}),getBuildingPosition:b=>({x:396+b.segment*56+(b.kind==='townhall'?24:0),y:[112,220,328][b.lane]+(b.kind==='defense'?-22:(b.kind==='resource'?22:0))}),effects:[],units:[],projectiles:[],imageCache:{},frontlineHint:'Разведка базы'};
      scene.buildings.forEach(b=>{ if(b.icon){ const img=new Image(); img.src=b.icon; scene.imageCache[b.icon]=img; } });
      scene.walls.forEach(w=>{ if(w.icon){ const img=new Image(); img.src=w.icon; scene.imageCache[w.icon]=img; } });
      this.renderer.setScene(scene);
    }
    async startBattle(){
      if (!this.state.target) return;
      const data = await window.RaidApi.startRaid(this.state.target.user_id);
      this.state.raid = data.raid;
      this.state.scene = new window.RaidBattleScene(data.raid, {feed:msg=>this.feed(msg), onChange:()=>this.refreshHud()});
      this.state.scene.hoveredLane = this.state.hoveredLane;
      this.state.scene.hoveredSegment = this.state.hoveredSegment;
      this.renderer.setScene(this.state.scene);
      this.setPhase('battle','Штурм','Проломите оборону и возьмите добычу');
      this.state.timer = 180;
      this.state.scoutEndsAt = 0;
      this.root.querySelector('#raid-attack-btn').disabled = true;
      this.root.querySelector('#raid-end-btn').disabled = false;
      this.root.querySelector('#raid-ability-btn').disabled = !data.raid.army.heroes?.length;
      this.renderArmyStrip(data.raid.army);
      this.setOverlay('Бой начался','Выберите войско снизу, затем кликните по линии или сектору.', false);
      this.updateThreats();
    }
    async finishBattle(){
      if (!this.state.scene || this.state.result) return;
      const result = this.state.scene.result();
      const data = await window.RaidApi.resolveRaid(this.state.raid.id, result);
      this.state.result = data.result;
      this.setPhase('result', data.result.stars >= 2 ? 'Победа' : 'Результат', data.result.target.login || 'Противник');
      this.setOverlay(`${data.result.stars}★  •  ${data.result.destructionPercent}%` , `Золото ${this.fmt(data.result.loot.gold)}  •  Эликсир ${this.fmt(data.result.loot.elixir)}  •  Тёмный ${this.fmt(data.result.loot.dark_elixir)}  •  Трофеи ${data.result.trophyDelta >=0 ? '+' : ''}${data.result.trophyDelta}`, true);
      this.root.querySelector('#raid-ability-btn').disabled = true;
      this.root.querySelector('#raid-end-btn').disabled = true;
      this.refreshHud();
    }
    onCanvasMove(e){
      const rect=this.canvas.getBoundingClientRect();
      const x=(e.clientX-rect.left)*(this.canvas.width/rect.width), y=(e.clientY-rect.top)*(this.canvas.height/rect.height);
      this.state.hoveredLane = Math.max(0, Math.min(2, Math.round((y-112)/108)));
      this.state.hoveredSegment = Math.max(0, Math.min(4, Math.round((x-392)/56)));
      if (this.state.scene) { this.state.scene.hoveredLane = this.state.hoveredLane; this.state.scene.hoveredSegment = this.state.hoveredSegment; }
    }
    onCanvasClick(){
      if (this.state.phase !== 'battle' || !this.state.scene || this.state.result) return;
      const card = this.state.scene.getSelected();
      if (!card) return;
      if (card.kind === 'spell') this.state.scene.castSelected(this.state.hoveredLane, this.state.hoveredSegment);
      else this.state.scene.spawnSelected(this.state.hoveredLane);
      this.refreshArmyUi();
    }
    feed(msg){
      const feed = this.root.querySelector('#raid-event-feed');
      if (!feed) return;
      const item = document.createElement('div'); item.className='raid-feed-item'; item.textContent=msg; feed.prepend(item);
      while (feed.children.length > 4) feed.lastElementChild.remove();
    }
    loop(){
      let last = performance.now();
      const tick = (now)=>{
        const dt = Math.min(.05, (now-last)/1000); last = now;
        if (this.state.phase === 'scout' && this.state.scoutEndsAt) {
          this.state.timer = Math.max(0, Math.ceil((this.state.scoutEndsAt - now)/1000));
          if (this.state.timer <= 0) this.startBattle();
        }
        if (this.state.phase === 'battle' && this.state.scene && !this.state.result) {
          this.state.scene.update(dt); this.state.timer = Math.max(0, Math.ceil(this.state.scene.duration - this.state.scene.time));
          if (this.state.scene.ended) this.finishBattle();
        }
        this.renderer.draw(now/1000);
        this.refreshHud();
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }
    refreshHud(){
      const timerEl = this.root.querySelector('#raid-timer');
      timerEl.textContent = this.state.phase==='search' ? '—' : this.mmss(this.state.timer);
      const destruction = this.state.scene?.stats?.destructionPercent || this.state.result?.destructionPercent || 0;
      const stars = this.state.scene?.stats?.stars || this.state.result?.stars || 0;
      this.root.querySelector('#raid-destruction').textContent = `${destruction}%`;
      this.root.querySelector('#raid-stars').textContent = `${stars}★`;
      const brief = this.root.querySelector('#raid-brief-text');
      if (this.state.phase==='scout') brief.textContent = '30 секунд на разведку: приоритетом должны стать опасные ПВО, Инферно и ядро базы.';
      else if (this.state.phase==='battle' && this.state.scene) brief.textContent = `${this.state.scene.frontlineHint}. Добыча: ${this.fmt(this.state.scene.stats.loot.gold)} / ${this.fmt(this.state.scene.stats.loot.elixir)} / ${this.fmt(this.state.scene.stats.loot.dark_elixir)}.`;
    }
    refreshArmyUi(){ this.renderArmyStrip(this.state.raid ? this.state.raid.army : this.state.bootstrap.army); }
    renderPlayer(player, nextCost){ this.root.querySelector('#raid-reroll-cost').textContent = `за ${this.fmt(nextCost || 0)} золота`; }
    renderTarget(target){
      this.root.querySelector('#raid-target-name').textContent = target.login;
      this.root.querySelector('#raid-target-sub').textContent = `Ратуша ${target.townhall_level} • ${target.trophies} кубков`;
      const loot = this.root.querySelector('#raid-target-loot'); loot.innerHTML='';
      [['gold','/images/icons/gold.png'],['elixir','/images/icons/elixir.png'],['dark_elixir','/images/icons/dark_elixir.png']].forEach(([k,icon])=>{ const div=document.createElement('div'); div.className='raid-loot-chip'; div.innerHTML=`<img src="${icon}" alt=""><span>${this.fmt(target.resources[k]||0)}</span>`; loot.appendChild(div); });
    }
    renderArmyStrip(army){
      const strip = this.root.querySelector('#raid-army-strip');
      if (!strip) return;
      const roster = this.state.scene ? this.state.scene.roster : {troops:(army.troops||[]).map(x=>({...x,remaining:x.count})), heroes:(army.heroes||[]).map(x=>({...x,remaining:1})), spells:(army.spells||[]).map(x=>({...x,remaining:x.count}))};
      strip.innerHTML='';
      [...roster.troops, ...roster.heroes, ...roster.spells].forEach(card=>{
        const el=document.createElement('button'); el.type='button'; el.className='raid-army-card' + (card.selected ? ' selected':'') + ((card.remaining||0)<=0 ? ' disabled':'');
        el.innerHTML=`<span class="qty">${card.remaining||0}</span><img src="${card.icon}" alt=""><div class="nm">${card.name}</div><div class="lvl">ур. ${card.level}</div>`;
        el.addEventListener('click', ()=>{ if ((card.remaining||0)<=0 || this.state.phase!=='battle') return; this.state.scene.selectCard(card.id); this.renderArmyStrip(this.state.raid.army); });
        strip.appendChild(el);
      });
    }
    updateThreats(){
      const wrap = this.root.querySelector('#raid-threat-list');
      if (!wrap || !this.state.target) return;
      wrap.innerHTML='';
      const threats = (this.state.target.base.buildings || []).filter(b=>['defense','townhall','trap'].includes(b.kind)).sort((a,b)=>(b.priorityWeight||0)-(a.priorityWeight||0)).slice(0,6);
      threats.forEach(t=>{ const el=document.createElement('div'); el.className='raid-threat-pill'; el.textContent=t.name; wrap.appendChild(el); });
    }
    setPhase(phase, label, title){ this.state.phase=phase; this.root.querySelector('#raid-phase-label').textContent=label; this.root.querySelector('#raid-phase-title').textContent=title; }
    setOverlay(title, sub, show){ this.root.querySelector('#raid-overlay-title').textContent=title; this.root.querySelector('#raid-overlay-sub').textContent=sub; this.overlay.classList.toggle('show', !!show); }
    goHome(){ const btn = document.querySelector('[data-page="home"]'); if (btn) btn.click(); else window.location.href='/'; }
    mmss(sec){ sec=Math.max(0, sec|0); const m=String(Math.floor(sec/60)).padStart(2,'0'); const s=String(sec%60).padStart(2,'0'); return `${m}:${s}`; }
    fmt(n){ return (parseInt(n,10)||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' '); }
  }
  window.RaidApp = RaidApp;
})();
