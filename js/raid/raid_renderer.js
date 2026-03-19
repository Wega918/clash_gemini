(function(){
  class RaidRenderer {
    constructor(canvas, feedCb){
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.feedCb = feedCb || (()=>{});
      this.width = canvas.width;
      this.height = canvas.height;
      this.particles = [];
      this.shake = 0;
    }
    setScene(scene){ this.scene = scene; }
    spawnParticles(x, y, color, count=10, speed=40){
      for(let i=0;i<count;i++){
        const a = Math.random()*Math.PI*2;
        const s = speed*(0.3+Math.random());
        this.particles.push({x,y,vx:Math.cos(a)*s,vy:Math.sin(a)*s,life:.4+Math.random()*.5,color,size:1+Math.random()*3});
      }
    }
    hit(x,y,color){ this.spawnParticles(x,y,color,12,50); this.shake = Math.max(this.shake, 4); }
    explosion(x,y,color){ this.spawnParticles(x,y,color,24,90); this.shake = Math.max(this.shake, 8); }
    draw(now){
      const ctx = this.ctx;
      ctx.save();
      ctx.clearRect(0,0,this.width,this.height);
      const shakeX = this.shake ? (Math.random()-.5)*this.shake : 0;
      const shakeY = this.shake ? (Math.random()-.5)*this.shake : 0;
      ctx.translate(shakeX, shakeY);
      this.drawBackground(ctx, now, this.scene);
      if (this.scene) {
        this.drawBase(ctx, this.scene, now);
        this.drawEffects(ctx, this.scene, now);
        this.drawUnits(ctx, this.scene, now);
        this.drawHudHints(ctx, this.scene, now);
      }
      this.drawParticles(ctx);
      ctx.restore();
      this.shake *= 0.82;
    }
    drawBackground(ctx, now, scene){
      const g = ctx.createLinearGradient(0,0,0,this.height);
      g.addColorStop(0,'#9fe6ff'); g.addColorStop(.32,'#81cdf8'); g.addColorStop(.325,'#92d97e'); g.addColorStop(1,'#5d9443');
      ctx.fillStyle=g; ctx.fillRect(0,0,this.width,this.height);
      ctx.fillStyle='rgba(255,255,255,.16)';
      for(let i=0;i<5;i++){
        const x = (i*160 + (now*10)%180)-40;
        ctx.beginPath(); ctx.arc(x, 58 + Math.sin(now+i)*8, 26, 0, Math.PI*2); ctx.fill();
        ctx.beginPath(); ctx.arc(x+28, 52 + Math.sin(now+i)*8, 22, 0, Math.PI*2); ctx.fill();
      }
      for(let i=0;i<3;i++){
        const y = 112 + i*108;
        ctx.fillStyle='rgba(255,255,255,.04)';
        ctx.fillRect(18, y-34, 180, 68);
        ctx.strokeStyle='rgba(255,255,255,.08)'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(0,y); ctx.lineTo(this.width,y); ctx.stroke();
        ctx.fillStyle='rgba(255,255,255,.12)';
        ctx.beginPath(); ctx.arc(70,y,14,0,Math.PI*2); ctx.fill();
        ctx.fillStyle='rgba(38,105,180,.9)';
        ctx.beginPath(); ctx.arc(70,y,10,0,Math.PI*2); ctx.fill();
      }
      ctx.fillStyle='rgba(6,12,22,.12)'; ctx.fillRect(340,22,292,this.height-44);
      ctx.strokeStyle='rgba(255,255,255,.08)'; ctx.lineWidth=1;
      for(let i=0;i<5;i++){ const x = 360 + i*56; ctx.beginPath(); ctx.moveTo(x,36); ctx.lineTo(x,this.height-36); ctx.stroke(); }
      ctx.fillStyle='rgba(255,214,111,.1)'; ctx.fillRect(358,36,260,this.height-72);
      ctx.fillStyle='rgba(0,0,0,.1)'; ctx.fillRect(440,0,220,this.height);
      if (scene){
        const hl = Math.max(0, Math.min(2, scene.hoveredLane ?? 1));
        const hs = Math.max(0, Math.min(4, scene.hoveredSegment ?? 1));
        const y = [112,220,328][hl];
        const x = 360 + hs*56;
        ctx.fillStyle='rgba(255,255,255,.06)'; ctx.fillRect(14,y-38,186,76);
        ctx.strokeStyle='rgba(255,225,132,.55)'; ctx.lineWidth=2; ctx.strokeRect(x-18,40,42,this.height-80);
      }
    }
    drawBase(ctx, scene){
      const laneYs = scene.laneYs;
      scene.walls.forEach(w=>{
        const p = scene.getWallPosition(w);
        const hpRatio = Math.max(0, w.hp / w.maxHp);
        const grad = ctx.createLinearGradient(0,p.y-18,0,p.y+18);
        grad.addColorStop(0, '#9b9b9b'); grad.addColorStop(1, '#5b5b5b');
        ctx.fillStyle = grad;
        const width = 20 + Math.min(28, w.count * 1.8);
        ctx.fillRect(p.x-width/2,p.y-20,width,40);
        ctx.strokeStyle='rgba(255,255,255,.22)'; ctx.strokeRect(p.x-width/2,p.y-20,width,40);
        if (hpRatio < .75) { ctx.strokeStyle='rgba(255,220,220,.6)'; ctx.beginPath(); ctx.moveTo(p.x-width/4,p.y-12); ctx.lineTo(p.x,p.y+5); ctx.lineTo(p.x+4,p.y+18); ctx.stroke(); }
        if (hpRatio < .45) { ctx.beginPath(); ctx.moveTo(p.x+width/5,p.y-10); ctx.lineTo(p.x-6,p.y+4); ctx.lineTo(p.x+8,p.y+16); ctx.stroke(); }
        this.drawHpBar(ctx,p.x-22,p.y+24,44,5,hpRatio,'#ffae5c');
      });
      scene.buildings.forEach(b=>{
        if (b.hidden && !b.revealed) return;
        const p = scene.getBuildingPosition(b);
        const destroyed = b.destroyed || b.hp <= 0;
        ctx.save();
        if (destroyed){ ctx.globalAlpha = .32; }
        ctx.translate(p.x,p.y);
        ctx.fillStyle = b.kind==='townhall' ? '#7a392a' : b.kind==='defense' ? '#7b2d36' : '#7d6530';
        ctx.shadowColor='rgba(0,0,0,.28)'; ctx.shadowBlur=16; ctx.shadowOffsetY=8;
        ctx.beginPath();
        ctx.roundRect(-24,-22,48,44,12);
        ctx.fill();
        ctx.strokeStyle='rgba(255,255,255,.16)'; ctx.stroke();
        if (b.img) {
          const img = scene.imageCache[b.img];
          if (img && img.complete) ctx.drawImage(img,-20,-18,40,36);
        } else {
          ctx.fillStyle='rgba(255,255,255,.9)'; ctx.font='700 11px sans-serif'; ctx.textAlign='center'; ctx.fillText((b.name||'?').slice(0,3),0,4);
        }
        if (b.frozenUntil > scene.time) { ctx.fillStyle='rgba(166,232,255,.26)'; ctx.fillRect(-22,-20,44,40); }
        ctx.restore();
        this.drawHpBar(ctx,p.x-24,p.y+28,48,5,Math.max(0,b.hp/b.maxHp), '#8ef56b');
      });
    }
    drawUnits(ctx, scene, now){
      scene.units.forEach(u=>{
        if (u.dead && u.opacity <= 0) return;
        const yOff = u.flying ? Math.sin(now*6 + u.idNum)*4 - 10 : 0;
        ctx.save();
        ctx.globalAlpha = u.opacity;
        ctx.translate(u.x,u.y+yOff);
        if (u.selected) { ctx.strokeStyle='rgba(255,228,128,.95)'; ctx.lineWidth=2; ctx.beginPath(); ctx.arc(0,0,16,0,Math.PI*2); ctx.stroke(); }
        if (u.status.rageUntil > scene.time) { ctx.fillStyle='rgba(198,120,255,.18)'; ctx.beginPath(); ctx.arc(0,0,20,0,Math.PI*2); ctx.fill(); }
        if (u.status.invulnerableUntil > scene.time) { ctx.strokeStyle='rgba(255,255,255,.82)'; ctx.beginPath(); ctx.arc(0,0,18,0,Math.PI*2); ctx.stroke(); }
        ctx.fillStyle = u.hero ? '#7e58ed' : (u.flying ? '#4b89f0' : '#2a5ca4');
        ctx.beginPath(); ctx.arc(0,0,12,0,Math.PI*2); ctx.fill();
        const img = scene.imageCache[u.icon];
        if (img && img.complete) ctx.drawImage(img,-12,-12,24,24);
        else { ctx.fillStyle='rgba(255,255,255,.92)'; ctx.font='700 10px sans-serif'; ctx.textAlign='center'; ctx.fillText((u.name||'?')[0]||'?',0,4); }
        ctx.restore();
        this.drawHpBar(ctx,u.x-14,u.y+(u.flying?-18:16),28,4,Math.max(0,u.hp/u.maxHp), '#79f58c');
      });
      scene.projectiles.forEach(p=>{
        ctx.strokeStyle = p.color; ctx.lineWidth = p.width || 2.2; ctx.globalAlpha = .9;
        ctx.beginPath(); ctx.moveTo(p.from.x,p.from.y); ctx.lineTo(p.to.x,p.to.y); ctx.stroke();
        ctx.fillStyle = p.color; ctx.beginPath(); ctx.arc(p.to.x,p.to.y,p.radius||3,0,Math.PI*2); ctx.fill();
      });
      ctx.globalAlpha = 1;
    }
    drawEffects(ctx, scene, now){
      scene.effects.forEach(e=>{
        if (e.until < scene.time) return;
        const alpha = .18 + Math.sin(now*8+e.id)*.05;
        ctx.save(); ctx.globalAlpha = alpha;
        ctx.beginPath(); ctx.arc(e.x,e.y,e.radius,0,Math.PI*2);
        if (e.type==='rage') { ctx.fillStyle='rgba(196,111,255,.28)'; ctx.fill(); ctx.strokeStyle='rgba(212,157,255,.65)'; }
        else if (e.type==='heal') { ctx.fillStyle='rgba(139,255,163,.24)'; ctx.fill(); ctx.strokeStyle='rgba(169,255,190,.65)'; }
        else if (e.type==='freeze') { ctx.fillStyle='rgba(155,227,255,.26)'; ctx.fill(); ctx.strokeStyle='rgba(209,243,255,.78)'; }
        else { ctx.fillStyle='rgba(255,233,112,.18)'; ctx.fill(); ctx.strokeStyle='rgba(255,233,112,.6)'; }
        ctx.lineWidth=2; ctx.stroke(); ctx.restore();
      });
    }
    drawParticles(ctx){
      const dt = 1/60;
      this.particles = this.particles.filter(p=> (p.life -= dt) > 0);
      this.particles.forEach(p=>{
        p.x += p.vx*dt; p.y += p.vy*dt; p.vy += 30*dt;
        ctx.globalAlpha = Math.max(0,p.life); ctx.fillStyle=p.color; ctx.fillRect(p.x,p.y,p.size,p.size);
      });
      ctx.globalAlpha=1;
    }
    drawHudHints(ctx, scene){
      if (!scene.frontlineHint) return;
      ctx.fillStyle='rgba(9,14,24,.58)'; ctx.beginPath(); ctx.roundRect(14,14,236,38,14); ctx.fill();
      ctx.fillStyle='rgba(255,255,255,.94)'; ctx.font='800 13px sans-serif'; ctx.fillText(scene.frontlineHint, 26, 38);
      ctx.fillStyle='rgba(255,255,255,.82)'; ctx.font='700 11px sans-serif'; ctx.fillText('Слева высадка • справа оборона базы', 26, 52);
    }
    drawHpBar(ctx,x,y,w,h,ratio,color){
      ctx.fillStyle='rgba(0,0,0,.35)'; ctx.fillRect(x,y,w,h); ctx.fillStyle=color; ctx.fillRect(x,y,w*Math.max(0,Math.min(1,ratio)),h);
    }
  }
  window.RaidRenderer = RaidRenderer;
})();
