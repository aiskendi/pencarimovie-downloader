 /**
 * PencariMovieApp — Merged streaming UI + session management + file download.
 *
 * Combines:
 *   - StreamApp UI (hero, trending, category rows, search, modal, file cards)
 *   - Session management (bot login, logout, session check via /api/session)
 *   - File Detail Page (resolve short_code → file_id_mt, Stream + Download)
 *   - Backend proxy for streaming data (/api/proxy-stream)
 *   - MadelineProto download integration (/api/download)
 */
class PencariMovieApp {
  constructor() {
    // ── Config ──
    this.localApiBase = window.location.origin;
    this.wpApiBase = 'https://pencarimovie.com/wp-json/fastdownloader/v1';
    this.siteName = 'PencariMovie';

    // ── State ──
    this.categories = [];
    this.trending = [];
    this.posts = {};
    this.searchTimeout = null;
    this.isSearchOpen = false;
    this.isModalOpen = false;
    this.isMobileNavOpen = false;
    this.heroIndex = 0;
    this.heroInterval = null;
    this._currentPostId = null;
    this._suppressHash = false;
    this._cameFromPost = false;
    this._previousPostData = null;
    this._savedSearchQuery = '';

    // ── Category page state ──
    this._categorySlug = '';
    this._categoryName = '';
    this._categoryOffset = 0;
    this._categoryHasMore = true;
    this._categoryLoading = false;
    this._categoryObserver = null;
    this._isCategoryPageOpen = false;
    this._cameFromCategory = false;

    // ── Cache ──
    this._cache = new Map();
    this._cachePrefix = 'pencarimovie_cache:';

    // Session state
    this.botId = '';
    this.botUsername = '';
    this.botName = '';
    this.hasSession = false;

    // ── DOM ref shortcuts ──
    this.$ = (sel) => document.querySelector(sel);
    this.$$ = (sel) => document.querySelectorAll(sel);

    // ── Init ──
    this._ready = this.init();
  }

  // ══════════════════════════════════════════════════════════════
  //  INIT
  // ══════════════════════════════════════════════════════════════

  async init() {
    this.detectTelegram();
    this.bindGlobalEvents();

    // ── Version check — block everything if update is required ──
    try {
      const versionInfo = await this.checkVersion();
      if (versionInfo && versionInfo.update_needed) {
        this.showUpdateRequired(versionInfo);
        return; // Stop — overlay blocks all interaction
      }
    } catch (e) {
      console.warn('Version check failed, proceeding:', e);
    }

    // ── Check for ?token= URL parameter for 1-click auto-login ──
    const urlParams = new URLSearchParams(window.location.search);
    const tokenFromUrl = urlParams.get('token');

    if (tokenFromUrl && tokenFromUrl.trim() !== '') {
      try {
        const input = this.$('#botTokenInput');
        if (input) input.value = tokenFromUrl.trim();
        await this.saveSettings(tokenFromUrl.trim());
        if (this.hasSession) {
          // Remove token from query string in browser URL history without reloading
          urlParams.delete('token');
          const newSearch = urlParams.toString() ? `?${urlParams.toString()}` : '';
          window.history.replaceState({}, document.title, `${window.location.pathname}${newSearch}${window.location.hash}`);
        }
      } catch (err) {
        console.warn('Auto-login from ?token= failed:', err);
      }
    } else {
      try {
        await this.loadSessionStatus();
      } catch (e) {
        console.warn('Session check failed:', e);
        this.hasSession = false;
      }
    }

    // Loading screen is hidden by showSettingsGate() or hideSettingsGate()
    if (this.hasSession) {
      this.hideSettingsGate();
      this.updateBotBadge();

      // Restore file detail origin context from sessionStorage (survives page refresh)
      this._restoreFileDetailContext();

      // Check for deep links first
      const hash = window.location.hash;
      if (hash.startsWith('#file/')) {
        const shortCode = hash.replace('#file/', '');
        // Load main page data in background so it's rendered when user goes back
        this.loadInitialData().catch((err) => console.warn('Background init data load failed:', err));
        await this.openFileDetail(shortCode);
      } else {
        await this.loadInitialData();
        this._checkHash();
      }
    } else {
      this.showSettingsGate();
    }
  }

  detectTelegram() {
    if (typeof Telegram !== 'undefined' && Telegram.WebApp) {
      document.body.classList.add('tg');
      Telegram.WebApp.expand();
      Telegram.WebApp.enableClosingConfirmation();
      Telegram.WebApp.onEvent('backButtonClicked', () => {
        if (this.isModalOpen) {
          this.closeModal();
        } else if (this.isSearchOpen) {
          this.closeSearch();
        } else if (this.isFileDetailOpen()) {
          this.closeFileDetail();
        } else {
          window.history.back();
        }
      });
    }
  }

