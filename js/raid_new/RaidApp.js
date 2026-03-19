/**
 * RAID APP - Main Controller
 * Управляет всем циклом рейда: поиск → разведка → бой → результат
 */

(function() {
  'use strict';

  class RaidApp {
    constructor(root) {
      this.root = root;
      
      // State management
      this.state = {
        phase: 'loading',  // loading, search, scout, battle, result
        bootstrap: null,
        target: null,
        raid: null,
        scene: null,
        result: null,
        selectedCard: null,
        hoveredLane: 1,
        hoveredSegment: 2,
        timer: 0,
        scoutEndsAt: 0,
        searching: false
      };
      
      // DOM elements
      this.canvas = root.querySelector('#raid-scene-canvas');
      this.ctx = this.canvas ? this.canvas.getContext('2d', { alpha: false }) : null;
      
      // Systems
      this.searchManager = null;
      this.scoutScene = null;
      this.battleScene = null;
      this.resultScene = null;
      
      // Bind methods
      this._boundFit = () => this.fitViewport();
      this._boundFrame = (now) => this.frame(now);
      
      // Initialize
      this.bind();
      this.fitViewport();
    }

    /**
     * Bind event listeners
     */
    bind() {
      // Exit button
      const exitBtn = this.root.querySelector('#raid-exit-btn');
      if (exitBtn) {
        exitBtn.addEventListener('click', () => this.exit());
      }
      
      // Reroll button
      const rerollBtn = this.root.querySelector('#raid-reroll-btn');
      if (rerollBtn) {
        rerollBtn.addEventListener('click', () => this.search(true));
      }
      
      // Attack button
      const attackBtn = this.root.querySelector('#raid-attack-btn');
      if (attackBtn) {
        attackBtn.addEventListener('click', () => this.startBattle());
      }
      
      // End battle button
      const endBtn = this.root.querySelector('#raid-end-btn');
      if (endBtn) {
        endBtn.addEventListener('click', () => this.endBattle());
      }
      
      // Hero ability button
      const abilityBtn = this.root.querySelector('#raid-ability-btn');
      if (abilityBtn) {
        abilityBtn.addEventListener('click', () => this.activateHeroAbility());
      }
      
      // Canvas interactions
      if (this.canvas) {
        this.canvas.addEventListener('mousemove', (e) => this.onCanvasMove(e));
        this.canvas.addEventListener('click', (e) => this.onCanvasClick(e));
        
        // Touch support
        this.canvas.addEventListener('touchmove', (e) => {
          e.preventDefault();
          const touch = e.touches[0];
          this.onCanvasMove({ 
            clientX: touch.clientX, 
            clientY: touch.clientY 
          });
        }, { passive: false });
        
        this.canvas.addEventListener('touchstart', (e) => {
          e.preventDefault();
          const touch = e.touches[0];
          this.onCanvasClick({ 
            clientX: touch.clientX, 
            clientY: touch.clientY 
          });
        }, { passive: false });
      }
      
      // Window resize
      window.addEventListener('resize', this._boundFit);
      window.addEventListener('orientationchange', this._boundFit);
    }

    /**
     * Fit viewport to screen
     */
    fitViewport() {
      const frame = this.root.querySelector('.raid-viewport-frame');
      if (!frame) return;
      
      const baseW = 820;
      const baseH = 560;
      const isMobile = window.innerWidth <= 540;
      const safeW = isMobile ? window.innerWidth : window.innerWidth - 32;
      const safeH = isMobile ? window.innerHeight : window.innerHeight - 32;
      
      const scale = Math.min(1, safeW / baseW, safeH / baseH);
      frame.style.transform = `scale(${scale})`;
    }

    /**
     * Mount the app
     */
    async mount() {
      console.log('🎮 RaidApp.mount() called');
      
      try {
        // Check canvas
        if (!this.canvas) {
          throw new Error('Canvas element not found');
        }
        if (!this.ctx) {
          throw new Error('Cannot get 2D context from canvas');
        }
        
        console.log('✅ Canvas ready:', this.canvas.width, 'x', this.canvas.height);
        
        console.log('🎮 Mount: Starting...');
        this.feed('Инициализация боевой системы...');
        this.setOverlay('Загрузка', 'Подготовка к бою...', true);
        
        // Initialize search manager
        if (window.RaidSearchManager) {
          console.log('✅ Initializing RaidSearchManager');
          this.searchManager = new window.RaidSearchManager(this.canvas, this.ctx);
        } else {
          console.warn('⚠️ RaidSearchManager not found');
        }
        
        // Bootstrap data
        console.log('📡 Fetching bootstrap data...');
        const boot = await window.RaidApi.bootstrap();
        console.log('✅ Bootstrap data received:', boot);
        this.state.bootstrap = boot;
        
        this.feed('Система боя готова!');
        console.log('📊 Rendering player and army...');
        this.renderPlayer(boot.player, boot.next_cost);
        this.renderArmyStrip(boot.army);
        
        // Start search
        console.log('🔍 Starting opponent search...');
        setTimeout(() => {
          this.search(false);
        }, 500);
        
        // Start render loop
        console.log('🎬 Starting render loop...');
        this.startLoop();
        
        console.log('✅ Mount complete!');
        
      } catch (error) {
        console.error('❌ Raid mount failed:', error);
        console.error('Stack:', error.stack);
        this.setOverlay('Ошибка', error.message || 'Не удалось запустить бой', true);
        this.feed('❌ ' + (error.message || 'Ошибка запуска'));
      }
    }

    /**
     * Search for opponent
     */
    async search(reroll = false) {
      if (this.state.searching) return;
      
      this.state.searching = true;
      this.state.phase = 'search';
      this.setPhase('search', 'Поиск', reroll ? 'Меняем цель' : 'Ищем противника');
      this.setOverlay('Поиск противника', 'Облака рассеиваются...', true);
      
      // Hide target chip during search
      this.hideElement('#raid-target-chip');
      this.hideElement('#raid-scout-panels');
      
      try {
        // Start cloud animation
        if (this.searchManager) {
          this.searchManager.startSearch();
        }
        
        // Search opponent
        const data = await window.RaidApi.searchOpponent(!!reroll);
        this.state.bootstrap = data;
        this.state.target = data.target;
        
        this.renderPlayer(data.player, data.next_cost);
        this.renderArmyStrip(data.army);
        this.renderTarget(data.target);
        
        this.feed(reroll ? '🔄 Новая цель найдена!' : '🎯 Противник обнаружен!');
        
        // Transition to scout
        setTimeout(() => {
          this.enterScout();
        }, 1200);
        
      } catch (error) {
        console.error('Search failed:', error);
        this.feed('❌ ' + (error.message || 'Не удалось найти цель'));
        this.setOverlay('Ошибка поиска', error.message || 'Попробуйте еще раз', true);
      } finally {
        this.state.searching = false;
      }
    }

    /**
     * Enter scout phase
     */
    enterScout() {
      this.state.phase = 'scout';
      this.state.timer = 30;
      this.state.scoutEndsAt = performance.now() + 30000;
      
      this.setPhase('scout', 'Разведка', '30 секунд на осмотр');
      this.setOverlay('Разведка началась', 'Изучите базу и подготовьте план атаки', false);
      
      // Show UI elements
      this.showElement('#raid-target-chip');
      this.showElement('#raid-scout-panels');
      
      // Enable attack button
      const attackBtn = this.root.querySelector('#raid-attack-btn');
      if (attackBtn) attackBtn.disabled = false;
      
      // Update threats
      this.updateThreats();
      
      // Initialize scout scene
      if (window.ScoutScene && this.state.target) {
        this.scoutScene = new window.ScoutScene(
          this.state.target,
          this.canvas,
          this.ctx,
          (lane, seg) => {
            this.state.hoveredLane = lane;
            this.state.hoveredSegment = seg;
          }
        );
      }
      
      this.feed('🔍 Разведка: изучите оборону противника');
    }

    /**
     * Start battle
     */
    async startBattle() {
      if (!this.state.target) return;
      
      try {
        this.feed('⚔️ Начинаем штурм!');
        this.setOverlay('Начало боя', 'Войска высаживаются...', true);
        
        const data = await window.RaidApi.startRaid(this.state.target.user_id);
        this.state.raid = data.raid;
        this.state.phase = 'battle';
        this.state.timer = 180;
        this.state.scoutEndsAt = 0;
        
        // Hide scout UI
        this.hideElement('#raid-scout-panels');
        
        // Show battle UI
        this.showElement('#raid-end-btn');
        this.showElement('#raid-ability-btn');
        
        // Disable attack button
        const attackBtn = this.root.querySelector('#raid-attack-btn');
        if (attackBtn) {
          attackBtn.disabled = true;
          attackBtn.classList.add('raid-hidden');
        }
        
        const endBtn = this.root.querySelector('#raid-end-btn');
        if (endBtn) endBtn.disabled = false;
        
        const abilityBtn = this.root.querySelector('#raid-ability-btn');
        if (abilityBtn) {
          abilityBtn.disabled = !data.raid.army.heroes?.length;
        }
        
        // Initialize battle scene
        if (window.BattleScene) {
          this.battleScene = new window.BattleScene(
            data.raid,
            this.canvas,
            this.ctx,
            {
              feed: (msg) => this.feed(msg),
              onChange: () => this.onBattleChange()
            }
          );
        }
        
        this.setPhase('battle', 'Бой', '3 минуты на штурм');
        setTimeout(() => {
          this.setOverlay('', '', false);
        }, 1500);
        
        this.renderArmyStrip(data.raid.army);
        
      } catch (error) {
        console.error('Start battle failed:', error);
        this.feed('❌ ' + (error.message || 'Не удалось начать бой'));
        this.setOverlay('Ошибка', error.message || 'Попробуйте еще раз', true);
      }
    }

    /**
     * End battle
     */
    async endBattle() {
      if (!this.battleScene || this.state.result) return;
      
      try {
        this.setOverlay('Подведение итогов', 'Подсчитываем результаты...', true);
        
        const result = this.battleScene.getResult();
        const data = await window.RaidApi.resolveRaid(this.state.raid.id, result);
        this.state.result = data.result;
        this.state.phase = 'result';
        
        // Show result
        this.showResult(data.result);
        
      } catch (error) {
        console.error('End battle failed:', error);
        this.feed('❌ ' + (error.message || 'Ошибка завершения боя'));
      }
    }

    /**
     * Show battle result
     */
    showResult(result) {
      const stars = '★'.repeat(result.stars) + '☆'.repeat(3 - result.stars);
      
      this.setPhase('result', result.stars >= 2 ? 'Победа! 🎉' : 'Результат', result.target.login);
      this.setOverlay(
        `${stars} ${result.destructionPercent}%`,
        `+${this.fmt(result.loot.gold)} 🪙  +${this.fmt(result.loot.elixir)} 🧪  +${this.fmt(result.loot.dark_elixir)} ⚫  ${result.trophyDelta >= 0 ? '+' : ''}${result.trophyDelta} 🏆`,
        true
      );
      
      // Disable buttons
      const abilityBtn = this.root.querySelector('#raid-ability-btn');
      const endBtn = this.root.querySelector('#raid-end-btn');
      if (abilityBtn) abilityBtn.disabled = true;
      if (endBtn) endBtn.disabled = true;
      
      this.feed(`🎖️ Бой завершён: ${stars}`);
    }

    /**
     * Activate hero ability
     */
    activateHeroAbility() {
      if (!this.battleScene) return;
      
      const activated = this.battleScene.activateHeroAbility();
      if (activated) {
        this.feed('⚡ Способность героя активирована!');
        this.renderArmyStrip(this.state.raid.army);
      }
    }

    /**
     * Canvas mouse move
     */
    onCanvasMove(e) {
      const rect = this.canvas.getBoundingClientRect();
      const x = (e.clientX - rect.left) * (this.canvas.width / rect.width);
      const y = (e.clientY - rect.top) * (this.canvas.height / rect.height);
      
      // Calculate hovered lane and segment
      const lane = Math.max(0, Math.min(2, Math.round((y - 110) / 108)));
      const segment = Math.max(0, Math.min(4, Math.round((x - 392) / 56)));
      
      this.state.hoveredLane = lane;
      this.state.hoveredSegment = segment;
      
      if (this.scoutScene) {
        this.scoutScene.setHover(lane, segment);
      }
      
      if (this.battleScene) {
        this.battleScene.setHover(lane, segment);
      }
    }

    /**
     * Canvas click
     */
    onCanvasClick(e) {
      if (this.state.phase !== 'battle' || !this.battleScene) return;
      
      const card = this.state.selectedCard;
      if (!card) return;
      
      if (card.kind === 'spell') {
        this.battleScene.castSpell(card, this.state.hoveredLane, this.state.hoveredSegment);
      } else {
        this.battleScene.spawnUnit(card, this.state.hoveredLane);
      }
      
      this.renderArmyStrip(this.state.raid.army);
    }

    /**
     * Battle change callback
     */
    onBattleChange() {
      this.renderArmyStrip(this.state.raid.army);
      this.updateBattleStats();
    }

    /**
     * Update battle stats
     */
    updateBattleStats() {
      if (!this.battleScene) return;
      
      const stats = this.battleScene.getStats();
      
      const destructionEl = this.root.querySelector('#raid-destruction');
      const starsEl = this.root.querySelector('#raid-stars');
      
      if (destructionEl) {
        destructionEl.textContent = `${stats.destructionPercent}%`;
        if (stats.destructionPercent > parseInt(destructionEl.dataset.prev || 0)) {
          destructionEl.classList.add('pulse');
          setTimeout(() => destructionEl.classList.remove('pulse'), 400);
        }
        destructionEl.dataset.prev = stats.destructionPercent;
      }
      
      if (starsEl) {
        starsEl.textContent = `${stats.stars}★`;
        if (stats.stars > parseInt(starsEl.dataset.prev || 0)) {
          starsEl.classList.add('pulse');
          setTimeout(() => starsEl.classList.remove('pulse'), 400);
        }
        starsEl.dataset.prev = stats.stars;
      }
    }

    /**
     * Render player info
     */
    renderPlayer(player, nextCost) {
      const costEl = this.root.querySelector('#raid-reroll-cost');
      if (costEl) {
        costEl.textContent = `за ${this.fmt(nextCost || 0)} золота`;
      }
    }

    /**
     * Render target info
     */
    renderTarget(target) {
      const nameEl = this.root.querySelector('#raid-target-name');
      const subEl = this.root.querySelector('#raid-target-sub');
      const lootEl = this.root.querySelector('#raid-target-loot');
      
      if (nameEl) nameEl.textContent = target.login;
      if (subEl) subEl.textContent = `Ратуша ${target.townhall_level} • ${target.trophies} 🏆`;
      
      if (lootEl) {
        lootEl.innerHTML = '';
        const resources = [
          ['gold', '/images/icons/gold.png'],
          ['elixir', '/images/icons/elixir.png'],
          ['dark_elixir', '/images/icons/dark_elixir.png']
        ];
        
        resources.forEach(([key, icon]) => {
          const chip = document.createElement('div');
          chip.className = 'raid-loot-chip';
          chip.innerHTML = `<img src="${icon}" alt=""><span>${this.fmt(target.resources[key] || 0)}</span>`;
          lootEl.appendChild(chip);
        });
      }
    }

    /**
     * Render army strip
     */
    renderArmyStrip(army) {
      const strip = this.root.querySelector('#raid-army-strip');
      if (!strip) return;
      
      const roster = this.battleScene ? this.battleScene.getRoster() : {
        troops: (army.troops || []).map(x => ({ ...x, remaining: x.count })),
        heroes: (army.heroes || []).map(x => ({ ...x, remaining: 1 })),
        spells: (army.spells || []).map(x => ({ ...x, remaining: x.count }))
      };
      
      strip.innerHTML = '';
      
      const allCards = [...roster.troops, ...roster.heroes, ...roster.spells];
      
      allCards.forEach(card => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'raid-army-card';
        btn.dataset.cardId = card.id;
        
        if (this.state.selectedCard && this.state.selectedCard.id === card.id) {
          btn.classList.add('selected');
        }
        
        if ((card.remaining || 0) <= 0) {
          btn.classList.add('disabled');
        }
        
        btn.innerHTML = `
          <span class="qty">${card.remaining || 0}</span>
          <img src="${card.icon}" alt="${card.name}">
          <div class="nm">${card.name}</div>
          <div class="lvl">ур. ${card.level}</div>
        `;
        
        btn.addEventListener('click', () => {
          if ((card.remaining || 0) <= 0 || this.state.phase !== 'battle') return;
          
          this.state.selectedCard = card;
          if (this.battleScene) {
            this.battleScene.selectCard(card.id);
          }
          this.renderArmyStrip(army);
        });
        
        strip.appendChild(btn);
      });
    }

    /**
     * Update threats
     */
    updateThreats() {
      const list = this.root.querySelector('#raid-threat-list');
      if (!list || !this.state.target) return;
      
      list.innerHTML = '';
      
      const threats = (this.state.target.base.buildings || [])
        .filter(b => ['defense', 'townhall', 'trap'].includes(b.kind))
        .sort((a, b) => (b.priorityWeight || 0) - (a.priorityWeight || 0))
        .slice(0, 6);
      
      threats.forEach(t => {
        const pill = document.createElement('div');
        pill.className = 'raid-threat-pill';
        pill.textContent = t.name;
        list.appendChild(pill);
      });
    }

    /**
     * Set phase
     */
    setPhase(phase, label, title) {
      this.state.phase = phase;
      
      const labelEl = this.root.querySelector('#raid-phase-label');
      const titleEl = this.root.querySelector('#raid-phase-title');
      
      if (labelEl) labelEl.textContent = label;
      if (titleEl) titleEl.textContent = title;
    }

    /**
     * Set overlay
     */
    setOverlay(title, sub, show) {
      const overlay = this.root.querySelector('#raid-scene-overlay');
      const titleEl = this.root.querySelector('#raid-overlay-title');
      const subEl = this.root.querySelector('#raid-overlay-sub');
      
      if (titleEl) titleEl.textContent = title;
      if (subEl) subEl.textContent = sub;
      if (overlay) overlay.classList.toggle('show', !!show);
    }

    /**
     * Feed message
     */
    feed(message) {
      const feed = this.root.querySelector('#raid-event-feed');
      if (!feed) return;
      
      const item = document.createElement('div');
      item.className = 'raid-feed-item';
      item.textContent = message;
      feed.prepend(item);
      
      // Keep only last 5 items
      while (feed.children.length > 5) {
        feed.lastElementChild.remove();
      }
    }

    /**
     * Start render loop
     */
    startLoop() {
      let lastTime = performance.now();
      
      const tick = (now) => {
        const dt = Math.min(0.05, (now - lastTime) / 1000);
        lastTime = now;
        
        // Update timer
        if (this.state.phase === 'scout' && this.state.scoutEndsAt) {
          this.state.timer = Math.max(0, Math.ceil((this.state.scoutEndsAt - now) / 1000));
          if (this.state.timer <= 0) {
            this.startBattle();
          }
        }
        
        if (this.state.phase === 'battle' && this.battleScene) {
          this.battleScene.update(dt);
          this.state.timer = Math.max(0, Math.ceil(this.battleScene.getRemainingTime()));
          
          if (this.battleScene.isEnded()) {
            this.endBattle();
          }
        }
        
        // Update timer display
        const timerEl = this.root.querySelector('#raid-timer');
        if (timerEl) {
          timerEl.textContent = this.state.phase === 'loading' ? '--:--' : this.formatTime(this.state.timer);
        }
        
        // Render
        if (this.searchManager && this.state.phase === 'search') {
          this.searchManager.render(now / 1000);
        } else if (this.scoutScene && this.state.phase === 'scout') {
          this.scoutScene.render(now / 1000);
        } else if (this.battleScene && this.state.phase === 'battle') {
          this.battleScene.render(now / 1000);
        } else {
          // Clear canvas when idle
          if (this.ctx) {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            
            // Draw simple background
            const grad = this.ctx.createLinearGradient(0, 0, 0, this.canvas.height);
            grad.addColorStop(0, '#a8ecff');
            grad.addColorStop(0.32, '#7dcaf6');
            grad.addColorStop(0.325, '#95da6d');
            grad.addColorStop(1, '#6dac4d');
            this.ctx.fillStyle = grad;
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
          }
        }
        
        requestAnimationFrame(tick);
      };
      
      requestAnimationFrame(tick);
    }

    /**
     * Format time MM:SS
     */
    formatTime(seconds) {
      seconds = Math.max(0, Math.floor(seconds));
      const m = String(Math.floor(seconds / 60)).padStart(2, '0');
      const s = String(seconds % 60).padStart(2, '0');
      return `${m}:${s}`;
    }

    /**
     * Format number with spaces
     */
    fmt(n) {
      return (parseInt(n, 10) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    /**
     * Show element
     */
    showElement(selector) {
      const el = this.root.querySelector(selector);
      if (el) el.classList.remove('raid-hidden');
    }

    /**
     * Hide element
     */
    hideElement(selector) {
      const el = this.root.querySelector(selector);
      if (el) el.classList.add('raid-hidden');
    }

    /**
     * Exit raid
     */
    exit() {
      // Remove blur effect
      document.body.classList.remove('raid-active');
      
      const homeBtn = document.querySelector('[data-page="home"]');
      if (homeBtn) {
        homeBtn.click();
      } else {
        window.location.href = '/';
      }
    }
  }

  // Export
  window.RaidApp = RaidApp;

})();
