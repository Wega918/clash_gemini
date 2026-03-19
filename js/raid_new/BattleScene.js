/**
 * BATTLE SCENE - Основной экран боя
 * Data-driven боевая система с полной логикой юнитов, героев, заклинаний и защит
 */

(function() {
  'use strict';

  let uid = 1;
  
  // Priority weights for targeting
  const PRIORITY_WEIGHTS = {
    townhall: 100,
    defense: 85,
    resource: 40,
    wall: 30,
    building: 20,
    any: 0
  };

  class BattleScene {
    constructor(raid, canvas, ctx, callbacks) {
      this.raid = raid;
      this.canvas = canvas;
      this.ctx = ctx;
      this.callbacks = callbacks || {};
      
      // Battle state
      this.time = 0;
      this.duration = 180; // 3 minutes
      this.ended = false;
      
      // Positions
      this.laneYs = [110, 220, 330];
      this.segmentXs = [390, 446, 502, 558, 614];
      this.entryX = 42;
      
      // Hover state
      this.hoveredLane = 1;
      this.hoveredSegment = 2;
      
      // Entities
      this.units = [];
      this.walls = (raid.target.base.walls || []).map(w => ({
        ...w,
        hp: w.hp,
        maxHp: w.maxHp,
        destroyed: false
      }));
      this.buildings = (raid.target.base.buildings || []).map(b => ({
        ...b,
        hp: b.hp,
        maxHp: b.maxHp,
        destroyed: false,
        revealed: !b.hidden,
        frozenUntil: 0,
        attackCooldown: 0
      }));
      
      // Effects
      this.spellEffects = [];
      this.particles = [];
      this.projectiles = [];
      
      // Roster (available troops)
      this.roster = {
        troops: (raid.army.troops || []).map(t => ({
          ...t,
          remaining: t.count,
          selected: false
        })),
        heroes: (raid.army.heroes || []).map(h => ({
          ...h,
          remaining: 1,
          selected: false,
          abilityUsed: false
        })),
        spells: (raid.army.spells || []).map(s => ({
          ...s,
          remaining: s.count,
          selected: false
        }))
      };
      
      // Stats
      this.stats = {
        destructionPercent: 0,
        stars: 0,
        loot: { gold: 0, elixir: 0, dark_elixir: 0 },
        destroyedBuildings: 0,
        destroyedDefenses: 0,
        destroyedWalls: 0,
        troopsLost: 0,
        heroesFallen: 0
      };
      
      // Image cache
      this.imageCache = {};
      this.preloadImages();
      
      // Selected card
      this.selectedCardId = null;
    }

    /**
     * Preload images
     */
    preloadImages() {
      const urls = new Set();
      
      [...this.walls, ...this.buildings].forEach(e => {
        if (e.icon) urls.add(e.icon);
      });
      
      [...this.roster.troops, ...this.roster.heroes, ...this.roster.spells].forEach(e => {
        if (e.icon) urls.add(e.icon);
      });
      
      urls.forEach(url => {
        const img = new Image();
        img.src = url;
        this.imageCache[url] = img;
      });
    }

    /**
     * Get roster (for UI)
     */
    getRoster() {
      return this.roster;
    }

    /**
     * Get stats (for UI)
     */
    getStats() {
      return this.stats;
    }

    /**
     * Get remaining time
     */
    getRemainingTime() {
      return Math.max(0, this.duration - this.time);
    }

    /**
     * Is battle ended
     */
    isEnded() {
      return this.ended;
    }

    /**
     * Set hover position
     */
    setHover(lane, segment) {
      this.hoveredLane = lane;
      this.hoveredSegment = segment;
    }

    /**
     * Select card
     */
    selectCard(cardId) {
      this.selectedCardId = cardId;
      
      // Update selected state in roster
      [...this.roster.troops, ...this.roster.heroes, ...this.roster.spells].forEach(card => {
        card.selected = card.id === cardId;
      });
    }

    /**
     * Spawn unit (troop or hero)
     */
    spawnUnit(card, lane) {
      if (!card || card.kind === 'spell' || card.remaining <= 0) return false;
      
      lane = Math.max(0, Math.min(2, lane));
      
      const unit = {
        id: 'u' + (uid++),
        cardId: card.id,
        name: card.name,
        icon: card.icon,
        kind: card.kind,
        level: card.level,
        
        // Position
        x: this.entryX,
        y: this.laneYs[lane] + (Math.random() - 0.5) * 18,
        lane: lane,
        segment: 0,
        
        // Combat stats
        hp: card.hp,
        maxHp: card.hp,
        damage: card.damagePerHit || (card.dps * (card.attackSpeed || 1)),
        dps: card.dps,
        attackSpeed: card.attackSpeed || 1,
        attackCooldown: 0,
        range: card.range || 1.1,
        
        // Movement
        speed: card.speed || 18,
        movement: card.movement,
        flying: card.movement === 'air' || card.movement === 'hybrid',
        
        // Targeting
        canHitGround: card.canHitGround !== false,
        canHitAir: card.canHitAir !== false,
        targetPriority: card.targetPriority || 'any',
        target: null,
        
        // Special abilities
        wallBreaker: card.wallBreaker || false,
        wallDamageMultiplier: card.wallDamageMultiplier || 1,
        splash: card.splash || 0,
        summon: card.summon,
        summonTick: this.time + (card.summon?.interval || 999),
        heroAbility: card.heroAbility || '',
        abilityUsed: false,
        
        // Status effects
        status: {
          rageUntil: 0,
          healUntil: 0,
          frozenUntil: 0,
          invisibleUntil: 0,
          invulnerableUntil: 0
        },
        
        // State
        dead: false,
        opacity: 1,
        spawnedAt: this.time,
        damageDone: 0,
        kills: 0
      };
      
      // Flying units start higher
      if (unit.flying) {
        unit.y -= 26;
      }
      
      this.units.push(unit);
      card.remaining -= 1;
      
      this.feed(`${card.name} высадился!`);
      this.onChange();
      
      return true;
    }

    /**
     * Cast spell
     */
    castSpell(card, lane, segment) {
      if (!card || card.kind !== 'spell' || card.remaining <= 0) return false;
      
      lane = Math.max(0, Math.min(2, lane));
      segment = Math.max(0, Math.min(4, segment));
      
      const x = this.segmentXs[segment];
      const y = this.laneYs[lane];
      const radius = 32 + (card.spellRadius || 3) * 8;
      const duration = Math.max(2.5, card.spellDuration || card.freezeTime || 4);
      
      const effect = {
        id: uid++,
        type: card.spellEffect,
        x: x,
        y: y,
        radius: radius,
        until: this.time + duration,
        cardId: card.id,
        level: card.level
      };
      
      this.spellEffects.push(effect);
      card.remaining -= 1;
      
      this.feed(`${card.name} применено!`);
      this.onChange();
      
      // Apply instant effects
      this.applySpellEffect(effect, card);
      
      // Create visual particles
      this.createSpellParticles(x, y, radius, card.spellEffect);
      
      return true;
    }

    /**
     * Apply spell effects
     */
    applySpellEffect(effect, card) {
      const { type, x, y, radius, until } = effect;
      
      // Lightning - instant damage to buildings
      if (type === 'lightning') {
        const targets = this.buildings
          .filter(b => !b.destroyed)
          .map(b => {
            const pos = this.getBuildingPosition(b);
            const dist = Math.hypot(pos.x - x, pos.y - y);
            return { building: b, distance: dist };
          })
          .filter(t => t.distance <= radius)
          .sort((a, b) => a.distance - b.distance)
          .slice(0, 3);
        
        targets.forEach(t => {
          const dmg = 260 + card.level * 70;
          this.damageBuilding(t.building, dmg, null, 'lightning');
        });
      }
      
      // Earthquake - damage to walls and buildings
      if (type === 'earthquake') {
        this.walls.forEach(w => {
          if (w.destroyed) return;
          const pos = this.getWallPosition(w);
          const dist = Math.hypot(pos.x - x, pos.y - y);
          if (dist <= radius * 1.2) {
            w.hp -= w.maxHp * 0.35;
            if (w.hp <= 0) this.destroyWall(w);
          }
        });
        
        this.buildings.forEach(b => {
          if (b.destroyed) return;
          const pos = this.getBuildingPosition(b);
          const dist = Math.hypot(pos.x - x, pos.y - y);
          if (dist <= radius) {
            this.damageBuilding(b, b.maxHp * 0.18, null, 'earthquake');
          }
        });
      }
      
      // Freeze - freeze buildings
      if (type === 'freeze') {
        this.buildings.forEach(b => {
          if (b.destroyed) return;
          const pos = this.getBuildingPosition(b);
          const dist = Math.hypot(pos.x - x, pos.y - y);
          if (dist <= radius) {
            b.frozenUntil = Math.max(b.frozenUntil, until);
          }
        });
      }
      
      // Rage, Heal, Haste, Invisibility - affect units
      if (['rage', 'haste', 'heal', 'invisibility'].includes(type)) {
        this.units.forEach(u => {
          if (u.dead) return;
          const dist = Math.hypot(u.x - x, u.y - y);
          if (dist <= radius) {
            if (type === 'rage' || type === 'haste') {
              u.status.rageUntil = Math.max(u.status.rageUntil, until);
            }
            if (type === 'heal') {
              u.status.healUntil = Math.max(u.status.healUntil, until);
            }
            if (type === 'invisibility') {
              u.status.invisibleUntil = Math.max(u.status.invisibleUntil, until);
            }
          }
        });
      }
    }

    /**
     * Activate hero ability
     */
    activateHeroAbility() {
      const hero = this.units.find(u => u.kind === 'hero' && !u.dead && !u.abilityUsed);
      if (!hero) return false;
      
      hero.abilityUsed = true;
      
      // Mark ability as used in roster
      const card = this.roster.heroes.find(h => h.id === hero.cardId);
      if (card) card.abilityUsed = true;
      
      // Apply ability effects based on type
      switch (hero.heroAbility) {
        case 'iron_fist': // Barbarian King
          hero.hp = Math.min(hero.maxHp, hero.hp + hero.maxHp * 0.36);
          hero.status.rageUntil = this.time + 10;
          this.feed(`${hero.name}: Железный кулак!`);
          break;
          
        case 'royal_cloak': // Archer Queen
          hero.status.invisibleUntil = this.time + 5;
          hero.status.rageUntil = this.time + 6;
          hero.hp = Math.min(hero.maxHp, hero.hp + hero.maxHp * 0.22);
          this.feed(`${hero.name}: Королевский плащ!`);
          break;
          
        case 'eternal_tome': // Grand Warden
          this.units.forEach(u => {
            if (u.dead) return;
            const dist = Math.hypot(u.x - hero.x, u.y - hero.y);
            if (dist < 120) {
              u.status.invulnerableUntil = Math.max(u.status.invulnerableUntil, this.time + 4.5);
            }
          });
          this.feed(`${hero.name}: Вечный том!`);
          break;
          
        case 'seeking_shield': // Royal Champion
          const targets = this.buildings
            .filter(b => !b.destroyed && b.kind === 'defense')
            .slice(0, 4);
          targets.forEach(b => {
            this.damageBuilding(b, hero.damage * 2.5, hero, 'ability');
          });
          this.feed(`${hero.name}: Щит-охотник!`);
          break;
          
        default:
          hero.status.rageUntil = this.time + 6;
          this.feed(`${hero.name}: Способность!`);
      }
      
      this.onChange();
      return true;
    }

    /**
     * Update battle (called every frame)
     */
    update(dt) {
      if (this.ended) return;
      
      this.time += dt;
      
      // Update effects
      this.updateEffects(dt);
      
      // Reveal hidden defenses
      this.revealHiddenDefenses();
      
      // Update units
      this.updateUnits(dt);
      
      // Update defenses
      this.updateDefenses(dt);
      
      // Update particles
      this.updateParticles(dt);
      
      // Update stats
      this.updateStats();
      
      // Check end conditions
      if (this.time >= this.duration || 
          this.stats.destructionPercent >= 100 || 
          this.buildings.every(b => b.destroyed)) {
        this.end();
      }
    }

    /**
     * Update effects
     */
    updateEffects(dt) {
      // Remove expired spell effects
      this.spellEffects = this.spellEffects.filter(e => e.until > this.time);
      
      // Apply continuous effects to units
      this.units.forEach(u => {
        if (u.dead) {
          u.opacity = Math.max(0, u.opacity - dt * 2);
          return;
        }
        
        // Healing
        if (u.status.healUntil > this.time) {
          u.hp = Math.min(u.maxHp, u.hp + u.maxHp * 0.08 * dt);
        }
      });
    }

    /**
     * Reveal hidden defenses
     */
    revealHiddenDefenses() {
      this.buildings.forEach(b => {
        if (!b.hidden || b.revealed || b.destroyed) return;
        
        const trigger = b.hiddenTrigger || { distance: 1, target: 'any' };
        const pos = this.getBuildingPosition(b);
        
        const unit = this.units.find(u => {
          if (u.dead) return false;
          
          const targetMatch = 
            trigger.target === 'any' ||
            (trigger.target === 'ground' && !u.flying) ||
            (trigger.target === 'air' && u.flying);
          
          if (!targetMatch) return false;
          
          const dist = Math.hypot(u.x - pos.x, u.y - pos.y);
          return dist <= trigger.distance * 42;
        });
        
        if (unit) {
          b.revealed = true;
          this.feed(`Обнаружена скрытая защита: ${b.name}!`);
        }
      });
    }

    /**
     * Update units
     */
    updateUnits(dt) {
      this.units.forEach(u => {
        if (u.dead) return;
        if (u.status.frozenUntil > this.time) return;
        
        // Summon ability
        if (u.summon && this.time >= u.summonTick) {
          u.summonTick = this.time + u.summon.interval;
          // Spawn summoned units (simplified)
          this.feed(`${u.name} призывает помощников!`);
        }
        
        // Attack cooldown
        u.attackCooldown = Math.max(0, u.attackCooldown - dt);
        
        // Find target
        const target = this.findUnitTarget(u);
        u.target = target;
        
        if (!target) {
          // Move forward
          const speedMult = u.status.rageUntil > this.time ? 1.3 : 1;
          u.x = Math.min(640, u.x + dt * u.speed * speedMult);
          u.segment = Math.floor((u.x - 360) / 56);
          return;
        }
        
        const targetPos = target.isWall ? 
          this.getWallPosition(target.obj) : 
          this.getBuildingPosition(target.obj);
        
        const dist = Math.hypot(targetPos.x - u.x, targetPos.y - u.y);
        
        // Move towards target if out of range
        if (dist > u.range * 42) {
          const angle = Math.atan2(targetPos.y - u.y, targetPos.x - u.x);
          const speedMult = u.status.rageUntil > this.time ? 1.3 : 1;
          u.x += Math.cos(angle) * dt * u.speed * speedMult;
          u.y += Math.sin(angle) * dt * u.speed * speedMult * 0.5;
          u.segment = Math.floor((u.x - 360) / 56);
          return;
        }
        
        // Attack
        if (u.attackCooldown <= 0) {
          const speedMult = u.status.rageUntil > this.time ? 1.35 : 1;
          const damageMult = u.status.rageUntil > this.time ? 1.4 : 1;
          u.attackCooldown = u.attackSpeed / speedMult;
          
          const dmg = u.damage * damageMult;
          
          if (target.isWall) {
            target.obj.hp -= dmg * u.wallDamageMultiplier;
            if (target.obj.hp <= 0) this.destroyWall(target.obj);
          } else {
            this.damageBuilding(target.obj, dmg, u, 'attack');
            
            // Splash damage
            if (u.splash > 0) {
              this.buildings.forEach(other => {
                if (other === target.obj || other.destroyed) return;
                const otherPos = this.getBuildingPosition(other);
                const splashDist = Math.hypot(otherPos.x - targetPos.x, otherPos.y - targetPos.y);
                if (splashDist <= u.splash * 18) {
                  this.damageBuilding(other, dmg * 0.4, u, 'splash');
                }
              });
            }
          }
          
          // Create projectile visual
          this.createProjectile(u.x, u.y, targetPos.x, targetPos.y);
        }
      });
      
      // Remove dead units
      this.units.forEach(u => {
        if (!u.dead && u.hp <= 0) {
          this.killUnit(u);
        }
      });
    }

    /**
     * Update defenses (buildings attack units)
     */
    updateDefenses(dt) {
      this.buildings.forEach(b => {
        if (b.destroyed || (b.hidden && !b.revealed)) return;
        if (b.kind !== 'defense' && b.kind !== 'townhall') return;
        if (b.frozenUntil > this.time) return;
        
        b.attackCooldown = Math.max(0, (b.attackCooldown || 0) - dt);
        if (b.attackCooldown > 0) return;
        
        const target = this.findDefenseTarget(b);
        if (!target) return;
        
        b.attackCooldown = 1.0;
        const dmg = b.dps * 1.0;
        
        this.damageUnit(target, dmg, b);
        
        // Splash damage
        if (b.splashRadius > 0) {
          this.units.forEach(u => {
            if (u === target || u.dead) return;
            const dist = Math.hypot(u.x - target.x, u.y - target.y);
            if (dist <= b.splashRadius * 18) {
              this.damageUnit(u, dmg * 0.5, b);
            }
          });
        }
        
        // Create projectile
        const bPos = this.getBuildingPosition(b);
        this.createProjectile(bPos.x, bPos.y, target.x, target.y, '#ff6060');
      });
    }

    /**
     * Update particles
     */
    updateParticles(dt) {
      this.particles.forEach((p, idx) => {
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        p.vy += 120 * dt; // gravity
        p.life -= dt;
        p.opacity = Math.max(0, p.life / 0.6);
        
        if (p.life <= 0) {
          this.particles.splice(idx, 1);
        }
      });
    }

    /**
     * Find target for unit
     */
    findUnitTarget(u) {
      // Check for walls blocking path
      if (!u.flying) {
        const frontWall = this.walls.find(w => 
          !w.destroyed && 
          w.lane === u.lane && 
          w.segment >= u.segment
        );
        
        if (frontWall && !this.isSegmentOpen(frontWall.segment, u.lane)) {
          if (u.wallBreaker || u.targetPriority === 'wall') {
            return { isWall: true, obj: frontWall, score: 999 };
          }
          return { isWall: true, obj: frontWall, score: 500 };
        }
      }
      
      // Find buildings
      const candidates = [];
      
      this.buildings.forEach(b => {
        if (b.destroyed) return;
        if (b.hidden && !b.revealed) return;
        
        const bPos = this.getBuildingPosition(b);
        const dist = Math.hypot(bPos.x - u.x, bPos.y - u.y);
        
        // Range check (flying units have longer sight)
        if (!u.flying && dist > 180) return;
        if (u.flying && dist > 260) return;
        
        // Check if segment is accessible
        if (!u.flying && !this.isSegmentOpen(b.segment, u.lane) && b.kind !== 'townhall') {
          return;
        }
        
        let score = 200 - dist * 0.5;
        score += (PRIORITY_WEIGHTS[b.kind] || 0);
        
        // Apply target priority
        if (u.targetPriority === 'defense' && b.kind === 'defense') score += 80;
        if (u.targetPriority === 'resource' && b.kind === 'resource') score += 50;
        if (u.targetPriority === 'townhall' && b.kind === 'townhall') score += 100;
        
        candidates.push({ isWall: false, obj: b, score });
      });
      
      candidates.sort((a, b) => b.score - a.score);
      return candidates[0] || null;
    }

    /**
     * Find target for defense
     */
    findDefenseTarget(building) {
      const bPos = this.getBuildingPosition(building);
      const wantsAir = building.targets === 'air' || building.targets === 'air_ground';
      const wantsGround = building.targets === 'ground' || building.targets === 'air_ground' || !building.targets;
      
      let best = null;
      let bestScore = -999999;
      
      this.units.forEach(u => {
        if (u.dead) return;
        if (u.status.invisibleUntil > this.time) return;
        if (u.status.invulnerableUntil > this.time) return;
        
        const isAir = u.flying;
        if (isAir && !wantsAir) return;
        if (!isAir && !wantsGround) return;
        
        const dist = Math.hypot(u.x - bPos.x, u.y - bPos.y);
        if (dist > (building.range || 5) * 42) return;
        
        let score = 300 - dist;
        if (u.kind === 'hero') score += 40;
        if (u.maxHp > 1000) score += 20;
        
        if (score > bestScore) {
          best = u;
          bestScore = score;
        }
      });
      
      return best;
    }

    /**
     * Is segment open (no wall blocking)
     */
    isSegmentOpen(segment, lane) {
      const wall = this.walls.find(w => 
        w.segment === segment && 
        w.lane === lane && 
        !w.destroyed
      );
      return !wall;
    }

    /**
     * Damage building
     */
    damageBuilding(building, damage, attacker, source) {
      if (building.destroyed) return;
      
      building.hp -= damage;
      
      if (attacker) {
        attacker.damageDone += damage;
      }
      
      // Create particles
      const pos = this.getBuildingPosition(building);
      this.createHitParticles(pos.x, pos.y, '#ff9040');
      
      if (building.hp <= 0) {
        building.destroyed = true;
        building.hp = 0;
        
        if (attacker) {
          attacker.kills += 1;
        }
        
        this.stats.destroyedBuildings += 1;
        if (building.kind === 'defense') {
          this.stats.destroyedDefenses += 1;
        }
        
        // Add loot
        if (building.loot) {
          this.stats.loot.gold += building.loot.gold || 0;
          this.stats.loot.elixir += building.loot.elixir || 0;
          this.stats.loot.dark_elixir += building.loot.dark_elixir || 0;
        }
        
        this.feed(`${building.name} уничтожен!`);
        this.createExplosionParticles(pos.x, pos.y);
      }
    }

    /**
     * Damage unit
     */
    damageUnit(unit, damage, source) {
      if (unit.dead) return;
      if (unit.status.invulnerableUntil > this.time) return;
      
      unit.hp -= damage;
      
      this.createHitParticles(unit.x, unit.y, '#ff6060');
      
      if (unit.hp <= 0) {
        this.killUnit(unit, source);
      }
    }

    /**
     * Kill unit
     */
    killUnit(unit, source) {
      if (unit.dead) return;
      
      unit.dead = true;
      unit.hp = 0;
      
      if (unit.kind === 'hero') {
        this.stats.heroesFallen += 1;
      } else {
        this.stats.troopsLost += 1;
      }
      
      this.feed(`${unit.name} пал!`);
      this.createExplosionParticles(unit.x, unit.y);
    }

    /**
     * Destroy wall
     */
    destroyWall(wall) {
      if (wall.destroyed) return;
      
      wall.destroyed = true;
      wall.hp = 0;
      this.stats.destroyedWalls += 1;
      
      this.feed('Пролом в стене!');
      
      const pos = this.getWallPosition(wall);
      this.createExplosionParticles(pos.x, pos.y);
    }

    /**
     * Update stats
     */
    updateStats() {
      const total = this.buildings.length;
      const destroyed = this.buildings.filter(b => b.destroyed).length;
      
      this.stats.destructionPercent = total > 0 ? Math.round((destroyed / total) * 100) : 0;
      
      const townhallDown = this.buildings.some(b => b.kind === 'townhall' && b.destroyed);
      
      let stars = 0;
      if (townhallDown) stars = 1;
      if (this.stats.destructionPercent >= 50) stars = Math.max(stars, 2);
      if (this.stats.destructionPercent >= 100) stars = 3;
      
      this.stats.stars = stars;
    }

    /**
     * End battle
     */
    end() {
      this.ended = true;
    }

    /**
     * Get battle result
     */
    getResult() {
      return {
        destructionPercent: this.stats.destructionPercent,
        stars: this.stats.stars,
        loot: this.stats.loot,
        summary: {
          destroyedWalls: this.stats.destroyedWalls,
          destroyedDefenses: this.stats.destroyedDefenses,
          troopsLost: this.stats.troopsLost,
          heroesFallen: this.stats.heroesFallen
        },
        townhallDestroyed: this.buildings.some(b => b.kind === 'townhall' && b.destroyed)
      };
    }

    /**
     * Get building position
     */
    getBuildingPosition(building) {
      const x = this.segmentXs[building.segment] + (building.segment === 4 ? 12 : 0);
      const y = this.laneYs[building.lane] + 
        (building.kind === 'defense' ? -22 : (building.kind === 'resource' ? 22 : 0));
      return { x, y };
    }

    /**
     * Get wall position
     */
    getWallPosition(wall) {
      return {
        x: this.segmentXs[wall.segment],
        y: this.laneYs[wall.lane]
      };
    }

    /**
     * Create projectile visual
     */
    createProjectile(x1, y1, x2, y2, color = '#ffcc00') {
      this.projectiles.push({
        x1, y1, x2, y2, color,
        life: 0.2,
        createdAt: this.time
      });
    }

    /**
     * Create hit particles
     */
    createHitParticles(x, y, color) {
      for (let i = 0; i < 8; i++) {
        const angle = Math.random() * Math.PI * 2;
        const speed = 40 + Math.random() * 40;
        this.particles.push({
          x, y,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed - 30,
          life: 0.4 + Math.random() * 0.3,
          opacity: 1,
          color: color,
          size: 2 + Math.random() * 3
        });
      }
    }

    /**
     * Create explosion particles
     */
    createExplosionParticles(x, y) {
      for (let i = 0; i < 16; i++) {
        const angle = Math.random() * Math.PI * 2;
        const speed = 60 + Math.random() * 60;
        this.particles.push({
          x, y,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed - 40,
          life: 0.6 + Math.random() * 0.4,
          opacity: 1,
          color: Math.random() > 0.5 ? '#ff9040' : '#ffcc00',
          size: 3 + Math.random() * 4
        });
      }
    }

    /**
     * Create spell particles
     */
    createSpellParticles(x, y, radius, type) {
      const colors = {
        rage: '#d070ff',
        heal: '#70ff90',
        freeze: '#70d0ff',
        lightning: '#fff040',
        default: '#ffffff'
      };
      
      const color = colors[type] || colors.default;
      
      for (let i = 0; i < 24; i++) {
        const angle = Math.random() * Math.PI * 2;
        const dist = Math.random() * radius;
        this.particles.push({
          x: x + Math.cos(angle) * dist,
          y: y + Math.sin(angle) * dist,
          vx: Math.cos(angle) * 30,
          vy: Math.sin(angle) * 30 - 50,
          life: 0.8 + Math.random() * 0.5,
          opacity: 1,
          color: color,
          size: 3 + Math.random() * 4
        });
      }
    }

    /**
     * Render battle scene
     */
    render(now) {
      const ctx = this.ctx;
      const w = this.canvas.width;
      const h = this.canvas.height;
      
      // Clear
      ctx.clearRect(0, 0, w, h);
      
      // Background
      this.drawBackground(ctx, w, h, now);
      
      // Base structures
      this.drawWalls(ctx);
      this.drawBuildings(ctx);
      
      // Units
      this.drawUnits(ctx, now);
      
      // Effects
      this.drawSpellEffects(ctx, now);
      this.drawProjectiles(ctx);
      this.drawParticles(ctx);
      
      // Hover highlights
      this.drawHoverHighlights(ctx);
    }

    /**
     * Draw background (same as scout)
     */
    drawBackground(ctx, w, h, now) {
      const skyGrad = ctx.createLinearGradient(0, 0, 0, h);
      skyGrad.addColorStop(0, '#a8ecff');
      skyGrad.addColorStop(0.32, '#7dcaf6');
      skyGrad.addColorStop(0.325, '#95da6d');
      skyGrad.addColorStop(1, '#6dac4d');
      ctx.fillStyle = skyGrad;
      ctx.fillRect(0, 0, w, h);
      
      // Clouds
      ctx.fillStyle = 'rgba(255,255,255,0.14)';
      for (let i = 0; i < 5; i++) {
        const x = (i * 160 + (now * 10) % 180) - 40;
        const y = 50 + Math.sin(now + i) * 6;
        ctx.beginPath();
        ctx.arc(x, y, 22, 0, Math.PI * 2);
        ctx.fill();
      }
      
      // Lane lines
      this.laneYs.forEach(y => {
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(w, y);
        ctx.stroke();
      });
    }

    /**
     * Draw hover highlights
     */
    drawHoverHighlights(ctx) {
      const lane = this.hoveredLane;
      const seg = this.hoveredSegment;
      const y = this.laneYs[lane];
      const x = this.segmentXs[seg];
      
      ctx.fillStyle = 'rgba(255,225,132,0.12)';
      ctx.fillRect(14, y - 38, 186, 76);
      
      ctx.strokeStyle = 'rgba(255,225,132,0.5)';
      ctx.lineWidth = 2;
      ctx.strokeRect(x - 24, 40, 52, this.canvas.height - 80);
    }

    /**
     * Draw walls
     */
    drawWalls(ctx) {
      this.walls.forEach(w => {
        if (w.destroyed) return;
        
        const pos = this.getWallPosition(w);
        const hpRatio = w.hp / w.maxHp;
        
        const grad = ctx.createLinearGradient(pos.x - 12, pos.y - 20, pos.x + 12, pos.y + 20);
        grad.addColorStop(0, '#a8a8a8');
        grad.addColorStop(1, '#606060');
        ctx.fillStyle = grad;
        
        const width = 20 + Math.min(22, w.count * 1.6);
        ctx.fillRect(pos.x - width/2, pos.y - 20, width, 40);
        
        ctx.strokeStyle = 'rgba(255,255,255,0.2)';
        ctx.strokeRect(pos.x - width/2, pos.y - 20, width, 40);
        
        this.drawHpBar(ctx, pos.x - 22, pos.y + 24, 44, 4, hpRatio, '#ffae5c');
      });
    }

    /**
     * Draw buildings
     */
    drawBuildings(ctx) {
      this.buildings.forEach(b => {
        if (b.hidden && !b.revealed) return;
        
        const pos = this.getBuildingPosition(b);
        const hpRatio = b.hp / b.maxHp;
        
        ctx.save();
        ctx.globalAlpha = b.destroyed ? 0.3 : 1;
        ctx.translate(pos.x, pos.y);
        
        // Building colors by type
        let color = '#7d6530';
        if (b.kind === 'townhall') color = '#8a4a2a';
        else if (b.kind === 'defense') color = '#9a3040';
        else if (b.kind === 'resource') color = '#6a8040';
        
        // Building size
        const buildingW = b.kind === 'townhall' ? 64 : 52;
        const buildingH = b.kind === 'townhall' ? 60 : 50;
        
        ctx.fillStyle = color;
        ctx.shadowColor = 'rgba(0,0,0,0.3)';
        ctx.shadowBlur = 14;
        ctx.shadowOffsetY = 8;
        
        ctx.beginPath();
        ctx.roundRect(-buildingW/2, -buildingH/2, buildingW, buildingH, 12);
        ctx.fill();
        
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255,255,255,0.16)';
        ctx.stroke();
        
        // Frozen effect
        if (b.frozenUntil > this.time) {
          ctx.fillStyle = 'rgba(166,232,255,0.3)';
          ctx.fillRect(-buildingW/2 + 2, -buildingH/2 + 2, buildingW - 4, buildingH - 4);
        }
        
        // Draw building icon/image
        const img = this.imageCache[b.icon];
        const iconSize = b.kind === 'townhall' ? 56 : 44;
        if (img && img.complete && !img.error && img.naturalWidth > 0) {
          try {
            ctx.drawImage(img, -iconSize/2, -iconSize/2, iconSize, iconSize);
          } catch (e) {
            // Fallback text
            ctx.fillStyle = 'rgba(255,255,255,0.9)';
            ctx.font = '900 12px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText((b.name || '?').slice(0, 3), 0, 0);
          }
        } else {
          // Fallback text
          ctx.fillStyle = 'rgba(255,255,255,0.9)';
          ctx.font = '900 12px sans-serif';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText((b.name || '?').slice(0, 3), 0, 0);
        }
        
        ctx.restore();
        
        if (!b.destroyed) {
          this.drawHpBar(ctx, pos.x - buildingW/2, pos.y + buildingH/2 + 4, buildingW, 4, hpRatio, '#8ef56b');
        }
      });
    }

    /**
     * Draw units
     */
    drawUnits(ctx, now) {
      this.units.forEach(u => {
        if (u.dead && u.opacity <= 0) return;
        
        const yOffset = u.flying ? Math.sin(now * 5 + u.id) * 5 - 12 : 0;
        
        ctx.save();
        ctx.globalAlpha = u.opacity;
        ctx.translate(u.x, u.y + yOffset);
        
        // Status effects
        if (u.status.rageUntil > this.time) {
          ctx.fillStyle = 'rgba(198,120,255,0.2)';
          ctx.beginPath();
          ctx.arc(0, 0, 18, 0, Math.PI * 2);
          ctx.fill();
        }
        
        if (u.status.invulnerableUntil > this.time) {
          ctx.strokeStyle = 'rgba(255,255,255,0.8)';
          ctx.lineWidth = 2;
          ctx.beginPath();
          ctx.arc(0, 0, 16, 0, Math.PI * 2);
          ctx.stroke();
        }
        
        // Unit circle with larger size
        const unitSize = u.kind === 'hero' ? 28 : 20;
        const iconSize = u.kind === 'hero' ? 52 : 40;
        
        ctx.fillStyle = u.kind === 'hero' ? '#7e58ed' : (u.flying ? '#4b89f0' : '#2a5ca4');
        ctx.beginPath();
        ctx.arc(0, 0, unitSize, 0, Math.PI * 2);
        ctx.fill();
        
        const img = this.imageCache[u.icon];
        if (img && img.complete && !img.error && img.naturalWidth > 0) {
          try {
            ctx.drawImage(img, -iconSize/2, -iconSize/2, iconSize, iconSize);
          } catch (e) {
            console.warn('Failed to draw image:', u.icon, e);
            // Draw fallback text
            ctx.fillStyle = 'rgba(255,255,255,0.9)';
            ctx.font = '900 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText((u.name || '?').slice(0, 2), 0, 0);
          }
        }
        
        ctx.restore();
        
        // HP bar
        if (!u.dead) {
          this.drawHpBar(ctx, u.x - 13, u.y + (u.flying ? -16 : 14), 26, 3, u.hp / u.maxHp, '#79f58c');
        }
      });
    }

    /**
     * Draw spell effects
     */
    drawSpellEffects(ctx, now) {
      this.spellEffects.forEach(e => {
        const alpha = 0.18 + Math.sin(now * 6) * 0.06;
        ctx.save();
        ctx.globalAlpha = alpha;
        
        let color = '#ffcc00';
        if (e.type === 'rage') color = '#d070ff';
        else if (e.type === 'heal') color = '#70ff90';
        else if (e.type === 'freeze') color = '#70d0ff';
        
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(e.x, e.y, e.radius, 0, Math.PI * 2);
        ctx.stroke();
        
        ctx.fillStyle = color.replace(')', ', 0.1)').replace('rgb', 'rgba');
        ctx.fill();
        
        ctx.restore();
      });
    }

    /**
     * Draw projectiles
     */
    drawProjectiles(ctx) {
      this.projectiles = this.projectiles.filter(p => {
        const age = this.time - p.createdAt;
        if (age > p.life) return false;
        
        ctx.save();
        ctx.globalAlpha = 1 - (age / p.life);
        ctx.strokeStyle = p.color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(p.x1, p.y1);
        ctx.lineTo(p.x2, p.y2);
        ctx.stroke();
        ctx.restore();
        
        return true;
      });
    }

    /**
     * Draw particles
     */
    drawParticles(ctx) {
      this.particles.forEach(p => {
        ctx.save();
        ctx.globalAlpha = p.opacity;
        ctx.fillStyle = p.color;
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      });
    }

    /**
     * Draw HP bar
     */
    drawHpBar(ctx, x, y, width, height, ratio, color) {
      ctx.fillStyle = 'rgba(0,0,0,0.5)';
      ctx.fillRect(x, y, width, height);
      
      ctx.fillStyle = color;
      ctx.fillRect(x, y, width * ratio, height);
      
      ctx.strokeStyle = 'rgba(0,0,0,0.7)';
      ctx.lineWidth = 1;
      ctx.strokeRect(x, y, width, height);
    }

    /**
     * Feed message
     */
    feed(message) {
      if (this.callbacks.feed) {
        this.callbacks.feed(message);
      }
    }

    /**
     * On change callback
     */
    onChange() {
      if (this.callbacks.onChange) {
        this.callbacks.onChange();
      }
    }
  }

  // Export
  window.BattleScene = BattleScene;

})();