  bindGlobalEvents() {
    // ── Settings gate ──
    this.$('#connectBtn').addEventListener('click', () => {
      this.saveSettings().catch((err) => {
        const el = this.$('#settingsStatus');
        if (el) el.textContent = 'Error: ' + err.message;
      });
    });

    const afterLogout = () => {
      this.clearSession().then(() => {
        localStorage.removeItem('tgfd.botId');
        this.botId = '';
        this.botUsername = '';
        this.hasSession = false;
        this.$('#botTokenInput').value = '';
        this.showSettingsGate();
      });
    };

    this.$('#logoutBtn').addEventListener('click', afterLogout);
    this.$('#settingsDisconnectBtn').addEventListener('click', afterLogout);

    this.$('#settingsClose').addEventListener('click', () => {
      this.closeSettingsGate();
    });

    this.$('#settingsBtn').addEventListener('click', () => {
      this.showSettingsGate();
    });

    // ── Nuvio Addon Modal Card ──
    const addonModal = this.$('#addonModal');
    const addonBtn = this.$('#addonBtn');
    const addonClose = this.$('#addonModalClose');
    const copyAddonBtn = this.$('#copyAddonManifestBtn');
    const manifestInput = this.$('#addonManifestInput');
    const copiedStatus = this.$('#addonCopiedStatus');

    const manifestUrl = window.location.origin + '/manifest.json';
    if (manifestInput) {
      manifestInput.value = manifestUrl;
    }

    const openAddonModal = () => {
      if (addonModal) {
        addonModal.classList.remove('hidden');
        addonModal.setAttribute('aria-hidden', 'false');
        if (copiedStatus) copiedStatus.classList.add('hidden');
      }
    };

    if (addonBtn) {
      addonBtn.addEventListener('click', openAddonModal);
    }

    const settingsOpenAddonBtn = this.$('#settingsOpenAddonBtn');
    if (settingsOpenAddonBtn) {
      settingsOpenAddonBtn.addEventListener('click', () => {
        this.closeSettingsGate();
        openAddonModal();
      });
    }

    if (addonClose && addonModal) {
      addonClose.addEventListener('click', () => {
        addonModal.classList.add('hidden');
        addonModal.setAttribute('aria-hidden', 'true');
      });
    }

    if (copyAddonBtn && manifestInput) {
      copyAddonBtn.addEventListener('click', () => {
        manifestInput.select();
        navigator.clipboard.writeText(manifestInput.value);
        if (copiedStatus) {
          copiedStatus.classList.remove('hidden');
          setTimeout(() => copiedStatus.classList.add('hidden'), 3000);
        }
      });
    }

    // Allow Enter key on token input
    this.$('#botTokenInput').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        this.$('#connectBtn').click();
      }
    });

    // ── Nav ──
    this.$('#streamSearchBtn').addEventListener('click', () => this.openSearch());
    this.$('#streamHamburger').addEventListener('click', () => this.openMobileNav());

    // ── Search overlay ──
    this.$('#searchOverlayBack').addEventListener('click', () => this.closeSearch());
    this.$('#searchInput').addEventListener('input', (e) => {
      clearTimeout(this.searchTimeout);
      const query = e.target.value.trim();
      if (query.length < 2) {
        this.$('#searchResults').classList.add('hidden');
        this.$('#searchEmpty').classList.add('hidden');
        this.$('#searchSuggestions').classList.remove('hidden');
        return;
      }
      this.searchTimeout = setTimeout(() => this.doSearch(query), 400);
    });

    // ── Modal ──
    this.$('#modalBackdrop').addEventListener('click', () => this.closeModal());
    this.$('#modalClose').addEventListener('click', () => this.closeModal());

    // ── Hero CTA (scroll fallback for non-JS navigation) ──
    const heroCta = this.$('#heroCta');
    if (heroCta) {
      heroCta.addEventListener('click', () => {
        document.querySelector('.stream-content')?.scrollIntoView({ behavior: 'smooth' });
      });
    }

    // ── File Detail ──
    this.$('#fileDetailBack').addEventListener('click', () => this.closeFileDetail());
    this.$('#fileDetailBackdrop').addEventListener('click', () => this.closeFileDetail());
    this.$('#fileDetailStreamBtn').addEventListener('click', () => {
      const url = this.$('#fileDetailStreamBtn').getAttribute('data-url');
      if (url) window.open(url, '_blank');
    });
    this.$('#fileDetailDownloadBtn').addEventListener('click', () => {
      const url = this.$('#fileDetailDownloadBtn').getAttribute('data-url');
      if (url) window.location.href = url;
    });

    const handleMediaPlaybackError = (mediaEl) => {
      if (!mediaEl || !mediaEl.src) return;
      console.warn('[Player] Media playback failed for source:', mediaEl.src);
      const titleEl = this.$('#fileDetailTitle');
      const tagsEl = this.$('#fileDetailTags');
      if (titleEl) titleEl.textContent = 'Stream playback failed';
      if (tagsEl) {
        tagsEl.innerHTML = `
          <div style="background:rgba(255,107,53,0.15);border:1px solid var(--accent);border-radius:8px;padding:10px 14px;margin-top:8px;">
            <p style="color:var(--accent);font-weight:600;margin:0 0 4px 0;"><i class="fas fa-exclamation-triangle"></i> Cannot play media stream</p>
            <p style="color:var(--text-secondary);font-size:0.85rem;margin:0 0 8px 0;">Make sure your Telegram bot is connected and the MadelineProto session is active.</p>
            <button id="fileDetailReconnectBtn" class="stream-btn stream-btn--primary stream-btn--sm" style="background:var(--accent);color:#fff;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:0.8rem;">
              <i class="fas fa-key"></i> Connect Bot
            </button>
          </div>
        `;
        const reconnectBtn = this.$('#fileDetailReconnectBtn');
        if (reconnectBtn) {
          reconnectBtn.addEventListener('click', () => this.showSettingsGate());
        }
      }
    };

    const vEl = this.$('#fileDetailVideo');
    if (vEl) {
      vEl.addEventListener('error', () => handleMediaPlaybackError(vEl));
    }
    const aEl = this.$('#fileDetailAudio');
    if (aEl) {
      aEl.addEventListener('error', () => handleMediaPlaybackError(aEl));
    }

    // ── Category Page ──
    this.$('#categoryPageBack').addEventListener('click', () => this.closeCategoryPage());

    // ── Mobile nav ──
    this.$('#mobileNavClose').addEventListener('click', () => this.closeMobileNav());
    this.$('#mobileNavOverlay').addEventListener('click', () => this.closeMobileNav());

    // ── Hash routing ──
    window.addEventListener('hashchange', () => this._handleHashChange());

    // ── Nav scroll effect ──
    window.addEventListener('scroll', () => {
      const nav = this.$('#streamNav');
      if (nav) {
        nav.classList.toggle('scrolled', window.scrollY > 60);
      }
    });

    // ── Keyboard ──
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (this.isModalOpen) this.closeModal();
        else if (this.isSearchOpen) this.closeSearch();
        else if (this.isFileDetailOpen()) this.closeFileDetail();
        else if (this._isCategoryPageOpen) this.closeCategoryPage();
        else if (this.isMobileNavOpen) this.closeMobileNav();
        else if (
          !this.$('#settingsGate').classList.contains('hidden') &&
          this.hasSession
        ) {
          this.closeSettingsGate();
        }
      }
    });
  }

  // ══════════════════════════════════════════════════════════════
  //  SESSION MANAGEMENT
  // ══════════════════════════════════════════════════════════════

  async loadSessionStatus() {
    try {
      const data = await this.requestJson(`${this.localApiBase}/api/session`);
      const hasSession = Boolean(data?.has_session);

      if (hasSession) {
        this.botId = String(data.bot_id || '').trim();
        this.botUsername = String(data.bot_username || '');
        this.botName = String(data.bot_name || '');
        this.apiSecret = String(data.api_secret || '');
        this.hasSession = true;
        if (this.botId) {
          localStorage.setItem('tgfd.botId', this.botId);
        }
      } else {
        this.botId = '';
        this.botUsername = '';
        this.botName = '';
        this.apiSecret = '';
        this.hasSession = false;
      }
    } catch (error) {
      console.warn('Session check failed:', error);
      this.botId = '';
      this.botUsername = '';
      this.botName = '';
      this.hasSession = false;
    }
  }

  showSettingsGate() {
    this._hideLoadingScreen();

    const gate = this.$('#settingsGate');
    const app = this.$('#streamApp');
    const tokenSection = this.$('#settingsTokenSection');
    const connectedSection = this.$('#settingsConnectedSection');
    const closeBtn = this.$('#settingsClose');

    if (!gate) return;

    gate.classList.remove('hidden');
    gate.setAttribute('aria-hidden', 'false');
    gate.removeAttribute('inert');

    if (this.hasSession) {
      // ── Overlay mode: bot connected ──
      // Keep streamApp visible underneath
      if (app) {
        app.classList.remove('hidden');
        app.setAttribute('aria-hidden', 'false');
        app.removeAttribute('inert');
      }

      // Show connected info, hide token input
      if (tokenSection) tokenSection.classList.add('hidden');
      if (connectedSection) connectedSection.classList.remove('hidden');
      if (closeBtn) closeBtn.style.display = 'flex';

      // Fill bot info
      const nameEl = this.$('#settingsBotName');
      const usernameEl = this.$('#settingsBotUsername');
      if (nameEl) nameEl.textContent = this.botName || 'Connected';
      if (usernameEl) usernameEl.textContent = this.botUsername ? '@' + this.botUsername : '';
    } else {
      // ── Setup mode: no session ──
      // Hide streamApp underneath
      if (app) {
        app.classList.add('hidden');
        app.setAttribute('aria-hidden', 'true');
        app.toggleAttribute('inert', true);
      }

      // Show token input, hide connected info
      if (tokenSection) tokenSection.classList.remove('hidden');
      if (connectedSection) connectedSection.classList.add('hidden');
      if (closeBtn) closeBtn.style.display = 'none';

      // Focus token input
      const input = this.$('#botTokenInput');
      if (input) setTimeout(() => input.focus(), 100);
    }

    // Hide file detail page if open
    const filePage = this.$('#fileDetailPage');
    if (filePage) {
      filePage.classList.add('hidden');
      filePage.setAttribute('aria-hidden', 'true');
    }

    // Stop hero rotation while settings are open
    this._stopHeroRotation();
  }

  hideSettingsGate() {
    // Must hide the loading screen first — it has z-index 10000 (above everything)
    this._hideLoadingScreen();

    const gate = this.$('#settingsGate');
    const app = this.$('#streamApp');

    if (gate) {
      gate.classList.add('hidden');
      gate.setAttribute('aria-hidden', 'true');
      gate.toggleAttribute('inert', true);
    }
    if (app) {
      app.classList.remove('hidden');
      app.setAttribute('aria-hidden', 'false');
      app.removeAttribute('inert');
    }

    // Reset UI state to setup-mode defaults for next time
    const tokenSection = this.$('#settingsTokenSection');
    const connectedSection = this.$('#settingsConnectedSection');
    const closeBtn = this.$('#settingsClose');
    const statusEl = this.$('#settingsStatus');
    if (tokenSection) tokenSection.classList.remove('hidden');
    if (connectedSection) connectedSection.classList.add('hidden');
    if (closeBtn) closeBtn.style.display = '';
    if (statusEl) statusEl.textContent = '';
  }

  closeSettingsGate() {
    // Closes the settings overlay when bot is connected (no logout)
    this.hideSettingsGate();
    this._startHeroRotation();
  }

  updateBotBadge() {
    const badge = this.$('#botStatusBadge');
    if (!badge) return;
    if (this.hasSession && this.botUsername) {
      badge.innerHTML = `<span style="color:#4caf49;">●</span> @${this.escapeHtml(this.botUsername)}`;
      badge.style.color = '#4caf49';
    } else if (this.hasSession) {
      badge.innerHTML = `<span style="color:#4caf49;">●</span> Connected`;
      badge.style.color = '#4caf49';
    } else {
      badge.innerHTML = `○ Disconnected`;
      badge.style.color = 'var(--text-muted)';
    }
  }

  async saveSettings(providedToken = null) {
    const input = this.$('#botTokenInput');
    const statusEl = this.$('#settingsStatus');
    const botToken = providedToken ? providedToken.trim() : (input ? input.value.trim() : '');

    if (!botToken) {
      if (statusEl) statusEl.textContent = 'Bot Token is required.';
      return;
    }

    if (statusEl) statusEl.textContent = 'Validating bot token...';

    try {
      const loginResp = await this.requestJson(`${this.localApiBase}/api/botlogin`, {
        method: 'POST',
        body: JSON.stringify({ bot_token: botToken })
      });

      if (loginResp?.ok !== 1) {
        if (statusEl) statusEl.textContent = 'Login failed: ' + (loginResp?.message || 'unknown error');
        return;
      }

      const botId = String(loginResp.bot_id || '').trim();
      const botUsername = String(loginResp.bot_username || '');
      const botName = String(loginResp.bot_name || '');

      this.apiSecret = String(loginResp.api_secret || '');
      localStorage.setItem('tgfd.botId', botId);
      this.botId = botId;
      this.botUsername = botUsername;
      this.botName = botName;
      this.hasSession = true;

      this.hideSettingsGate();
      this.updateBotBadge();
      if (statusEl) statusEl.textContent = '';

      // Load streaming data
      await this.loadInitialData();

      // After login, re-check version in case server-side policy changed
      try {
        const versionInfo = await this.checkVersion();
        if (versionInfo && versionInfo.update_needed) {
          this.showUpdateRequired(versionInfo);
          return;
        }
      } catch (e) {
        console.warn('Version check after login failed:', e);
      }
    } catch (error) {
      if (statusEl) statusEl.textContent = 'Login failed: ' + error.message;
    }
  }

  async clearSession() {
    try {
      await this.requestJson(`${this.localApiBase}/api/botlogout`, {
        method: 'POST',
        body: '{}'
      });
    } catch (error) {
      console.warn('Failed to clear session:', error);
    }
    // Clear all cached data — session change means stale data
    this._cacheClear();
  }

  // ══════════════════════════════════════════════════════════════
  //  HELPERS
  // ══════════════════════════════════════════════════════════════

  escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
      '&': '&',
      '<': '<',
      '>': '>',
      "'": '&#039;',
      '"': '"'
    })[char]);
  }

  formatSize(bytes) {
    const size = Number(bytes || 0);
    if (!size) return 'Unknown size';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let index = 0;
    let output = size;
    while (output >= 1024 && index < units.length - 1) {
      output /= 1024;
      index += 1;
    }
    return `${output.toFixed(output >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
  }

  encodeDownloadPayload(payload) {
    const json = JSON.stringify(payload);
    const base64 = btoa(unescape(encodeURIComponent(json)));
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  buildDownloadUrl(fileId, fileSize, fileName, fileMime) {
    const url = new URL(`${this.localApiBase}/api/download`);
    url.searchParams.set('d', this.encodeDownloadPayload({
      file_id: fileId,
      file_size: fileSize,
      file_name: fileName,
      mime: fileMime,
      bot_id: this.botId
    }));
    return url.toString();
  }

  /**
   * Guess whether a file is video, audio, or unknown based on
   * the title's file extension or the file_type field.
   * @param {string} title
   * @param {string} fileType
   * @returns {'video'|'audio'|null}
   */
  _guessMediaType(title, fileType) {
    const videoExts = ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', '3gp', 'mpg', 'mpeg'];
    const audioExts = ['mp3', 'flac', 'm4a', 'wav', 'ogg', 'aac', 'wma', 'opus'];

    // Check title extension
    const dotIdx = (title || '').lastIndexOf('.');
    if (dotIdx > 0) {
      const ext = title.substring(dotIdx + 1).toLowerCase();
      if (videoExts.includes(ext)) return 'video';
      if (audioExts.includes(ext)) return 'audio';
    }

    // Check file_type
    const ft = (fileType || '').toLowerCase();
    if (ft.startsWith('video')) return 'video';
    if (ft.startsWith('audio')) return 'audio';

    return null; // unknown / not playable inline
  }

  // ══════════════════════════════════════════════════════════════
  //  CACHE LAYER
  // ══════════════════════════════════════════════════════════════

  /**
   * Build a deterministic cache key from a type and params object.
   * @param {'stream'|'resolve'} type
   * @param {Object} params
   * @returns {string}
   */
  _cacheKey(type, params) {
    const sorted = Object.keys(params).sort().reduce((acc, k) => {
      acc[k] = params[k];
      return acc;
    }, {});
    return `${type}:${JSON.stringify(sorted)}`;
  }

  /**
   * Retrieve a cached value. Checks in-memory Map first, then sessionStorage.
   * Returns null if not found or expired (when requireFresh=true).
   * Returns {data, expired} — caller decides whether to use stale data.
   * @param {string} key
   * @returns {{data: any, expired: boolean}|null}
   */
  _cacheGet(key) {
    const now = Date.now();

    // 1. Check in-memory Map
    const memEntry = this._cache.get(key);
    if (memEntry) {
      return {
        data: memEntry.data,
        expired: now > memEntry.expiresAt
      };
    }

    // 2. Fallback to sessionStorage
    try {
      const raw = sessionStorage.getItem(this._cachePrefix + key);
      if (raw) {
        const entry = JSON.parse(raw);
        // Promote to in-memory
        this._cache.set(key, { data: entry.data, expiresAt: entry.expiresAt });
        return {
          data: entry.data,
          expired: now > entry.expiresAt
        };
      }
    } catch (_) {
      // Ignore corrupt sessionStorage entries
    }

    return null;
  }

  /**
   * Store a value in both in-memory Map and sessionStorage.
   * @param {string} key
   * @param {any} data
   * @param {number} ttlMs — time to live in milliseconds
   */
  _cacheSet(key, data, ttlMs) {
    const expiresAt = Date.now() + ttlMs;
    const entry = { data, expiresAt };

    // In-memory
    this._cache.set(key, entry);

    // sessionStorage (fire-and-forget, catch quota errors)
    try {
      sessionStorage.setItem(this._cachePrefix + key, JSON.stringify(entry));
    } catch (_) {
      // sessionStorage may be full or unavailable (private browsing)
    }
  }

  /**
   * Clear all cached data — both in-memory and sessionStorage.
   */
  _cacheClear() {
    this._cache.clear();

    // Remove all sessionStorage entries with our prefix
    try {
      const keysToRemove = [];
      for (let i = 0; i < sessionStorage.length; i++) {
        const k = sessionStorage.key(i);
        if (k && k.startsWith(this._cachePrefix)) {
          keysToRemove.push(k);
        }
      }
      keysToRemove.forEach((k) => sessionStorage.removeItem(k));
    } catch (_) {
      // Ignore
    }
  }

  /**
   * Return the TTL (in ms) for a given proxy-stream action.
   * @param {string} action
   * @returns {number}
   */
  _getStreamTTL(action) {
    switch (action) {
      case 'trending':
      case 'categories':
        return 5 * 60 * 1000; // 5 min
      case 'posts':
        return 2 * 60 * 1000; // 2 min
      case 'search_files':
      case 'search':
        return 30 * 1000;     // 30 sec — freshness matters
      case 'post_files':
      case 'get_post':
        return 5 * 60 * 1000; // 5 min — static metadata
      default:
        return 60 * 1000;     // 1 min fallback
    }
  }

  /**
   * Save file detail origin context to sessionStorage so it survives page refresh.
   * Called from openFileDetail() after setting _cameFromPost / _cameFromSearch.
   */
  _saveFileDetailContext() {
    try {
      const ctx = {};
      if (this._cameFromPost && this._previousPostData) {
        const postId = this._previousPostData.id || this._previousPostData.ID || '';
        if (postId) {
          ctx.from = 'post';
          ctx.postId = postId;
          ctx.postTitle = this._previousPostData.title || this._previousPostData.post_title || '';
          ctx.postExcerpt = this._previousPostData.excerpt || this._previousPostData.post_excerpt || '';
          ctx.postThumbnail = this._previousPostData.thumbnail_url || this._previousPostData._external_featured_image || '';
        }
      } else if (this._cameFromSearch) {
        ctx.from = 'search';
        if (this._savedSearchQuery) {
          ctx.searchQuery = this._savedSearchQuery;
        }
      }
      if (ctx.from) {
        sessionStorage.setItem('pencarimovie_fd_context', JSON.stringify(ctx));
      } else {
        sessionStorage.removeItem('pencarimovie_fd_context');
      }
    } catch (_) {
      // sessionStorage unavailable or quota exceeded
    }
  }

  /**
   * Restore file detail origin context from sessionStorage.
   * Called from init() after session check, before deep link handling.
   * This allows the back button to restore the correct context after a page refresh.
   */
  _restoreFileDetailContext() {
    try {
      const saved = sessionStorage.getItem('pencarimovie_fd_context');
      if (!saved) return;
      const ctx = JSON.parse(saved);
      if (ctx.from === 'post' && ctx.postId) {
        this._cameFromPost = true;
        this._previousPostData = {
          id: ctx.postId,
          ID: ctx.postId,
          title: ctx.postTitle || '',
          post_title: ctx.postTitle || '',
          excerpt: ctx.postExcerpt || '',
          post_excerpt: ctx.postExcerpt || '',
          thumbnail_url: ctx.postThumbnail || '',
          _external_featured_image: ctx.postThumbnail || ''
        };
      } else if (ctx.from === 'search') {
        this._cameFromSearch = true;
        if (ctx.searchQuery) {
          this._savedSearchQuery = ctx.searchQuery;
        }
      }
    } catch (_) {
      // Corrupted data or unavailable storage
    }
  }

  /**
   * Clear persisted file detail context from sessionStorage.
   * Called from closeFileDetail() when the context has been consumed.
   */
  _clearFileDetailContext() {
    try {
      sessionStorage.removeItem('pencarimovie_fd_context');
    } catch (_) {
      // Ignore
    }
  }

  /**
   * Fetch data from network and cache it.
   * @param {string} cacheKey
   * @param {number} ttl
   * @param {Function} fetcher — async function that returns the data
   * @returns {Promise<any>}
   */
  async _fetchAndCache(cacheKey, ttl, fetcher) {
    const data = await fetcher();
    this._cacheSet(cacheKey, data, ttl);
    return data;
  }

  /**
   * Fire a background refresh (no await) and update cache when done.
   * @param {string} cacheKey
   * @param {number} ttl
   * @param {Function} fetcher
   */
  _backgroundRefresh(cacheKey, ttl, fetcher) {
    fetcher()
      .then((data) => this._cacheSet(cacheKey, data, ttl))
      .catch(() => {
        // Silently ignore background refresh failures —
        // stale data is better than nothing.
      });
  }

  isFileDetailOpen() {
    const page = this.$('#fileDetailPage');
    return page && !page.classList.contains('hidden');
  }

  showLoading(show) {
    const el = this.$('#streamLoading');
    if (el) el.classList.toggle('hidden', !show);
  }

  /** Hide the full-page loading screen shown during session check */
  _hideLoadingScreen() {
    const el = this.$('#loadingScreen');
    if (el) {
      el.classList.add('hidden');
      el.setAttribute('aria-hidden', 'true');
    }
  }

  /** Hide resolving spinner and optionally show action buttons */
  _endResolving(showActions = true) {
    const resolvingEl = this.$('#fileDetailResolving');
    const actionsEl = this.$('#fileDetailActions');
    if (resolvingEl) {
      resolvingEl.classList.add('hidden');
      resolvingEl.setAttribute('aria-hidden', 'true');
    }
    if (actionsEl) {
      if (showActions) {
        actionsEl.classList.remove('hidden');
      } else {
        actionsEl.classList.add('hidden');
      }
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  CATEGORY PAGE (infinite scroll)
  // ══════════════════════════════════════════════════════════════

  /**
   * Open the full-page category browser. Hides the main streaming view,
   * resets pagination, and loads the first page of posts.
   */
  openCategoryPage(slug, name) {
    // Pause hero rotation while category page is displayed
    this._stopHeroRotation();

    // Close any open overlays
    if (this.isSearchOpen) this.closeSearch();
    if (this.isMobileNavOpen) this.closeMobileNav();
    if (this.isFileDetailOpen()) this.closeFileDetail();

    this._categorySlug = slug;
    this._categoryName = name;
    this._categoryOffset = 0;
    this._categoryHasMore = true;
    this._isCategoryPageOpen = true;

    // Clear grid
    const grid = this.$('#categoryPageGrid');
    if (grid) grid.innerHTML = '';

    // Set title
    this.$('#categoryPageTitle').textContent = name;

    // Hide main app, show category page
    const streamApp = this.$('#streamApp');
    const categoryPage = this.$('#categoryPage');
    if (streamApp) {
      streamApp.classList.add('hidden');
      streamApp.setAttribute('aria-hidden', 'true');
    }
    if (categoryPage) {
      categoryPage.classList.remove('hidden');
      categoryPage.setAttribute('aria-hidden', 'false');
      categoryPage.scrollTop = 0;
    }

    // Update hash
    this._suppressHash = true;
    window.location.hash = '#category/' + slug;
    setTimeout(() => { this._suppressHash = false; }, 0);

    // Load first page
    this._loadMoreCategoryPosts();
  }

  /**
   * Close the category page and restore the main streaming view.
   */
  closeCategoryPage() {
    this._isCategoryPageOpen = false;
    this._closeCategoryObserver();

    const categoryPage = this.$('#categoryPage');
    const streamApp = this.$('#streamApp');

    if (categoryPage) {
      categoryPage.classList.add('hidden');
      categoryPage.setAttribute('aria-hidden', 'true');
    }
    if (streamApp) {
      streamApp.classList.remove('hidden');
      streamApp.setAttribute('aria-hidden', 'false');
    }

    // Clear hash (only if a category hash is present)
    const currentHash = window.location.hash;
    if (currentHash && currentHash.startsWith('#category/')) {
      this._suppressHash = true;
      window.location.hash = '#';
      setTimeout(() => { this._suppressHash = false; }, 0);
    }

    // Resume hero rotation when returning to main view
    this._startHeroRotation();
  }

  /**
   * Fetch the next page of posts for the current category and append
   * to the grid. Stops when fewer than PAGE_SIZE results are returned.
   */
  async _loadMoreCategoryPosts() {
    if (this._categoryLoading || !this._categoryHasMore) return;
    this._categoryLoading = true;

    const PAGE_SIZE = 20;
    const loadingEl = this.$('#categoryPageLoading');
    const grid = this.$('#categoryPageGrid');

    if (loadingEl) loadingEl.classList.remove('hidden');

    try {
      // When slug is 'latest' (the pseudo-category for "Latest Releases"),
      // omit the category param so the backend uses direct WP_Query
      // instead of the Manticore search path.
      const params = {
        limit: PAGE_SIZE,
        offset: this._categoryOffset
      };
      if (this._categorySlug !== 'latest') {
        params.category = this._categorySlug;
      }
      const posts = await this.fetchStream('posts', params);

      if (!Array.isArray(posts) || posts.length === 0) {
        this._categoryHasMore = false;
        this._closeCategoryObserver();
        return;
      }

      // If fewer than PAGE_SIZE, there are no more pages
      if (posts.length < PAGE_SIZE) {
        this._categoryHasMore = false;
        this._closeCategoryObserver();
      }

      // Append posts to grid
      if (grid) {
        for (const post of posts) {
          grid.insertAdjacentHTML('beforeend', this._renderCategoryPostCard(post));
        }

        // Re-bind click handlers for new cards
        const newCards = grid.querySelectorAll('.stream-card:not([data-bound])');
        newCards.forEach((card) => {
          card.setAttribute('data-bound', 'true');
          card.addEventListener('click', () => {
            const postId = card.getAttribute('data-post-id');
            if (postId) {
              const postData = posts.find((p) => String(p.id) === postId || String(p.ID) === postId);
              if (postData) {
                this.openModal(postData);
              } else {
                this.openModal({ id: postId, post_title: card.getAttribute('data-post-title') || 'Details' });
              }
            }
          });
        });
      }

      this._categoryOffset += posts.length;

      // Re-observe sentinel if we have more pages
      if (this._categoryHasMore) {
        this._openCategoryObserver();
      }
    } catch (err) {
      console.warn('Failed to load category posts:', err);
    } finally {
      this._categoryLoading = false;
      if (loadingEl) loadingEl.classList.add('hidden');
    }
  }

  /**
   * Render a single post card for the category page grid.
   */
  _renderCategoryPostCard(item) {
    const title = item.title || item.post_title || 'Untitled';
    const thumbnail = item.thumbnail_url || item._external_featured_image || '';
    const category = item.category || '';
    const year = item.year || '';
    const id = item.id || '';

    return `
      <div class="stream-card" data-post-id="${id}" data-post-title="${this.escapeHtml(title)}">
        <img class="stream-card__thumb" src="${thumbnail}" alt="${this.escapeHtml(title)}" loading="lazy"
             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22158%22><rect fill=%22%232a2a2a%22 width=%22280%22 height=%22158%22/><text fill=%22%23808080%22 x=%22140%22 y=%2279%22 text-anchor=%22middle%22 font-size=%2214%22>${this.escapeHtml(title)}</text></svg>'">
        <div class="stream-card__overlay">
          <div class="stream-card__title">${this.escapeHtml(title)}</div>
          <div class="stream-card__meta">${year ? year : ''}${category ? ' · ' + this.escapeHtml(category) : ''}</div>
        </div>
      </div>
    `;
  }

  /** Start observing the sentinel element for infinite scroll */
  _openCategoryObserver() {
    this._closeCategoryObserver();
    this._categoryObserver = new IntersectionObserver((entries) => {
      const entry = entries[0];
      if (entry && entry.isIntersecting && this._categoryHasMore && !this._categoryLoading) {
        this._loadMoreCategoryPosts();
      }
    }, { rootMargin: '300px' });

    const sentinel = this.$('#categoryPageSentinel');
    if (sentinel) this._categoryObserver.observe(sentinel);
  }

  /** Disconnect the IntersectionObserver */
  _closeCategoryObserver() {
    if (this._categoryObserver) {
      this._categoryObserver.disconnect();
      this._categoryObserver = null;
    }
  }

  async requestJson(url, options = {}) {
    const response = await fetch(url, {
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      },
      ...options
    });

    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (error) {
      // ═══ [DIAGNOSTIC] Log raw response when JSON parsing fails ═══
      const preview = text.length > 2000 ? text.substring(0, 2000) + '... [TRUNCATED]' : text;
      console.error('requestJson: non-JSON response', {
        url,
        status: response.status,
        contentType: response.headers?.get?.('content-type') || 'unknown',
        bodyLength: text.length,
        bodyPreview: preview,
      });
      throw new Error(`API returned non-JSON response (${response.status}).`);
    }

    if (!response.ok) {
      throw new Error(data?.message || data?.code || `HTTP ${response.status}`);
    }

    return data;
  }

  // ══════════════════════════════════════════════════════════════
  //  DATA FETCHING (via backend proxy)
  // ══════════════════════════════════════════════════════════════

  async fetchStream(action, params = {}) {
    // ── Cache check ──
    const cacheKey = this._cacheKey('stream', { action, ...params });
    const ttl = this._getStreamTTL(action);
    const cached = this._cacheGet(cacheKey);

    if (cached) {
      if (!cached.expired) {
        return cached.data;
      }
      // Expired but not a search action: stale-while-revalidate
      if (action !== 'search_files' && action !== 'search') {
        this._backgroundRefresh(cacheKey, ttl, () => this._rawFetchStream(action, params));
        return cached.data;
      }
      // Search actions: expired means re-fetch (freshness matters)
    }

    // ── Normal fetch (miss or search expired) ──
    return this._fetchAndCache(cacheKey, ttl, () => this._rawFetchStream(action, params));
  }

  /**
   * Raw proxy-stream fetch without caching.
   * Extracted so both fetchStream() and background refresh can share the same logic.
   */
  async _rawFetchStream(action, params) {
    const url = new URL(`${this.localApiBase}/api/proxy-stream`);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, value);
      }
    });

    const data = await this.requestJson(url.toString());
    // WP AJAX returns {success: true, data: ...}
    if (data?.success) return data.data;
    if (data?.data) return data.data;
    return data;
  }

  // ══════════════════════════════════════════════════════════════
  //  UI RENDERING
  // ══════════════════════════════════════════════════════════════

  async loadInitialData() {
    this.showLoading(true);

    try {
      const [trendingData, categoriesData, latestData] = await Promise.all([
        this.fetchStream('trending', { limit: 10 }).catch(() => []),
        this.fetchStream('categories').catch(() => []),
        this.fetchStream('posts', { limit: 12 }).catch(() => [])
      ]);

      this.trending = Array.isArray(trendingData) ? trendingData : [];
      this.categories = Array.isArray(categoriesData) ? categoriesData : [];

      // Store posts by category for lazy loading
      if (Array.isArray(latestData)) {
        this.posts['latest'] = latestData;
      }

      // Render
      this.renderNavLinks();
      this.renderHero(latestData);
      this.renderTrending();
      this.renderSearchChips();

      // Load category rows (lazy)
      await this.renderCategoryRows();
    } catch (error) {
      console.warn('Failed to load initial data:', error);
    } finally {
      this.showLoading(false);
    }
  }

  renderNavLinks() {
    const container = this.$('#streamNavLinks');
    if (!container) return;

    const categories = this.categories.length > 0 ? this.categories : [
      { name: 'Animation', slug: 'animation' },
      { name: 'Action', slug: 'action' },
      { name: 'Comedy', slug: 'comedy' },
      { name: 'Drama', slug: 'drama' },
      { name: 'Horror', slug: 'horror' },
      { name: 'Sci-Fi', slug: 'sci-fi' },
      { name: 'Thriller', slug: 'thriller' },
      { name: 'Malay', slug: 'malay' },
      { name: 'Indo', slug: 'indo' },
      { name: 'Korean', slug: 'korean' }
    ];

    container.innerHTML = categories.map((cat) =>
      `<button class="stream-nav__link" data-category="${this.escapeHtml(cat.slug)}">
        ${this.escapeHtml(cat.name)}
      </button>`
    ).join('');

    container.querySelectorAll('.stream-nav__link').forEach((btn) => {
      btn.addEventListener('click', () => {
        const slug = btn.getAttribute('data-category');
        const cat = categories.find(c => c.slug === slug);
        this.openCategoryPage(slug, cat ? cat.name : slug);
        this.closeMobileNav();
      });
    });

    // Also render mobile nav links
    const mobileContainer = this.$('#mobileNavLinks');
    if (mobileContainer) {
      mobileContainer.innerHTML = categories.map((cat) =>
        `<button class="stream-mobile-nav__link" data-category="${this.escapeHtml(cat.slug)}">
          ${this.escapeHtml(cat.name)}
        </button>`
      ).join('');

      mobileContainer.querySelectorAll('.stream-mobile-nav__link').forEach((btn) => {
        btn.addEventListener('click', () => {
          const slug = btn.getAttribute('data-category');
          const cat = categories.find(c => c.slug === slug);
          this.openCategoryPage(slug, cat ? cat.name : slug);
          this.closeMobileNav();
        });
      });
    }
  }

  /**
   * Render the hero banner with carousel rotation.
   * Stores the full posts array, shows slide 0, starts auto-rotation.
   */
  renderHero(posts) {
    this._stopHeroRotation();

    this._heroPosts = Array.isArray(posts) && posts.length > 0 ? posts : [];
    this.heroIndex = 0;

    const heroTitle = this.$('#heroTitle');
    const heroExcerpt = this.$('#heroExcerpt');
    const heroCta = this.$('#heroCta');

    if (this._heroPosts.length === 0) {
      if (heroTitle) heroTitle.textContent = 'Welcome to ' + this.siteName;
      if (heroExcerpt) heroExcerpt.textContent = 'Browse the latest movies and files.';
      if (heroCta) heroCta.classList.add('hidden');
      // No dots for empty hero
      const dotsContainer = this.$('#heroDots');
      if (dotsContainer) dotsContainer.innerHTML = '';
      return;
    }

    // Reset backdrop layer — primary backdrop shows first slide immediately
    const heroBackdrop = this.$('#heroBackdrop');
    const heroBackdropNext = this.$('#heroBackdropNext');
    const firstPost = this._heroPosts[0];
    const firstThumb = firstPost.thumbnail_url || firstPost._external_featured_image || '';
    if (heroBackdrop) {
      heroBackdrop.style.backgroundImage = firstThumb ? `url('${firstThumb}')` : 'none';
    }
    if (heroBackdropNext) {
      heroBackdropNext.style.backgroundImage = 'none';
      heroBackdropNext.classList.remove('visible');
    }

    // Show first slide content
    this._showHeroSlideContent(0);

    // Render dot indicators
    this._renderHeroDots(this._heroPosts.length);

    // Start auto-rotation
    this._startHeroRotation();
  }

  /**
   * Update hero text content and CTA for the given index without backdrop crossfade.
   */
  _showHeroSlideContent(index) {
    const post = this._heroPosts[index];
    if (!post) return;

    const heroTitle = this.$('#heroTitle');
    const heroExcerpt = this.$('#heroExcerpt');
    const heroCta = this.$('#heroCta');

    const title = post.title || post.post_title || '';
    const excerpt = post.excerpt || post.post_excerpt || '';

    if (heroTitle) heroTitle.textContent = title;
    if (heroExcerpt) heroExcerpt.textContent = excerpt.replace(/<[^>]*>/g, '').trim();

    // Bind CTA button
    if (heroCta) {
      heroCta.classList.remove('hidden');
      const newCta = heroCta.cloneNode(true);
      heroCta.parentNode.replaceChild(newCta, heroCta);
      newCta.addEventListener('click', (e) => {
        e.preventDefault();
        this.openModal(post);
      });
      this._heroCtaPost = post;
    }

    // Update active dot
    const dots = this.$$('.stream-hero__dot');
    dots.forEach((dot, i) => {
      dot.classList.toggle('active', i === index);
    });
  }

  /**
   * Crossfade the hero backdrop to the given index, then update text content.
   */
  _showHeroSlide(index) {
    const post = this._heroPosts[index];
    if (!post) return;

    const thumbnail = post.thumbnail_url || post._external_featured_image || '';
    const heroBackdrop = this.$('#heroBackdrop');
    const heroBackdropNext = this.$('#heroBackdropNext');

    if (heroBackdropNext && thumbnail) {
      // Set next backdrop image and fade it in
      heroBackdropNext.style.backgroundImage = `url('${thumbnail}')`;
      heroBackdropNext.classList.add('visible');

      // After crossfade completes, swap primary backdrop and reset next layer
      const onTransitionEnd = () => {
        heroBackdropNext.removeEventListener('transitionend', onTransitionEnd);
        heroBackdrop.style.backgroundImage = `url('${thumbnail}')`;
        heroBackdropNext.style.backgroundImage = 'none';
        heroBackdropNext.classList.remove('visible');
      };
      heroBackdropNext.addEventListener('transitionend', onTransitionEnd, { once: true });
    } else if (heroBackdrop && thumbnail) {
      // Fallback: no next layer, just set directly
      heroBackdrop.style.backgroundImage = `url('${thumbnail}')`;
    }

    // Update title, excerpt, CTA, dots
    this._showHeroSlideContent(index);
  }

  /**
   * Start the hero auto-rotation timer (every 8 seconds).
   */
  _startHeroRotation() {
    this._stopHeroRotation();
    if (this._heroPosts.length < 2) return;
    this.heroInterval = setInterval(() => {
      const next = (this.heroIndex + 1) % this._heroPosts.length;
      this.heroIndex = next;
      this._showHeroSlide(next);
    }, 8000);
  }

  /**
   * Stop the hero auto-rotation timer.
   */
  _stopHeroRotation() {
    if (this.heroInterval) {
      clearInterval(this.heroInterval);
      this.heroInterval = null;
    }
  }

  /**
   * Render dot indicators below the hero banner.
   */
  _renderHeroDots(count) {
    const container = this.$('#heroDots');
    if (!container) return;
    if (count < 2) {
      container.innerHTML = '';
      return;
    }
    container.innerHTML = Array.from({ length: count }, (_, i) =>
      `<button class="stream-hero__dot${i === 0 ? ' active' : ''}" data-index="${i}" aria-label="Slide ${i + 1}"></button>`
    ).join('');

    // Click handler via event delegation
    container.addEventListener('click', (e) => {
      const dot = e.target.closest('.stream-hero__dot');
      if (!dot) return;
      const index = parseInt(dot.getAttribute('data-index'), 10);
      if (isNaN(index) || index === this.heroIndex) return;

      // Reset rotation so it doesn't immediately skip
      this._stopHeroRotation();
      this.heroIndex = index;
      this._showHeroSlide(index);
      this._startHeroRotation();
    });
  }

  renderTrending() {
    const container = this.$('#trendingPills');
    if (!container) return;

    if (!this.trending || this.trending.length === 0) {
      container.innerHTML = '';
      return;
    }

    container.innerHTML = this.trending.map((item) =>
      `<button class="stream-trending__pill" data-keyword="${this.escapeHtml(item.keyword || '')}">
        <span class="trend-hot">🔥</span> ${this.escapeHtml(item.keyword || '')}
      </button>`
    ).join('');

    container.querySelectorAll('.stream-trending__pill').forEach((btn) => {
      btn.addEventListener('click', () => {
        const keyword = btn.getAttribute('data-keyword');
        if (keyword) {
          this.$('#searchInput').value = keyword;
          this.openSearch();
          this.doSearch(keyword);
        }
      });
    });
  }

  async renderCategoryRows() {
    const container = this.$('#streamContent');
    if (!container) return;

    let html = '';

    // "Latest" row
    if (Array.isArray(this.posts['latest']) && this.posts['latest'].length > 0) {
      html += this._buildTrackHtml('latest', 'Latest Releases', this.posts['latest']);
    }

    // Category rows
    for (const cat of this.categories) {
      try {
        const posts = await this.fetchStream('posts', { category: cat.slug, limit: 10 });
        if (Array.isArray(posts) && posts.length > 0) {
          this.posts[cat.slug] = posts;
          html += this._buildTrackHtml(cat.slug, cat.name, posts);
        }
      } catch (err) {
        // Silently skip failed categories
      }
    }

    container.innerHTML = html;

    // Add scroll arrow functionality
    container.querySelectorAll('.stream-content-row__arrow').forEach((btn) => {
      btn.addEventListener('click', () => {
        const trackId = btn.getAttribute('data-track');
        const track = this.$(`#track-${trackId}`);
        if (!track) return;
        const dir = btn.classList.contains('stream-content-row__arrow--left') ? -1 : 1;
        track.scrollBy({ left: dir * 300, behavior: 'smooth' });
      });
    });

    // "View All" button → Category Page
    container.addEventListener('click', (e) => {
      const viewAllBtn = e.target.closest('.stream-content-row__view-all');
      if (viewAllBtn) {
        const slug = viewAllBtn.getAttribute('data-category');
        const cat = this.categories.find(c => c.slug === slug);
        // 'latest' is a pseudo-category (not in this.categories), so provide
        // a human-readable name for the category page title.
        if (slug === 'latest') {
          this.openCategoryPage(slug, 'Latest Releases');
        } else {
          this.openCategoryPage(slug, cat ? cat.name : slug);
        }
        return;
      }

      // File card clicks → File Detail Page
      const card = e.target.closest('.stream-file-card');
      if (card) {
        const shortCode = card.getAttribute('data-short-code');
        if (shortCode) {
          this.openFileDetail(shortCode);
        }
      }
      // Post card clicks → Modal
      const postCard = e.target.closest('.stream-card');
      if (postCard) {
        const postId = postCard.getAttribute('data-post-id');
        if (postId) {
          // Find the post data across all stored post arrays
          let postData = null;
          const allKeys = Object.keys(this.posts);
          for (const key of allKeys) {
            const arr = this.posts[key];
            if (Array.isArray(arr)) {
              postData = arr.find((p) => String(p.id) === postId || String(p.ID) === postId);
              if (postData) break;
            }
          }
          if (postData) {
            this.openModal(postData);
          } else {
            // Fallback: open modal with just the ID
            this.openModal({ id: postId, post_title: postCard.getAttribute('data-post-title') || 'Details' });
          }
        }
      }
    });
  }

  _buildTrackHtml(trackId, title, items) {
    const cards = items.map((item) => this._renderCard(item)).join('');
    return `
      <div class="stream-content-row" id="row-${trackId}">
        <div class="stream-content-row__header">
          <h2 class="stream-content-row__title">${this.escapeHtml(title)}</h2>
          <button class="stream-content-row__view-all" data-category="${this.escapeHtml(trackId)}">
            View All <i class="fas fa-chevron-right"></i>
          </button>
        </div>
        <div class="stream-content-row__track-wrap">
          <button class="stream-content-row__arrow stream-content-row__arrow--left" data-track="${trackId}">
            <i class="fas fa-chevron-left"></i>
          </button>
          <div class="stream-content-row__track" id="track-${trackId}">
            ${cards}
          </div>
          <button class="stream-content-row__arrow stream-content-row__arrow--right" data-track="${trackId}">
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>
    `;
  }

  _renderCard(item) {
    const title = item.title || item.post_title || 'Untitled';
    const thumbnail = item.thumbnail_url || item._external_featured_image || '';
    const category = item.category || '';
    const year = item.year || '';
    const id = item.id || '';

    return `
      <div class="stream-card" data-post-id="${id}" data-post-title="${this.escapeHtml(title)}">
        <img class="stream-card__thumb" src="${thumbnail}" alt="${this.escapeHtml(title)}" loading="lazy"
             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22280%22 height=%22158%22><rect fill=%22%232a2a2a%22 width=%22280%22 height=%22158%22/><text fill=%22%23808080%22 x=%22140%22 y=%2279%22 text-anchor=%22middle%22 font-size=%2214%22>${this.escapeHtml(title)}</text></svg>'">
        <div class="stream-card__overlay">
          <div class="stream-card__title">${this.escapeHtml(title)}</div>
          <div class="stream-card__meta">${year ? year : ''}${category ? ' · ' + this.escapeHtml(category) : ''}</div>
        </div>
      </div>
    `;
  }

  _renderFileCard(file) {
    const rawTitle = file.title || 'File';
    const title = this.cleanMediaTitle(rawTitle);
    const shortCode = file.short_code || '';
    const fileType = file.file_type || file.extension || '';
    const fileSize = file.file_size || 0;
    const thumbnail = file.thumbnail_url || '';

    return `
      <div class="stream-file-card" data-short-code="${this.escapeHtml(shortCode)}" title="${this.escapeHtml(title)}">
        <div class="stream-file-card__thumb"${thumbnail ? ' style="background-image:url(' + thumbnail + ');background-size:cover;"' : ''}>
          ${!thumbnail ? '<i class="fas fa-film"></i>' : ''}
          <div class="stream-file-card__overlay">
            <button class="stream-file-card__play"><i class="fas fa-play"></i></button>
          </div>
        </div>
        <div class="stream-file-card__info">
          <div class="stream-file-card__title" title="${this.escapeHtml(title)}">${this.escapeHtml(title)}</div>
          <div class="stream-file-card__meta">
            <span class="stream-file-card__badge">${this.escapeHtml(fileType || 'file')}</span>
            <span class="stream-file-card__size">${this.formatSize(fileSize)}</span>
          </div>
        </div>
        <div class="stream-file-card__action">
          <button class="stream-file-card__btn"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    `;
  }

  scrollToCategory(slug) {
    const row = this.$(`#row-${slug}`);
    if (row) {
      row.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  SEARCH
  // ══════════════════════════════════════════════════════════════

  openSearch() {
    this._stopHeroRotation();
    this.isSearchOpen = true;
    const overlay = this.$('#streamSearchOverlay');
    if (overlay) overlay.classList.add('open');
    this.$('#searchInput').focus();
  }

  closeSearch() {
    this.isSearchOpen = false;
    const overlay = this.$('#streamSearchOverlay');
    if (overlay) overlay.classList.remove('open');
    this.$('#searchResults').classList.add('hidden');
    this.$('#searchEmpty').classList.add('hidden');
    this.$('#searchSuggestions').classList.remove('hidden');

    // Resume hero rotation when closing search overlay
    this._startHeroRotation();
  }

  renderSearchChips() {
    const container = this.$('#searchChips');
    if (!container) return;

    const chips = this.trending.length > 0
      ? this.trending.slice(0, 8).map((t) => t.keyword || '')
      : ['Action', 'Comedy', 'Drama', 'Horror', 'Sci-Fi', 'Thriller', 'Malay', 'Korean'];

    container.innerHTML = chips.map((chip) =>
      `<button class="stream-search-overlay__chip">${this.escapeHtml(chip)}</button>`
    ).join('');

    container.querySelectorAll('.stream-search-overlay__chip').forEach((btn) => {
      btn.addEventListener('click', () => {
        const query = btn.textContent.trim();
        this.$('#searchInput').value = query;
        this.doSearch(query);
      });
    });
  }

  async doSearch(query) {
    const resultsContainer = this.$('#searchResults');
    const emptyEl = this.$('#searchEmpty');
    const suggestionsEl = this.$('#searchSuggestions');

    if (!query || query.length < 2) return;

    suggestionsEl.classList.add('hidden');
    resultsContainer.classList.remove('hidden');
    emptyEl.classList.add('hidden');
    resultsContainer.innerHTML = '<div class="stream-loading"><div class="stream-loading__spinner"></div></div>';

    try {
      // Search both files and posts
      const [filesResult, postsResult] = await Promise.all([
        this.fetchStream('search_files', { search: query, limit: 20 }).catch(() => null),
        this.fetchStream('search', { search: query, limit: 12 }).catch(() => null)
      ]);

      let html = '';

      // Files section
      const files = filesResult?.files || [];
      if (files.length > 0) {
        html += `
          <div class="stream-search-section">
            <div class="stream-search-section__title">
              <i class="fas fa-file"></i> Files
              <span class="stream-search-section__count">${filesResult?.total_found || files.length}</span>
            </div>
            <div class="stream-search-section__grid">
              ${files.map((f) => this._renderFileCard(f)).join('')}
            </div>
          </div>
        `;
      }

      // Posts section
      const posts = Array.isArray(postsResult) ? postsResult : (postsResult?.data || []);
      if (posts.length > 0) {
        html += `
          <div class="stream-search-section">
            <div class="stream-search-section__title">
              <i class="fas fa-film"></i> Posts
              <span class="stream-search-section__count">${posts.length}</span>
            </div>
            <div class="stream-search-section__grid stream-search-section__grid--posts">
              ${posts.map((p) => this._renderCard(p)).join('')}
            </div>
          </div>
        `;
      }

      if (!html) {
        emptyEl.classList.remove('hidden');
        resultsContainer.innerHTML = '';
      } else {
        resultsContainer.innerHTML = html;
      }

      // Attach click events
      resultsContainer.querySelectorAll('.stream-card').forEach((card) => {
        card.addEventListener('click', () => {
          const postId = card.getAttribute('data-post-id');
          const title = card.getAttribute('data-post-title');
          // Fetch full post data
          this.fetchStream('get_post', { post_id: postId }).then((postData) => {
            if (postData) this.openModal(postData);
          }).catch(() => {
            // Fallback: open with basic info
            this.openModal({ id: postId, title, post_title: title });
          });
        });
      });

      resultsContainer.querySelectorAll('.stream-file-card').forEach((card) => {
        card.addEventListener('click', () => {
          const shortCode = card.getAttribute('data-short-code');
          if (shortCode) this.openFileDetail(shortCode);
        });
      });

    } catch (error) {
      console.warn('Search failed:', error);
      emptyEl.classList.remove('hidden');
      resultsContainer.innerHTML = '';
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  MODAL
  // ══════════════════════════════════════════════════════════════

  openModal(post) {
    this._stopHeroRotation();
    this.isModalOpen = true;
    const modal = this.$('#streamModal');
    const hero = this.$('#modalHero');
    const body = this.$('#modalBody');

    if (!modal) return;

    const title = post.title || post.post_title || 'Details';
    const excerpt = post.excerpt || post.post_excerpt || '';
    const thumbnail = post.thumbnail_url || post._external_featured_image || '';
    const category = post.category || '';
    const year = post.year || '';
    const content = post.content || post.post_content || '';

    if (hero) {
      hero.style.backgroundImage = thumbnail ? `url('${thumbnail}')` : 'none';
      hero.style.backgroundColor = thumbnail ? 'transparent' : 'var(--bg-elevated)';
    }

    let metaHtml = '';
    if (year) metaHtml += `<span class="stream-modal__meta-tag">${this.escapeHtml(year)}</span>`;
    if (category) metaHtml += `<span class="stream-modal__meta-tag">${this.escapeHtml(category)}</span>`;

    body.innerHTML = `
      <h2 class="stream-modal__title">${this.escapeHtml(title)}</h2>
      ${metaHtml ? `<div class="stream-modal__meta">${metaHtml}</div>` : ''}
      <div class="stream-modal__body-text">
        ${excerpt ? `<p>${this.escapeHtml(excerpt)}</p>` : ''}
        ${content ? `<div>${content}</div>` : ''}
      </div>
      <div class="stream-modal__files" id="modalFilesSection">
        <div class="stream-modal__files-title">
          <i class="fas fa-download"></i> Files
          <span class="stream-modal__files-count">Loading...</span>
        </div>
        <div class="stream-loading"><div class="stream-loading__spinner"></div></div>
      </div>
    `;

    modal.classList.add('open');

    // Load files for this post
    this._loadPostFiles(post);
  }

  async _loadPostFiles(post) {
    const filesSection = this.$('#modalFilesSection');
    if (!filesSection) return;

    const postId = post.id || post.ID || 0;
    if (!postId) {
      filesSection.innerHTML = `
        <div class="stream-modal__files-title">
          <i class="fas fa-download"></i> Files
        </div>
        <p style="color:var(--text-muted);font-size:0.85rem;">No post ID available.</p>
      `;
      return;
    }

    try {
      const result = await this.fetchStream('post_files', { post_id: postId, limit: 500 });
      const files = result?.files || [];

      if (files.length === 0) {
        filesSection.innerHTML = `
          <div class="stream-modal__files-title">
            <i class="fas fa-download"></i> Files
            <span class="stream-modal__files-count">0</span>
          </div>
          <p style="color:var(--text-muted);font-size:0.85rem;">No files found for this post.</p>
        `;
        return;
      }

      filesSection.innerHTML = `
        <div class="stream-modal__files-title">
          <i class="fas fa-download"></i> Files
          <span class="stream-modal__files-count">${files.length}</span>
        </div>
        <div class="stream-modal__files-grid">
          ${files.map((f) => this._renderFileCard(f)).join('')}
        </div>
      `;

      // Click → File Detail Page
      filesSection.querySelectorAll('.stream-file-card').forEach((card) => {
        card.addEventListener('click', () => {
          const shortCode = card.getAttribute('data-short-code');
          if (shortCode) {
            // Remember we came from a post modal so we can restore it on back
            this._cameFromPost = true;
            this._previousPostData = post;
            this.closeModal();
            this.openFileDetail(shortCode);
          }
        });
      });

    } catch (error) {
      console.warn('Failed to load post files:', error);
      filesSection.innerHTML = `
        <div class="stream-modal__files-title">
          <i class="fas fa-download"></i> Files
        </div>
        <p style="color:var(--text-muted);font-size:0.85rem;">Failed to load files.</p>
      `;
    }
  }

  closeModal() {
    this.isModalOpen = false;
    const modal = this.$('#streamModal');
    if (modal) modal.classList.remove('open');

    // If the post modal was restored via _cameFromPost inside
    // closeFileDetail() while _cameFromCategory was also true, the
    // category page remains hidden. Restore it now that the modal
    // is dismissed.
    if (this._cameFromCategory) {
      this._cameFromCategory = false;
      this._isCategoryPageOpen = true;
      const categoryPage = this.$('#categoryPage');
      if (categoryPage) {
        categoryPage.classList.remove('hidden');
        categoryPage.setAttribute('aria-hidden', 'false');
      }
      // Category page overlays streamApp — keep streamApp hidden
      const streamApp = this.$('#streamApp');
      if (streamApp) {
        streamApp.classList.add('hidden');
        streamApp.setAttribute('aria-hidden', 'true');
      }
      // Restore hash to #category/SLUG
      if (this._categorySlug) {
        this._suppressHash = true;
        window.location.hash = '#category/' + this._categorySlug;
        setTimeout(() => { this._suppressHash = false; }, 0);
      }
    } else {
      // Resume hero rotation when modal closes to main view
      this._startHeroRotation();
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  FILE DETAIL PAGE
  // ══════════════════════════════════════════════════════════════

  async openFileDetail(shortCode) {
    this._stopHeroRotation();

    const filePage = this.$('#fileDetailPage');
    const streamApp = this.$('#streamApp');
    const searchOverlay = this.$('#streamSearchOverlay');

    if (!filePage) return;

    // Close search overlay if open — remember to restore it on back.
    // Use isSearchOpen state flag instead of DOM class check, because the
    // search overlay is shown/hidden via the CSS "open" class, not "hidden".
    // Also preserve _cameFromSearch if restored from sessionStorage (page refresh).
    if (this.isSearchOpen) {
      this._cameFromSearch = true;
      this._savedSearchQuery = (this.$('#searchInput')?.value || '').trim();
      this.closeSearch();
    } else if (!this._cameFromSearch) {
      // Don't overwrite if context was restored from sessionStorage
      this._cameFromSearch = false;
    }

    // If coming from category page, remember to restore it on back
    if (this._isCategoryPageOpen) {
      this._cameFromCategory = true;
    } else if (!this._cameFromCategory) {
      this._cameFromCategory = false;
    }

    // Save origin context to sessionStorage so it survives page refresh
    this._saveFileDetailContext();

    // Hide the category page overlay if open from category page
    if (this._cameFromCategory) {
      const categoryPage = this.$('#categoryPage');
      if (categoryPage) {
        categoryPage.classList.add('hidden');
        categoryPage.setAttribute('aria-hidden', 'true');
      }
    }

    // Show file detail page, hide main app
    filePage.classList.remove('hidden');
    filePage.setAttribute('aria-hidden', 'false');
    if (streamApp) {
      streamApp.classList.add('hidden');
      streamApp.setAttribute('aria-hidden', 'true');
    }

    // Update hash (without triggering hash handler).
    // WARNING: hashchange fires asynchronously in Chrome (microtask).
    // We must keep suppressHash=true until the event has fired, so
    // defer the reset via setTimeout(0).
    this._suppressHash = true;
    window.location.hash = '#file/' + shortCode;
    setTimeout(() => { this._suppressHash = false; }, 0);

    // Show resolving spinner, hide action buttons during API call
    const resolvingEl = this.$('#fileDetailResolving');
    const actionsEl = this.$('#fileDetailActions');
    if (resolvingEl) {
      resolvingEl.classList.remove('hidden');
      resolvingEl.setAttribute('aria-hidden', 'false');
    }
    if (actionsEl) actionsEl.classList.add('hidden');

    // Hide thumbnail container during resolving
    const thumbContainer = this.$('#fileDetailThumb');
    if (thumbContainer) thumbContainer.classList.add('hidden');

    // Clear previous content
    this.$('#fileDetailTitle').textContent = '';
    this.$('#fileDetailThumbImg').src = '';
    this.$('#fileDetailThumbImg').alt = '';
    this.$('#fileDetailTags').innerHTML = '';
    this.$('#fileDetailSize').textContent = '';

    // Reset player state before showing new file detail
    const playerEl = this.$('#fileDetailPlayer');
    const videoEl = this.$('#fileDetailVideo');
    const audioEl = this.$('#fileDetailAudio');
    if (videoEl) { videoEl.pause(); videoEl.src = ''; videoEl.load(); videoEl.classList.add('hidden'); }
    if (audioEl) { audioEl.pause(); audioEl.src = ''; audioEl.load(); audioEl.classList.add('hidden'); }
    if (playerEl) playerEl.classList.add('hidden');
    // Show Stream button by default (will hide if video/audio embedded)
    this.$('#fileDetailStreamBtn').classList.remove('hidden');

    try {
      // ── Cache check for resolve-file (immutable mapping, no TTL) ──
      const resolveCacheKey = this._cacheKey('resolve', { shortCode, botId: this.botId || '' });
      const cached = this._cacheGet(resolveCacheKey);
      if (cached && !cached.expired) {
        this._endResolving();
        this._renderResolvedFile(cached.data, shortCode);
        return;
      }

      // Resolve short_code via WordPress REST API (with 3x retry)
      const resolveUrl = new URL(`${this.wpApiBase}/resolve-file`);
      resolveUrl.searchParams.set('short_code', shortCode);
      if (this.botId) resolveUrl.searchParams.set('bot_id', this.botId);

      // 15-second timeout per attempt to prevent hanging on unresponsive API
      const FETCH_TIMEOUT_MS = 15000;

      // Build headers — include API secret for authenticated WordPress endpoints
      const wpHeaders = {};
      if (this.apiSecret) {
        wpHeaders['X-API-Secret'] = this.apiSecret;
      }

      let data = null;
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          const controller = new AbortController();
          const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
          try {
            data = await this.requestJson(resolveUrl.toString(), {
              signal: controller.signal,
              headers: wpHeaders,
            });
          } finally {
            clearTimeout(timeoutId);
          }
          if (data && data.ok) break;
        } catch (e) {
          if (attempt >= 3) throw e;
        }
        // Wait before retry (increasing delay)
        await new Promise(r => setTimeout(r, attempt * 1000));
      }

      // Resolving complete — hide spinner
      this._endResolving(data && data.ok);

      if (!data || !data.ok) {
        this.$('#fileDetailTitle').textContent = 'Failed to resolve file';
        return;
      }

      // Cache the result (immutable mapping — no TTL expiry)
      this._cacheSet(resolveCacheKey, data, 365 * 24 * 60 * 60 * 1000);

      // Render
      this._renderResolvedFile(data, shortCode);

    } catch (error) {
      const isTimeout = error.name === 'AbortError';
      const msg = isTimeout ? 'Request timed out. The WordPress API is not responding.' : error.message;
      console.warn('[resolve-file] Failed after 3 retries:', isTimeout ? 'timeout' : error.message);
      this._endResolving(false);
      this.$('#fileDetailTitle').textContent = 'Error resolving file';
      this.$('#fileDetailTags').innerHTML = `<span style="color:var(--accent)">${this.escapeHtml(msg)}</span>`;

      // Auto-logout if WordPress rejected the request (expired/missing API secret)
      if (!isTimeout && msg.includes('not allowed')) {
        this.clearSession().then(() => {
          localStorage.removeItem('tgfd.botId');
          this.botId = '';
          this.botUsername = '';
          this.apiSecret = '';
          this.hasSession = false;
          this.$('#botTokenInput').value = '';
          this.showSettingsGate();
        });
      }
    }
  }

  /**
   * Render the resolved file data into the file detail page.
   * Extracted so both cache-hit and fresh-fetch paths can reuse the same rendering.
   * @param {Object} data — resolve-file API response
   * @param {string} shortCode — fallback title
   */
  _renderResolvedFile(data, shortCode) {
    const fileId = data.file_id_mt || data.file_id || '';
    const title = data.title || shortCode;
    const fileSize = data.file_size || 0;
    const fileType = data.file_type || data.mime || 'file';
    const thumbnail = data.thumbnail || data.thumbnail_url || '';

    const videoEl = this.$('#fileDetailVideo');
    const audioEl = this.$('#fileDetailAudio');
    const playerEl = this.$('#fileDetailPlayer');

    // Render file info
    this.$('#fileDetailTitle').textContent = title;
    this.$('#fileDetailSize').textContent = 'Size: ' + this.formatSize(fileSize);

    if (thumbnail) {
      this.$('#fileDetailThumbImg').src = thumbnail;
      this.$('#fileDetailThumbImg').alt = title;
      // Show thumbnail container (hidden during resolving)
      const thumbContainer = this.$('#fileDetailThumb');
      if (thumbContainer) thumbContainer.classList.remove('hidden');
    }

    // Tags
    let tagsHtml = '';
    if (fileType) {
      tagsHtml += `<span class="stream-file-card__badge">${this.escapeHtml(fileType)}</span>`;
    }
    this.$('#fileDetailTags').innerHTML = tagsHtml;

    // Detect media type and embed player if video/audio
    const mediaType = this._guessMediaType(title, fileType);
    const isEmbeddable = mediaType === 'video' || mediaType === 'audio';

    if (isEmbeddable && fileId && fileSize > 0) {
      // Build local download URL as video/audio source
      const streamUrl = this.buildDownloadUrl(fileId, fileSize, title, data.mime || fileType);

      if (mediaType === 'video') {
        if (videoEl) {
          videoEl.src = streamUrl;
          if (thumbnail) videoEl.poster = thumbnail;
          videoEl.classList.remove('hidden');
        }
        if (audioEl) audioEl.classList.add('hidden');
      } else {
        if (audioEl) {
          audioEl.src = streamUrl;
          audioEl.classList.remove('hidden');
        }
        if (videoEl) videoEl.classList.add('hidden');
      }

      if (playerEl) playerEl.classList.remove('hidden');

      // Hide the Stream button (embedded player replaces it)
      this.$('#fileDetailStreamBtn').classList.add('hidden');
    } else {
      // Not embeddable: keep Stream button using play_url
      const playUrl = data.play_url || '';
      this.$('#fileDetailStreamBtn').setAttribute('data-url', playUrl);
      this.$('#fileDetailStreamBtn').disabled = !playUrl;
      this.$('#fileDetailStreamBtn').classList.remove('hidden');
    }

    // Download button: build local download URL
    if (fileId && fileSize > 0) {
      const downloadUrl = this.buildDownloadUrl(fileId, fileSize, title, data.mime || fileType);
      this.$('#fileDetailDownloadBtn').setAttribute('data-url', downloadUrl);
      this.$('#fileDetailDownloadBtn').disabled = false;
      this.$('#fileDetailDownloadBtn').innerHTML = '<i class="fas fa-download"></i> Download';
    } else {
      this.$('#fileDetailDownloadBtn').disabled = true;
      this.$('#fileDetailDownloadBtn').innerHTML = '<i class="fas fa-download"></i> No file ID';
    }
  }

  closeFileDetail() {
    const filePage = this.$('#fileDetailPage');
    const streamApp = this.$('#streamApp');

    if (filePage) {
      filePage.classList.add('hidden');
      filePage.setAttribute('aria-hidden', 'true');
    }

    // Pause and reset embedded player — must happen before any context
    // restoration so all three exit paths (post, search, else) stop media
    //
    // Using src='' instead of removeAttribute('src') because the browser
    // maintains an internal media resource reference after the content
    // attribute is removed; setting the IDL property to empty string is
    // more reliable at forcing the resource selection algorithm to unload
    // the current media when followed by load().
    const videoEl = this.$('#fileDetailVideo');
    const audioEl = this.$('#fileDetailAudio');
    const playerEl = this.$('#fileDetailPlayer');
    if (videoEl) { videoEl.pause(); videoEl.src = ''; videoEl.load(); videoEl.classList.add('hidden'); }
    if (audioEl) { audioEl.pause(); audioEl.src = ''; audioEl.load(); audioEl.classList.add('hidden'); }
    if (playerEl) playerEl.classList.add('hidden');

    // Reset resolving state (in case file detail was closed mid-resolve)
    this._endResolving();

    // If user came from a post modal, restore it
    if (this._cameFromPost) {
      if (this._previousPostData) {
        this.openModal(this._previousPostData);
      }
      this._clearFileDetailContext();
      this._cameFromPost = false;
      this._previousPostData = null;
      // Keep streamApp visible underneath the modal
      if (streamApp) {
        streamApp.classList.remove('hidden');
        streamApp.setAttribute('aria-hidden', 'false');
      }
      // Clear the #file/ hash so the URL reflects the restored state
      const currentHash = window.location.hash;
      if (currentHash && currentHash !== '#') {
        this._suppressHash = true;
        window.location.hash = '#';
        setTimeout(() => { this._suppressHash = false; }, 0);
      }
      return;
    }

    // If user came from category page, restore it without resetting pagination
    if (this._cameFromCategory) {
      this._cameFromCategory = false;
      const categoryPage = this.$('#categoryPage');
      if (categoryPage) {
        categoryPage.classList.remove('hidden');
        categoryPage.setAttribute('aria-hidden', 'false');
      }
      // Keep streamApp hidden underneath the category overlay
      if (streamApp) {
        streamApp.classList.add('hidden');
        streamApp.setAttribute('aria-hidden', 'true');
      }
      // Clear the #file/ hash so the URL reflects the restored state
      const currentHash = window.location.hash;
      if (currentHash && currentHash !== '#') {
        this._suppressHash = true;
        window.location.hash = '#' + (!this._categorySlug ? '' : 'category/' + this._categorySlug);
        setTimeout(() => { this._suppressHash = false; }, 0);
      }
      return;
    }

    // If user came from search, restore search overlay using openSearch()
    // which properly sets isSearchOpen=true and adds the CSS "open" class.
    // The search overlay is position:fixed;z-index:1500 so it sits on top
    // of streamApp naturally — no need to hide streamApp underneath.
    if (this._cameFromSearch) {
      this.openSearch();
      const savedQuery = this._savedSearchQuery;
      this._savedSearchQuery = '';
      if (savedQuery) {
        const searchInput = this.$('#searchInput');
        if (searchInput) {
          searchInput.value = savedQuery;
          this.doSearch(savedQuery);
        }
      }
      // Keep streamApp visible underneath the fixed search overlay
      if (streamApp) {
        streamApp.classList.remove('hidden');
        streamApp.setAttribute('aria-hidden', 'false');
      }
      this._clearFileDetailContext();
      this._cameFromSearch = false;
    } else {
      // Normal flow — show main app
      this._clearFileDetailContext();
      if (streamApp) {
        streamApp.classList.remove('hidden');
        streamApp.setAttribute('aria-hidden', 'false');
      }
      // Resume hero rotation when returning to main view
      this._startHeroRotation();
    }

    // Clear hash — only if not already cleared (prevents recursive hashchange
    // when browser back button already restored the previous hash)
    const currentHash = window.location.hash;
    if (currentHash && currentHash !== '#') {
      this._suppressHash = true;
      window.location.hash = '#';
      // Defer reset so the async hashchange event sees suppressHash=true
      setTimeout(() => { this._suppressHash = false; }, 0);
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  MOBILE NAV
  // ══════════════════════════════════════════════════════════════

  openMobileNav() {
    this._stopHeroRotation();
    this.isMobileNavOpen = true;
    const nav = this.$('#mobileNav');
    const overlay = this.$('#mobileNavOverlay');
    if (nav) nav.classList.add('open');
    if (overlay) overlay.classList.add('open');
  }

  closeMobileNav() {
    this.isMobileNavOpen = false;
    const nav = this.$('#mobileNav');
    const overlay = this.$('#mobileNavOverlay');
    if (nav) nav.classList.remove('open');
    if (overlay) overlay.classList.remove('open');

    // Resume hero rotation when closing mobile nav
    this._startHeroRotation();
  }

  // ══════════════════════════════════════════════════════════════
  //  HASH ROUTING
  // ══════════════════════════════════════════════════════════════

  _checkHash() {
    const hash = window.location.hash;
    if (hash.startsWith('#post/')) {
      const postId = hash.replace('#post/', '');
      this._openPostFromHash(postId);
    } else if (hash.startsWith('#file/')) {
      const shortCode = hash.replace('#file/', '');
      this.openFileDetail(shortCode);
    } else if (hash.startsWith('#category/')) {
      const slug = hash.replace('#category/', '');
      const cat = this.categories.find(c => c.slug === slug);
      this.openCategoryPage(slug, cat ? cat.name : slug);
    }
  }

  _handleHashChange() {
    if (this._suppressHash) return;
    const hash = window.location.hash;

    if (!hash || hash === '#') {
      if (this._isCategoryPageOpen) this.closeCategoryPage();
      if (this.isModalOpen) this.closeModal();
      if (this.isFileDetailOpen()) this.closeFileDetail();
    } else if (hash.startsWith('#category/')) {
      const slug = hash.replace('#category/', '');

      // If file detail is open, closeFileDetail() via _cameFromCategory
      // restores the category page without resetting pagination — avoid
      // a duplicate openCategoryPage() call that would clear the grid.
      if (this.isFileDetailOpen()) {
        this.closeFileDetail();
        return;
      }

      const cat = this.categories.find(c => c.slug === slug);
      this.openCategoryPage(slug, cat ? cat.name : slug);
    } else if (hash.startsWith('#post/')) {
      // Close file detail page first if it's open (e.g. browser back from #file/ to #post/)
      if (this.isFileDetailOpen()) this.closeFileDetail();
      const postId = hash.replace('#post/', '');
      this._openPostFromHash(postId);
    } else if (hash.startsWith('#file/')) {
      // Guard: don't re-open file detail if already open (prevents
      // _cameFromSearch reset when hashchange fires asynchronously
      // after programmatic hash assignment)
      if (this.isFileDetailOpen()) return;
      const shortCode = hash.replace('#file/', '');
      this.openFileDetail(shortCode);
    }
  }

  async _openPostFromHash(postId) {
    try {
      const postData = await this.fetchStream('get_post', { post_id: postId });
      if (postData) this.openModal(postData);
    } catch (error) {
      console.warn('Failed to load post from hash:', error);
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  VERSION CHECK
  // ══════════════════════════════════════════════════════════════

  /**
   * Fetch version info from the local backend.
   * Returns null on failure or if no update needed.
   * Returns { update_needed, current_version, minimum_version, update_url } on match.
   */
  async checkVersion() {
    try {
      const resp = await fetch(`${this.localApiBase}/api/version`);
      if (!resp.ok) {
        console.warn('Version endpoint returned', resp.status);
        return null;
      }
      const data = await resp.json();
      if (data && data.update_needed) {
        return data;
      }
      return null;
    } catch (e) {
      console.warn('Version check request failed:', e);
      return null;
    }
  }

  /**
   * Show the update required overlay with version details.
   * Called when the server reports the app is outdated.
   */
  showUpdateRequired(info) {
    const overlay = this.$('#updateRequiredOverlay');
    if (!overlay) return;

    const currentEl = overlay.querySelector('.update-required__version-current');
    const minimumEl = overlay.querySelector('.update-required__version-minimum');
    const linkEl = overlay.querySelector('.update-required__link');

    if (currentEl) currentEl.textContent = info.current_version || '?';
    if (minimumEl) minimumEl.textContent = info.minimum_version || '?';
    if (linkEl) {
      if (info.update_url) {
        linkEl.href = info.update_url;
        linkEl.style.display = 'inline-block';
      } else {
        linkEl.style.display = 'none';
      }
    }

    overlay.classList.remove('hidden');
    overlay.setAttribute('aria-hidden', 'false');
  }
}

// ─── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  window.app = new PencariMovieApp();
});
