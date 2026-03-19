/**
 * RAID API CLIENT
 * Взаимодействие с бэкендом рейдов
 */

(function() {
  'use strict';

  const RaidApi = {
    /**
     * Get CSRF token
     */
    csrf() {
      const token = window.APP_CONFIG?.csrfToken || 
                   document.querySelector('meta[name="csrf_token"],meta[name="csrf-token"]')?.content || 
                   '';
      
      if (!token) {
        console.warn('⚠️ CSRF token not found! This may cause API errors.');
        console.log('window.APP_CONFIG:', window.APP_CONFIG);
      } else {
        console.log('✅ CSRF token found:', token.substring(0, 10) + '...');
      }
      
      return token;
    },

    /**
     * POST request to battle API
     */
    async post(action, payload = {}) {
      const csrfToken = this.csrf();
      
      console.log('📡 API Request:', action);
      console.log('CSRF Token:', csrfToken ? csrfToken.substring(0, 15) + '...' : 'MISSING!');
      
      const body = new URLSearchParams({
        action,
        csrf_token: csrfToken,
        ...payload
      });

      const headers = {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken,
        'Accept': 'application/json'
      };
      
      console.log('Request Headers:', headers);
      console.log('Request Body:', body.toString());

      const response = await fetch('/app/battle_api.php', {
        method: 'POST',
        headers: headers,
        credentials: 'same-origin',
        body: body.toString()
      });

      console.log('Response Status:', response.status);

      let data;
      try {
        data = await response.json();
        console.log('Response Data:', data);
      } catch (e) {
        console.error('Failed to parse JSON:', e);
        throw new Error('Неверный формат ответа от сервера');
      }

      // Update CSRF token
      if (data.csrf_token && window.APP_CONFIG) {
        window.APP_CONFIG.csrfToken = data.csrf_token;
        console.log('✅ CSRF token updated');
      }

      // Check for errors
      if (!response.ok || !data.ok) {
        console.error('❌ API Error:', data.error);
        throw new Error(data.error || `HTTP ${response.status}`);
      }

      return data;
    },

    /**
     * Bootstrap raid system
     * Returns: { player, army, next_cost }
     */
    async bootstrap() {
      return this.post('bootstrap');
    },

    /**
     * Search for opponent
     * @param {boolean} reroll - Whether this is a reroll
     * Returns: { target, player, army, next_cost }
     */
    async searchOpponent(reroll = false) {
      return this.post('search_opponent', {
        reroll: reroll ? '1' : '0'
      });
    },

    /**
     * Start raid battle
     * @param {number} defenderId - Target user ID
     * Returns: { raid }
     */
    async startRaid(defenderId) {
      return this.post('start_raid', {
        defender_id: String(defenderId)
      });
    },

    /**
     * Resolve raid battle
     * @param {number} raidId - Raid ID
     * @param {object} result - Battle result
     * Returns: { result }
     */
    async resolveRaid(raidId, result) {
      return this.post('resolve_raid', {
        raid_id: String(raidId),
        result_json: JSON.stringify(result)
      });
    }
  };

  // Export
  window.RaidApi = RaidApi;

})();
