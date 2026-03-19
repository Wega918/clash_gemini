/**
 * SCOUT SCENE - Экран разведки базы противника
 * Показывает вражескую базу с визуализацией угроз
 */

(function() {
  'use strict';

  class ScoutScene {
    constructor(target, canvas, ctx, onHoverChange) {
      this.target = target;
      this.canvas = canvas;
      this.ctx = ctx;
      this.onHoverChange = onHoverChange || (() => {});
      
      // State
      this.hoveredLane = 1;
      this.hoveredSegment = 2;
      this.time = 0;
      
      // Lane and segment positions
      this.laneYs = [110, 220, 330];
      this.segmentXs = [390, 446, 502, 558, 614];
      
      // Image cache
      this.imageCache = {};
      this.preloadImages();
    }

    /**
     * Preload building and wall images
     */
    preloadImages() {
      const urls = new Set();
      
      (this.target.base.buildings || []).forEach(b => {
        if (b.icon) urls.add(b.icon);
      });
      
      (this.target.base.walls || []).forEach(w => {
        if (w.icon) urls.add(w.icon);
      });
      
      urls.forEach(url => {
        const img = new Image();
        img.onerror = () => {
          console.warn('⚠️ Failed to load image:', url);
          this.imageCache[url] = null;
        };
        img.src = url;
        this.imageCache[url] = img;
      });
    }

    /**
     * Set hovered position
     */
    setHover(lane, segment) {
      this.hoveredLane = lane;
      this.hoveredSegment = segment;
      this.onHoverChange(lane, segment);
    }

    /**
     * Render scout scene
     */
    render(now) {
      this.time = now;
      const ctx = this.ctx;
      const w = this.canvas.width;
      const h = this.canvas.height;
      
      // Clear
      ctx.clearRect(0, 0, w, h);
      
      // Background
      this.drawBackground(ctx, w, h);
      
      // Deployment zones (left side)
      this.drawDeploymentZones(ctx);
      
      // Hover highlights
      this.drawHoverHighlights(ctx);
      
      // Base structures
      this.drawWalls(ctx);
      this.drawBuildings(ctx);
      
      // Threat indicators
      this.drawThreatIndicators(ctx);
      
      // Info text
      this.drawInfoText(ctx, w, h);
    }

    /**
     * Draw background
     */
    drawBackground(ctx, w, h) {
      // Sky gradient
      const skyGrad = ctx.createLinearGradient(0, 0, 0, h);
      skyGrad.addColorStop(0, '#a8ecff');
      skyGrad.addColorStop(0.32, '#7dcaf6');
      skyGrad.addColorStop(0.325, '#95da6d');
      skyGrad.addColorStop(1, '#6dac4d');
      ctx.fillStyle = skyGrad;
      ctx.fillRect(0, 0, w, h);
      
      // Animated clouds
      ctx.fillStyle = 'rgba(255,255,255,0.16)';
      for (let i = 0; i < 5; i++) {
        const x = (i * 160 + (this.time * 8) % 180) - 40;
        const y = 50 + Math.sin(this.time + i) * 6;
        ctx.beginPath();
        ctx.arc(x, y, 24, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(x + 26, y - 4, 20, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    /**
     * Draw deployment zones
     */
    drawDeploymentZones(ctx) {
      const h = this.canvas.height;
      const w = this.canvas.width;
      
      // Left side deployment area
      ctx.fillStyle = 'rgba(100,150,255,0.08)';
      ctx.fillRect(18, 36, 180, h - 72);
      
      // Lane dividers
      this.laneYs.forEach((y, idx) => {
        // Lane background
        ctx.fillStyle = 'rgba(255,255,255,0.04)';
        ctx.fillRect(18, y - 36, 180, 72);
        
        // Lane center indicator
        ctx.fillStyle = 'rgba(80,140,220,0.85)';
        ctx.beginPath();
        ctx.arc(70, y, 12, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.fillStyle = 'rgba(255,255,255,0.9)';
        ctx.font = '900 11px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(['↑', '→', '↓'][idx], 70, y);
        
        // Lane divider line
        if (idx < this.laneYs.length - 1) {
          ctx.strokeStyle = 'rgba(255,255,255,0.08)';
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(0, y + 36);
          ctx.lineTo(w, y + 36);
          ctx.stroke();
        }
      });
    }

    /**
     * Draw hover highlights
     */
    drawHoverHighlights(ctx) {
      const h = this.canvas.height;
      const lane = Math.max(0, Math.min(2, this.hoveredLane));
      const seg = Math.max(0, Math.min(4, this.hoveredSegment));
      
      // Lane highlight
      const y = this.laneYs[lane];
      ctx.fillStyle = 'rgba(255,255,255,0.06)';
      ctx.fillRect(14, y - 38, 186, 76);
      
      // Segment highlight
      const x = this.segmentXs[seg];
      ctx.strokeStyle = 'rgba(255,225,132,0.6)';
      ctx.lineWidth = 2;
      ctx.strokeRect(x - 24, 40, 52, h - 80);
      
      // Crosshair at intersection
      ctx.fillStyle = 'rgba(255,225,132,0.3)';
      ctx.beginPath();
      ctx.arc(x, y, 8, 0, Math.PI * 2);
      ctx.fill();
    }

    /**
     * Draw walls
     */
    drawWalls(ctx) {
      (this.target.base.walls || []).forEach(wall => {
        if (wall.destroyed) return;
        
        const x = this.segmentXs[wall.segment];
        const y = this.laneYs[wall.lane];
        const hpRatio = Math.max(0, wall.hp / wall.maxHp);
        
        // Wall body
        const wallGrad = ctx.createLinearGradient(x - 12, y - 20, x + 12, y + 20);
        wallGrad.addColorStop(0, '#b0b0b0');
        wallGrad.addColorStop(1, '#707070');
        ctx.fillStyle = wallGrad;
        
        const width = 22 + Math.min(20, wall.count * 1.5);
        ctx.fillRect(x - width/2, y - 22, width, 44);
        
        // Border
        ctx.strokeStyle = 'rgba(255,255,255,0.24)';
        ctx.lineWidth = 1;
        ctx.strokeRect(x - width/2, y - 22, width, 44);
        
        // Cracks
        if (hpRatio < 0.7) {
          ctx.strokeStyle = 'rgba(100,80,70,0.6)';
          ctx.lineWidth = 1.5;
          ctx.beginPath();
          ctx.moveTo(x - 6, y - 14);
          ctx.lineTo(x + 2, y + 4);
          ctx.lineTo(x + 6, y + 18);
          ctx.stroke();
        }
        
        if (hpRatio < 0.4) {
          ctx.beginPath();
          ctx.moveTo(x + 8, y - 12);
          ctx.lineTo(x - 4, y + 6);
          ctx.lineTo(x + 4, y + 16);
          ctx.stroke();
        }
        
        // HP bar
        this.drawHpBar(ctx, x - 24, y + 26, 48, 5, hpRatio, '#ffae5c');
      });
    }

    /**
     * Draw buildings
     */
    drawBuildings(ctx) {
      (this.target.base.buildings || []).forEach(building => {
        if (building.hidden && !building.revealed) return;
        if (building.destroyed) return;
        
        const x = this.segmentXs[building.segment] + (building.segment === 4 ? 12 : 0);
        const y = this.laneYs[building.lane] + (building.kind === 'defense' ? -22 : (building.kind === 'resource' ? 22 : 0));
        const hpRatio = Math.max(0, building.hp / building.maxHp);
        
        ctx.save();
        ctx.translate(x, y);
        
        // Building color by type
        let buildingColor = '#7d6530';
        if (building.kind === 'townhall') buildingColor = '#8a4a2a';
        else if (building.kind === 'defense') buildingColor = '#9a3040';
        else if (building.kind === 'resource') buildingColor = '#6a8040';
        
        // Building size - LARGER
        const buildingW = building.kind === 'townhall' ? 64 : 52;
        const buildingH = building.kind === 'townhall' ? 60 : 50;
        const iconSize = building.kind === 'townhall' ? 56 : 44;
        
        // Building body
        const buildingGrad = ctx.createLinearGradient(0, -buildingH/2, 0, buildingH/2);
        buildingGrad.addColorStop(0, buildingColor);
        buildingGrad.addColorStop(1, this.darkenColor(buildingColor, 0.7));
        ctx.fillStyle = buildingGrad;
        
        ctx.shadowColor = 'rgba(0,0,0,0.32)';
        ctx.shadowBlur = 18;
        ctx.shadowOffsetY = 10;
        
        ctx.beginPath();
        ctx.roundRect(-buildingW/2, -buildingH/2, buildingW, buildingH, 14);
        ctx.fill();
        
        // Border
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255,255,255,0.18)';
        ctx.lineWidth = 1;
        ctx.stroke();
        
        // Icon or name
        const img = this.imageCache[building.icon];
        if (img && img.complete && !img.error && img.naturalWidth > 0) {
          try {
            ctx.drawImage(img, -iconSize/2, -iconSize/2, iconSize, iconSize);
          } catch (e) {
            console.warn('Failed to draw image:', building.icon, e);
            // Draw fallback
            ctx.fillStyle = 'rgba(255,255,255,0.9)';
            ctx.font = '900 12px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText((building.name || '?').slice(0, 3), 0, 0);
          }
        } else {
          ctx.fillStyle = 'rgba(255,255,255,0.9)';
          ctx.font = '900 12px sans-serif';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText((building.name || '?').slice(0, 3), 0, 0);
        }
        
        // Level badge
        if (building.level) {
          ctx.fillStyle = 'rgba(0,0,0,0.7)';
          ctx.beginPath();
          ctx.roundRect(12, -20, 16, 14, 4);
          ctx.fill();
          
          ctx.fillStyle = '#ffffff';
          ctx.font = '700 9px sans-serif';
          ctx.textAlign = 'center';
          ctx.fillText(building.level, 20, -13);
        }
        
        ctx.restore();
        
        // HP bar
        this.drawHpBar(ctx, x - 26, y + 30, 52, 5, hpRatio, '#8ef56b');
      });
    }

    /**
     * Draw threat indicators
     */
    drawThreatIndicators(ctx) {
      const threats = (this.target.base.buildings || [])
        .filter(b => b.kind === 'defense' && !b.destroyed)
        .sort((a, b) => (b.priorityWeight || 0) - (a.priorityWeight || 0))
        .slice(0, 10);
      
      threats.forEach(threat => {
        const x = this.segmentXs[threat.segment] + (threat.segment === 4 ? 12 : 0);
        const y = this.laneYs[threat.lane] - 22;
        
        // Pulsing danger ring
        const pulseSize = 32 + Math.sin(this.time * 3) * 4;
        
        ctx.save();
        ctx.globalAlpha = 0.4 + Math.sin(this.time * 3) * 0.2;
        ctx.strokeStyle = '#ff6060';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(x, y, pulseSize, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
      });
    }

    /**
     * Draw HP bar
     */
    drawHpBar(ctx, x, y, width, height, ratio, color) {
      // Background
      ctx.fillStyle = 'rgba(0,0,0,0.4)';
      ctx.fillRect(x, y, width, height);
      
      // Fill
      const fillWidth = width * ratio;
      const barGrad = ctx.createLinearGradient(x, y, x, y + height);
      barGrad.addColorStop(0, color);
      barGrad.addColorStop(1, this.darkenColor(color, 0.7));
      ctx.fillStyle = barGrad;
      ctx.fillRect(x, y, fillWidth, height);
      
      // Border
      ctx.strokeStyle = 'rgba(0,0,0,0.6)';
      ctx.lineWidth = 1;
      ctx.strokeRect(x, y, width, height);
    }

    /**
     * Draw info text
     */
    drawInfoText(ctx, w, h) {
      ctx.save();
      ctx.fillStyle = 'rgba(255,255,255,0.85)';
      ctx.font = '700 13px sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'bottom';
      ctx.shadowColor = 'rgba(0,0,0,0.6)';
      ctx.shadowBlur = 8;
      ctx.fillText('← Выберите линию для атаки', w / 2, h - 10);
      ctx.restore();
    }

    /**
     * Darken color
     */
    darkenColor(color, factor) {
      const hex = color.replace('#', '');
      const r = parseInt(hex.substr(0, 2), 16);
      const g = parseInt(hex.substr(2, 2), 16);
      const b = parseInt(hex.substr(4, 2), 16);
      
      const nr = Math.floor(r * factor);
      const ng = Math.floor(g * factor);
      const nb = Math.floor(b * factor);
      
      return `#${nr.toString(16).padStart(2, '0')}${ng.toString(16).padStart(2, '0')}${nb.toString(16).padStart(2, '0')}`;
    }
  }

  // Export
  window.ScoutScene = ScoutScene;

})();
