(function(){
  let uid = 1;
  const priorityWeights = {wall:6, defense:5, hero:4, townhall:3, resource:2, building:1, any:0};

  class RaidBattleScene {
    constructor(raid, opts={}){
      this.raid = raid;
      this.time = 0;
      this.duration = 180;
      this.laneYs = [112,220,328];
      this.segmentXs = [464,516,570,622,674];
      this.entryX = 42;
      this.centerX = 330;
      this.frontlineHint = 'Выберите войско и линию высадки';
      this.imageCache = {};
      this.feed = opts.feed || (()=>{});
      this.onChange = opts.onChange || (()=>{});
      this.projectiles = [];
      this.effects = [];
      this.units = [];
      this.walls = (raid.target.base.walls || []).map(w=>({...w, hp:w.hp, maxHp:w.maxHp, destroyed:false}));
      this.buildings = (raid.target.base.buildings || []).map(b=>({...b, hp:b.hp, maxHp:b.maxHp, destroyed:false, revealed:!b.hidden, frozenUntil:0, dotUntil:0, invisibleUntil:0}));
      this.imageUrls = new Set();
      [...this.walls, ...this.buildings].forEach(x=>x.icon && this.imageUrls.add(x.icon));
      [...(raid.army.troops||[]), ...(raid.army.heroes||[]), ...(raid.army.spells||[])].forEach(x=>x.icon && this.imageUrls.add(x.icon));
      this.preloadImages();
      this.roster = this.prepareRoster(raid.army);
      this.stats = {loot:{gold:0,elixir:0,dark_elixir:0}, destroyedBuildings:0, destroyedDefenses:0, destroyedWalls:0, stars:0, destructionPercent:0, heroesFallen:0, troopsLost:0, mvp:null};
      this.deployedHousing = 0;
      this.ended = false;
    }
    preloadImages(){ this.imageUrls.forEach(url=>{ const img = new Image(); img.src = url; this.imageCache[url] = img; }); }
    prepareRoster(army){
      return {
        troops:(army.troops||[]).map(x=>({...x, remaining:x.count, selected:false})),
        heroes:(army.heroes||[]).map(x=>({...x, remaining:1, selected:false, abilityUsed:false})),
        spells:(army.spells||[]).map(x=>({...x, remaining:x.count, selected:false}))
      };
    }
    getRosterList(){ return [...this.roster.troops, ...this.roster.heroes, ...this.roster.spells]; }
    selectCard(id){ this.getRosterList().forEach(c=>c.selected = c.id===id); }
    getSelected(){ return this.getRosterList().find(c=>c.selected && c.remaining>0) || null; }
    getBuildingPosition(b){ return {x: 420 + b.segment*48 + (b.kind==='townhall'?20:0), y:this.laneYs[b.lane] + (b.kind==='defense'?-20:(b.kind==='resource'?20:0))}; }
    getWallPosition(w){ return {x: 390 + w.segment*48, y:this.laneYs[w.lane]}; }
    spawnSelected(lane){ const card = this.getSelected(); if (!card || card.kind==='spell' || card.remaining<=0) return false; return this.spawnCard(card, lane); }
    spawnCard(card, lane, fromAbility=false){
      const u = {
        id:'u'+(uid++), idNum:uid, cardId:card.id, name:card.name, icon:card.icon, x:this.entryX, y:this.laneYs[lane] + (Math.random()*18-9), lane,
        segment:0, target:null, hp:card.hp, maxHp:card.hp, damage:card.damagePerHit || Math.max(1, card.dps * card.attackSpeed), dps:card.dps,
        attackCooldown:0, attackSpeed:card.attackSpeed || 1, range:card.range || 1.1, speed:card.speed || 16, movement:card.movement,
        flying: card.movement==='air' || card.movement==='hybrid', hero:card.kind==='hero', kind:card.kind, targetPriority:card.targetPriority,
        canHitGround:card.canHitGround, canHitAir:card.canHitAir, wallBreaker:card.wallBreaker, wallDamageMultiplier:card.wallDamageMultiplier || 1,
        splash:card.splash || 0, summon:card.summon, heroAbility:card.heroAbility || '', dead:false, opacity:1, selected:false, spawnedAt:this.time,
        status:{rageUntil:0, healUntil:0, frozenUntil:0, invisibleUntil:0, invulnerableUntil:0}, abilityUsed:false, summonTick:this.time + (card.summon?.interval || 9999),
        damageDone:0, kills:0
      };
      if (u.flying) u.y -= 24;
      this.units.push(u);
      if (!fromAbility) card.remaining -= 1;
      this.deployedHousing += card.housing || 1;
      this.frontlineHint = `${card.name} вступает в ${['верхнюю','центральную','нижнюю'][lane]} линию`;
      this.feed(`Высадка: ${card.name} → ${['верх','центр','низ'][lane]}`);
      this.onChange();
      return true;
    }
    castSelected(lane, segment){ const card=this.getSelected(); if(!card||card.kind!=='spell'||card.remaining<=0) return false; this.castSpell(card,lane,segment); card.remaining -=1; this.onChange(); return true; }
    castSpell(card,lane,segment){
      const x = 392 + Math.max(0,Math.min(4,segment))*48;
      const y = this.laneYs[Math.max(0,Math.min(2,lane))];
      const radius = 32 + (card.spellRadius||3)*8;
      const until = this.time + Math.max(2.2, card.spellDuration || card.freezeTime || 4);
      this.effects.push({id:uid++, type:card.spellEffect, x,y,radius, until, cardId:card.id});
      this.feed(`${card.name} накрывает сектор ${segment+1}`);
      if (card.spellEffect==='lightning') {
        this.findTargets({lane, segment, canHitAir:true, canHitGround:true, prefer:'defense', includeFar:true}).slice(0,3).forEach(t=>this.applyDamageToBuilding(t, 260 + card.level*70, null, 'lightning'));
      }
      if (card.spellEffect==='earthquake') {
        this.walls.filter(w=>!w.destroyed && Math.abs(w.segment-segment)<=1 && Math.abs(w.lane-lane)<=1).forEach(w=>{ w.hp -= w.maxHp*.35; if (w.hp<=0) this.destroyWall(w); });
        this.buildings.filter(b=>!b.destroyed && Math.abs(b.segment-segment)<=1 && Math.abs(b.lane-lane)<=1).forEach(b=> this.applyDamageToBuilding(b, b.maxHp*.18, null, 'earthquake'));
      }
      if (card.spellEffect==='freeze') this.buildings.forEach(b=>{ const p=this.getBuildingPosition(b); if(!b.destroyed && Math.hypot(p.x-x,p.y-y)<=radius) b.frozenUntil = Math.max(b.frozenUntil, this.time + (card.freezeTime || 3.5)); });
      if (card.spellEffect==='rage' || card.spellEffect==='haste' || card.spellEffect==='heal' || card.spellEffect==='invisibility') {
        this.units.forEach(u=>{ if (u.dead) return; if (Math.hypot(u.x-x,u.y-y)<=radius) {
          if (card.spellEffect==='rage' || card.spellEffect==='haste') u.status.rageUntil = Math.max(u.status.rageUntil, until);
          if (card.spellEffect==='heal') u.status.healUntil = Math.max(u.status.healUntil, until);
          if (card.spellEffect==='invisibility') u.status.invisibleUntil = Math.max(u.status.invisibleUntil, until);
        }});
      }
    }
    activateHeroAbility(){
      const hero = this.units.find(u=>u.hero && !u.dead && !u.abilityUsed);
      if (!hero) return false;
      hero.abilityUsed = true;
      const card = this.roster.heroes.find(h=>h.id===hero.cardId);
      if (card) card.abilityUsed = true;
      switch(hero.heroAbility){
        case 'iron_fist':
          hero.hp = Math.min(hero.maxHp, hero.hp + hero.maxHp*0.36); hero.status.rageUntil = this.time + 10;
          for(let i=0;i<2;i++) this.spawnCard({id:'barbarian',name:'Варвар',icon:'/images/warriors/Barbarian/Avatar_Barbarian.png',hp:80,damagePerHit:28,dps:28,attackSpeed:1,range:1,speed:18,movement:'ground',canHitGround:true,canHitAir:false,targetPriority:'any',wallBreaker:false,wallDamageMultiplier:1,splash:0,housing:1,kind:'troop'}, hero.lane, true);
          this.feed('Король Варваров: Железный кулак!');
          break;
        case 'royal_cloak':
          hero.status.invisibleUntil = this.time + 5; hero.status.rageUntil = this.time + 6; hero.hp = Math.min(hero.maxHp, hero.hp + hero.maxHp*0.22);
          for(let i=0;i<2;i++) this.spawnCard({id:'archer',name:'Лучница',icon:'/images/warriors/Archer/Avatar_Archer.png',hp:40,damagePerHit:25,dps:25,attackSpeed:1,range:4.8,speed:22,movement:'ground',canHitGround:true,canHitAir:true,targetPriority:'any',wallBreaker:false,wallDamageMultiplier:1,splash:0,housing:1,kind:'troop'}, hero.lane, true);
          this.feed('Королева Лучниц: Королевский плащ!');
          break;
        case 'eternal_tome':
          this.units.forEach(u=>{ if (!u.dead && Math.abs(u.lane-hero.lane)<=1 && Math.abs(u.x-hero.x)<90) u.status.invulnerableUntil = Math.max(u.status.invulnerableUntil, this.time+4.5); });
          this.feed('Великий страж: Вечный том!');
          break;
        case 'seeking_shield':
          this.findTargets({lane:hero.lane, segment:hero.segment, canHitAir:true, canHitGround:true, prefer:'defense', includeFar:true}).slice(0,4).forEach((b,idx)=>setTimeout(()=>this.applyDamageToBuilding(b, hero.damage*2.1, hero, 'shield'), idx*90));
          this.feed('Королевская чемпионка: Щит-охотник!');
          break;
        default:
          hero.status.rageUntil = this.time + 6; this.feed(`${hero.name}: способность активирована`);
      }
      this.onChange();
      return true;
    }
    update(dt){
      if (this.ended) return;
      this.time += dt;
      this.projectiles.length = 0;
      this.updateEffects(dt);
      this.revealHidden();
      this.updateUnits(dt);
      this.updateDefenses(dt);
      this.updateStarsAndLoot();
      if (this.time >= this.duration || this.stats.destructionPercent >= 100 || this.buildings.every(b=>b.destroyed)) this.end();
    }
    updateEffects(dt){
      this.effects = this.effects.filter(e=>e.until > this.time);
      this.units.forEach(u=>{
        if (u.dead) { u.opacity = Math.max(0, u.opacity - dt*2); return; }
        if (u.status.healUntil > this.time) u.hp = Math.min(u.maxHp, u.hp + u.maxHp*0.07*dt);
        if (u.status.frozenUntil > this.time) return;
        if (u.status.invisibleUntil < this.time) {}
      });
    }
    revealHidden(){
      this.buildings.forEach(b=>{
        if (!b.hidden || b.revealed || b.destroyed) return;
        const trig = b.hiddenTrigger || {distance:1,target:'any'};
        const p = this.getBuildingPosition(b);
        const unit = this.units.find(u=>!u.dead && ((trig.target==='ground'&&!u.flying)||(trig.target==='air'&&u.flying)||trig.target==='any') && Math.hypot(u.x-p.x,u.y-p.y) <= trig.distance*42);
        if (unit) { b.revealed = true; this.feed(`Скрытая защита раскрыта: ${b.name}`); }
      });
    }
    updateUnits(dt){
      this.units.forEach(u=>{
        if (u.dead) return;
        if (u.status.frozenUntil > this.time) return;
        if (u.summon && this.time >= u.summonTick) {
          u.summonTick = this.time + u.summon.interval;
          for(let i=0;i<u.summon.count;i++) this.units.push({id:'u'+(uid++),idNum:uid,cardId:'summoned_'+u.summon.unit,name:'Скелет',icon:'/images/warriors/Barbarian/Avatar_Barbarian.png',x:u.x-8+i*8,y:u.y+8-i*6,lane:u.lane,segment:u.segment,target:null,hp:55,maxHp:55,damage:28,dps:28,attackCooldown:0,attackSpeed:1,range:1,speed:22,movement:'ground',flying:false,hero:false,kind:'summon',targetPriority:'any',canHitGround:true,canHitAir:false,wallBreaker:false,wallDamageMultiplier:1,splash:0,summon:null,heroAbility:'',dead:false,opacity:1,spawnedAt:this.time,status:{rageUntil:0,healUntil:0,frozenUntil:0,invisibleUntil:0,invulnerableUntil:0},abilityUsed:true,damageDone:0,kills:0});
          this.feed(`${u.name} призывает скелетов`);
        }
        u.attackCooldown = Math.max(0, u.attackCooldown - dt);
        const target = this.pickUnitTarget(u);
        if (!target) { u.x = Math.min(388, u.x + dt * this.currentSpeed(u)); return; }
        const tp = target.isWall ? this.getWallPosition(target.obj) : this.getBuildingPosition(target.obj);
        const dist = Math.hypot(tp.x-u.x,tp.y-u.y);
        if (dist > (u.range*42)) {
          const ang = Math.atan2(tp.y-u.y,tp.x-u.x);
          u.x += Math.cos(ang) * dt * this.currentSpeed(u);
          u.y += Math.sin(ang) * dt * this.currentSpeed(u) * .48;
          return;
        }
        if (u.attackCooldown > 0) return;
        u.attackCooldown = Math.max(.2, u.attackSpeed / (u.status.rageUntil>this.time ? 1.35 : 1));
        const dmg = u.damage * (u.status.rageUntil>this.time ? 1.4 : 1);
        if (target.isWall) {
          target.obj.hp -= dmg * (u.wallDamageMultiplier || 1);
          if (target.obj.hp <= 0) this.destroyWall(target.obj);
        } else {
          this.applyDamageToBuilding(target.obj, dmg, u, 'hit');
          if (u.splash > 0) this.buildings.forEach(other=>{ if(other===target.obj||other.destroyed) return; const op=this.getBuildingPosition(other); if(Math.hypot(op.x-tp.x,op.y-tp.y)<=u.splash*18) this.applyDamageToBuilding(other, dmg*.34, u, 'splash'); });
        }
      });
      this.units.forEach(u=>{ if (!u.dead && u.hp <= 0) this.killUnit(u); });
    }
    currentSpeed(u){ return u.speed * (u.status.rageUntil>this.time ? 1.28 : 1) * (u.status.frozenUntil>this.time ? 0 : 1); }
    pickUnitTarget(u){
      const frontierWall = this.walls.find(w=>!w.destroyed && w.lane===u.lane && w.segment>=u.segment);
      if (!u.flying && !u.hero && u.targetPriority==='wall' && frontierWall) return {isWall:true,obj:frontierWall,score:999};
      if (!u.flying && frontierWall && !this.isSegmentOpen(frontierWall.segment, u.lane)) return {isWall:true,obj:frontierWall,score:980};
      const opts = this.findTargets({lane:u.lane, segment:u.segment, canHitAir:u.canHitAir, canHitGround:u.canHitGround, prefer:u.targetPriority, includeFar:u.flying || u.hero});
      return opts[0] || (frontierWall ? {isWall:true,obj:frontierWall,score:400} : null);
    }
    findTargets({lane, segment, canHitAir, canHitGround, prefer, includeFar}){
      const list=[];
      this.buildings.forEach(b=>{
        if (b.destroyed) return;
        if (b.hidden && !b.revealed) return;
        if (!includeFar && b.segment > segment + 1) return;
        if (!includeFar && !this.isSegmentOpen(b.segment, lane) && b.kind!=='townhall') return;
        const airTarget = b.targets==='air' || b.targets==='air_ground';
        const groundTarget = b.targets==='ground' || b.targets==='air_ground' || !b.targets;
        const hitOk = canHitAir || canHitGround;
        if (!hitOk) return;
        let score = 100 - b.segment*8 - Math.abs(b.lane-lane)*6;
        score += (priorityWeights[b.kind]||0)*10;
        if (prefer==='defense' && b.kind==='defense') score += 60;
        if (prefer==='resource' && b.kind==='resource') score += 35;
        if (prefer==='townhall' && b.kind==='townhall') score += 60;
        if (prefer==='any') score += 0;
        list.push({isWall:false,obj:b,score});
      });
      list.sort((a,b)=>b.score-a.score);
      return list;
    }
    isSegmentOpen(segment, lane){
      const wall = this.walls.find(w=>w.segment===segment && w.lane===lane && !w.destroyed);
      return !wall;
    }
    updateDefenses(dt){
      this.buildings.forEach(b=>{
        if (b.destroyed || (b.hidden && !b.revealed)) return;
        if (b.kind!=='defense' && b.kind!=='townhall' && b.kind!=='trap') return;
        if (b.frozenUntil > this.time) return;
        b.cooldown = Math.max(0, (b.cooldown || 0) - dt);
        if (b.kind==='trap' && !b.revealed) return;
        const target = this.pickDefenseTarget(b);
        if (!target || b.cooldown > 0) return;
        const baseCd = b.attackType==='burst' ? .45 : (b.attackType==='beam' ? .35 : 1.0);
        b.cooldown = baseCd;
        const dmg = b.dps * baseCd * (b.attackType==='beam' ? 1.3 : 1);
        this.damageUnit(target, dmg, b);
        if (b.splashRadius > 0) this.units.forEach(u=>{ if(u===target||u.dead) return; if(Math.hypot(u.x-target.x,u.y-target.y)<=b.splashRadius*18) this.damageUnit(u, dmg*.45, b); });
      });
    }
    pickDefenseTarget(b){
      const bp=this.getBuildingPosition(b);
      const wantsAir = b.targets==='air' || b.targets==='air_ground';
      const wantsGround = b.targets==='ground' || b.targets==='air_ground' || !b.targets;
      let best=null,bestScore=-1e9;
      this.units.forEach(u=>{
        if (u.dead) return;
        if (u.status.invisibleUntil > this.time) return;
        if (u.status.invulnerableUntil > this.time) return;
        const isAir = !!u.flying;
        if (isAir && !wantsAir) return;
        if (!isAir && !wantsGround) return;
        const dist = Math.hypot(u.x-bp.x,u.y-bp.y);
        if (dist > b.range*42) return;
        let score = 240 - dist;
        if (u.hero) score += 26;
        if (!isAir && (b.buildingId==='mortar' || b.buildingId==='bomb_tower' || b.buildingId==='wizard_tower')) score += 12;
        if (isAir && b.buildingId==='air_defense') score += 40;
        if (u.cardId==='healer') score += 25;
        if (u.maxHp > 1200) score += 10;
        if (score > bestScore) { best=u; bestScore=score; }
      });
      return best;
    }
    damageUnit(u, dmg, source){
      if (u.status.invulnerableUntil > this.time) return;
      u.hp -= dmg; if (u.hp <= 0) this.killUnit(u, source);
    }
    killUnit(u, source){
      if (u.dead) return;
      u.dead = true; this.stats.troopsLost += u.kind==='hero' ? 0 : 1; if (u.hero) this.stats.heroesFallen += 1;
      this.feed(`${u.name} пал${u.hero ? '' : ''}`);
    }
    applyDamageToBuilding(b, dmg, unit, effect){
      if (b.destroyed) return;
      b.hp -= dmg;
      if (unit) unit.damageDone += dmg;
      if (b.hp <= 0) {
        b.destroyed = true;
        if (unit) unit.kills += 1;
        this.stats.destroyedBuildings += 1;
        if (b.kind==='defense') this.stats.destroyedDefenses += 1;
        this.stats.loot.gold += b.loot?.gold || 0;
        this.stats.loot.elixir += b.loot?.elixir || 0;
        this.stats.loot.dark_elixir += b.loot?.dark_elixir || 0;
        this.feed(`${b.name} уничтожен`);
      }
    }
    destroyWall(w){ if (w.destroyed) return; w.destroyed=true; w.hp=0; this.stats.destroyedWalls += w.count || 1; this.feed('Пролом в стене!'); }
    updateStarsAndLoot(){
      const total = this.buildings.length;
      const destroyed = this.buildings.filter(b=>b.destroyed).length;
      this.stats.destructionPercent = total ? Math.round((destroyed/total)*100) : 0;
      const townhallDown = this.buildings.some(b=>b.kind==='townhall' && b.destroyed);
      let stars = 0; if (townhallDown) stars = 1; if (this.stats.destructionPercent >= 50) stars = Math.max(stars, 2); if (this.stats.destructionPercent >= 100) stars = 3;
      this.stats.stars = stars;
      this.stats.mvp = this.units.filter(u=>u.damageDone>0).sort((a,b)=>(b.damageDone+b.kills*300)-(a.damageDone+a.kills*300))[0] || null;
    }
    end(){ this.ended = true; }
    result(){
      return {
        destructionPercent:this.stats.destructionPercent,
        stars:this.stats.stars,
        loot:this.stats.loot,
        summary:{destroyedWalls:this.stats.destroyedWalls,destroyedDefenses:this.stats.destroyedDefenses,troopsLost:this.stats.troopsLost,heroesFallen:this.stats.heroesFallen,mvp:this.stats.mvp ? this.stats.mvp.name : null},
        townhallDestroyed: this.buildings.some(b=>b.kind==='townhall' && b.destroyed)
      };
    }
  }
  window.RaidBattleScene = RaidBattleScene;
})();
