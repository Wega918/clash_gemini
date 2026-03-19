/**
 * RAID SEARCH MANAGER
 * Красивый облачный эффект поиска противника с улучшенными анимациями
 */

(function() {
  'use strict';

  class RaidSearchManager {
    constructor(canvas, ctx) {
      this.canvas = canvas;
      this.ctx = ctx;
      
      // Cloud particles
      this.clouds = [];
      this.maxClouds = 28;
      
      // Fog particles
      this.fogParticles = [];
      this.maxFog = 45;
      
      // Sparkles
      this.sparkles = [];
      this.maxSparkles = 20;
      
      // Animation state
      this.time = 0;
      this.phase = 'idle'; // idle, searching, revealing
      
      this.init();
    }

    /**
     * Initialize particles
     */
    init() {
      // Create clouds
      for (let i = 0; i < this.maxClouds; i++) {
        this.clouds.push(this.createCloud());
      }
      
      // Create fog
      for (let i = 0; i < this.maxFog; i++) {
        this.fogParticles.push(this.createFogParticle());
      }
    }

    /**
     * Create cloud particle
     */
    createCloud() {
      return {
        x: Math.random() * this.canvas.width,
        y: Math.random() * this.canvas.height,
        radius: 30 + Math.random() * 50,
        speedX: (Math.random() - 0.5) * 30,
        speedY: (Math.random() - 0.5) * 15,
        opacity: 0.15 + Math.random() * 0.25,
        phase: Math.random() * Math.PI * 2,
        wobble: Math.random() * 0.5
      };
    }

    /**
     * Create fog particle
     */
    createFogParticle() {
      return {
        x: Math.random() * this.canvas.width,
        y: Math.random() * this.canvas.height,
        radius: 5 + Math.random() * 15,
        speedX: (Math.random() - 0.5) * 20,
        speedY: (Math.random() - 0.5) * 10,
        opacity: 0.08 + Math.random() * 0.12,
        life: 1.0
      };
    }

    /**
     * Create sparkle
     */
    createSparkle() {
      return {
        x: Math.random() * this.canvas.width,
        y: Math.random() * this.canvas.height,
        size: 2 + Math.random() * 4,
        speedY: -20 - Math.random() * 40,
        opacity: 1.0,
        life: 1.0,
        color: Math.random() > 0.5 ? '#ffffff' : '#ffe58f'
      };
    }

    /**
     * Start search animation
     */
    startSearch() {
      this.phase = 'searching';
      
      // Add more clouds
      while (this.clouds.length < this.maxClouds) {
        this.clouds.push(this.createCloud());
      }
    }

    /**
     * Reveal target (fade out clouds)
     */
    reveal() {
      this.phase = 'revealing';
    }

    /**
     * Update particles
     */
    update(dt) {
      this.time += dt;
      
      // Update clouds
      this.clouds.forEach((cloud, index) => {
        // Movement
        cloud.x += cloud.speedX * dt;
        cloud.y += cloud.speedY * dt + Math.sin(this.time * 2 + cloud.phase) * cloud.wobble;
        
        // Wrap around
        if (cloud.x < -cloud.radius * 2) cloud.x = this.canvas.width + cloud.radius;
        if (cloud.x > this.canvas.width + cloud.radius * 2) cloud.x = -cloud.radius;
        if (cloud.y < -cloud.radius * 2) cloud.y = this.canvas.height + cloud.radius;
        if (cloud.y > this.canvas.height + cloud.radius * 2) cloud.y = -cloud.radius;
        
        // Fade effect
        if (this.phase === 'revealing') {
          cloud.opacity *= 0.96;
          if (cloud.opacity < 0.01) {
            this.clouds.splice(index, 1);
          }
        } else if (this.phase === 'searching') {
          const targetOpacity = 0.18 + Math.sin(this.time * 3 + cloud.phase) * 0.06;
          cloud.opacity += (targetOpacity - cloud.opacity) * 0.05;
        }
      });
      
      // Update fog
      this.fogParticles.forEach((fog, index) => {
        fog.x += fog.speedX * dt;
        fog.y += fog.speedY * dt;
        fog.life -= dt * 0.3;
        fog.opacity = fog.life * 0.12;
        
        if (fog.life <= 0 || fog.x < 0 || fog.x > this.canvas.width || fog.y < 0 || fog.y > this.canvas.height) {
          this.fogParticles[index] = this.createFogParticle();
        }
      });
      
      // Update sparkles
      if (this.phase === 'searching' && Math.random() < 0.15) {
        this.sparkles.push(this.createSparkle());
      }
      
      this.sparkles.forEach((sparkle, index) => {
        sparkle.y += sparkle.speedY * dt;
        sparkle.life -= dt * 1.5;
        sparkle.opacity = sparkle.life;
        
        if (sparkle.life <= 0) {
          this.sparkles.splice(index, 1);
        }
      });
      
      // Limit sparkles
      while (this.sparkles.length > this.maxSparkles) {
        this.sparkles.shift();
      }
    }

    /**
     * Render
     */
    render(now) {
      const ctx = this.ctx;
      const w = this.canvas.width;
      const h = this.canvas.height;
      
      // Clear
      ctx.clearRect(0, 0, w, h);
      
      // Background gradient
      const bgGrad = ctx.createLinearGradient(0, 0, 0, h);
      bgGrad.addColorStop(0, '#9fe6ff');
      bgGrad.addColorStop(0.32, '#81cdf8');
      bgGrad.addColorStop(0.325, '#92d97e');
      bgGrad.addColorStop(1, '#5d9443');
      ctx.fillStyle = bgGrad;
      ctx.fillRect(0, 0, w, h);
      
      // Update
      this.update(1/60);
      
      // Draw fog layer
      this.fogParticles.forEach(fog => {
        ctx.save();
        ctx.globalAlpha = fog.opacity;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(fog.x, fog.y, fog.radius, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      });
      
      // Draw clouds
      this.clouds.forEach(cloud => {
        ctx.save();
        ctx.globalAlpha = cloud.opacity;
        
        // Create cloud gradient
        const grad = ctx.createRadialGradient(
          cloud.x, cloud.y, 0,
          cloud.x, cloud.y, cloud.radius
        );
        grad.addColorStop(0, '#ffffff');
        grad.addColorStop(0.5, 'rgba(255,255,255,0.6)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        
        ctx.fillStyle = grad;
        
        // Draw multi-blob cloud
        const wobbleX = Math.sin(this.time * 2 + cloud.phase) * 8;
        const wobbleY = Math.cos(this.time * 1.5 + cloud.phase) * 5;
        
        ctx.beginPath();
        ctx.arc(cloud.x + wobbleX, cloud.y + wobbleY, cloud.radius, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.beginPath();
        ctx.arc(cloud.x + cloud.radius * 0.6 + wobbleX, cloud.y - cloud.radius * 0.2 + wobbleY, cloud.radius * 0.7, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.beginPath();
        ctx.arc(cloud.x - cloud.radius * 0.5 + wobbleX, cloud.y + cloud.radius * 0.1 + wobbleY, cloud.radius * 0.65, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.restore();
      });
      
      // Draw sparkles
      this.sparkles.forEach(sparkle => {
        ctx.save();
        ctx.globalAlpha = sparkle.opacity;
        ctx.fillStyle = sparkle.color;
        
        // Star shape
        ctx.save();
        ctx.translate(sparkle.x, sparkle.y);
        ctx.rotate(this.time * 3);
        
        ctx.beginPath();
        for (let i = 0; i < 4; i++) {
          const angle = (Math.PI * 2 / 4) * i;
          const x = Math.cos(angle) * sparkle.size;
          const y = Math.sin(angle) * sparkle.size;
          if (i === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        }
        ctx.closePath();
        ctx.fill();
        
        ctx.restore();
        ctx.restore();
      });
      
      // Draw center glow effect when searching
      if (this.phase === 'searching') {
        ctx.save();
        const centerX = w / 2;
        const centerY = h / 2;
        const pulseSize = 60 + Math.sin(this.time * 4) * 20;
        
        const glowGrad = ctx.createRadialGradient(
          centerX, centerY, 0,
          centerX, centerY, pulseSize
        );
        glowGrad.addColorStop(0, 'rgba(255,255,255,0.16)');
        glowGrad.addColorStop(0.5, 'rgba(255,255,200,0.08)');
        glowGrad.addColorStop(1, 'rgba(255,255,255,0)');
        
        ctx.fillStyle = glowGrad;
        ctx.beginPath();
        ctx.arc(centerX, centerY, pulseSize, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      }
      
      // Draw search text overlay
      if (this.phase === 'searching') {
        ctx.save();
        ctx.globalAlpha = 0.6 + Math.sin(this.time * 3) * 0.2;
        ctx.fillStyle = '#ffffff';
        ctx.font = '900 32px -apple-system, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,0.4)';
        ctx.shadowBlur = 20;
        ctx.fillText('Поиск...', w / 2, h / 2);
        ctx.restore();
      }
    }
  }

  // Export
  window.RaidSearchManager = RaidSearchManager;

})();
