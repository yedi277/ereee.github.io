/* ================================================================
   个人网址导航 - 主逻辑
================================================================ */

// 搜索引擎配置
const ENGINE_URLS = {
  baidu:  'https://www.baidu.com/s?wd=',
  bing:   'https://www.bing.com/search?q=',
  so:     'https://www.so.com/s?q=',
  sogou:  'https://www.sogou.com/web?query=',
  google: 'https://www.google.com/search?q=',
  github: 'https://github.com/search?q=',
};

let currentEngine = 'baidu';
let bookmarksLoaded = false;
let bookmarksPromise = null;
let currentCat = 'all';

const panel = document.getElementById('bookmarks');
const searchInput = document.getElementById('search');
const engineInput = document.getElementById('engine-input');

/* ================================================================
   搜索功能
================================================================ */

function doSearch() {
  const query = engineInput.value.trim();
  if (query) {
    window.open(ENGINE_URLS[currentEngine] + encodeURIComponent(query), '_blank');
  }
}

// 搜索引擎切换
document.querySelectorAll('.engine-tabs button').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelector('.engine-tabs .active').classList.remove('active');
    this.classList.add('active');
    currentEngine = this.dataset.engine;
  });
});

// 回车搜索
engineInput.addEventListener('keydown', e => {
  if (e.key === 'Enter') doSearch();
});

/* ================================================================
   书签加载与渲染
================================================================ */

function loadBookmarks() {
  if (bookmarksLoaded) return Promise.resolve();
  if (bookmarksPromise) return bookmarksPromise;
  
  panel.innerHTML = '<div style="padding:12px;color:var(--muted);">加载中...</div>';
  
  bookmarksPromise = new Promise(resolve => {
    const script = document.createElement('script');
    script.src = 'js/bookmarks.js';
    script.onload = () => {
      bookmarksLoaded = true;
      initCategoryBar();
      resolve();
    };
    script.onerror = () => {
      panel.innerHTML = '<div style="padding:12px;color:#dc3545;">加载失败</div>';
    };
    document.head.appendChild(script);
  });
  
  return bookmarksPromise;
}

function initCategoryBar() {
  const bar = document.getElementById('categoryBar');
  if (!bar || typeof bookmarks === 'undefined') return;
  
  bar.innerHTML = '<div class="cat-tab active" data-cat="all">全部</div>' +
    bookmarks.map(b => `<div class="cat-tab" data-cat="${b.cat}">${b.icon} ${b.cat}</div>`).join('');
  
  // 点击事件
  bar.addEventListener('click', e => {
    const tab = e.target.closest('.cat-tab');
    if (tab) filterByCat(tab.dataset.cat);
  });
  
  // 键盘导航
  bar.addEventListener('keydown', e => {
    const tabs = [...bar.querySelectorAll('.cat-tab')];
    const idx = tabs.indexOf(document.activeElement);
    if (idx === -1) return;
    
    if (e.key === 'ArrowRight') tabs[(idx + 1) % tabs.length].focus();
    else if (e.key === 'ArrowLeft') tabs[(idx - 1 + tabs.length) % tabs.length].focus();
    else if (e.key === 'Enter') tabs[idx].click();
    else if (e.key === 'Escape') closePanel();
  });
}

function renderBookmarks() {
  const items = currentCat === 'all' 
    ? bookmarks.flatMap(b => b.items)
    : bookmarks.find(b => b.cat === currentCat)?.items || [];
  
  panel.innerHTML = items.map(([name, url, icon]) => `
    <a class="card" href="${url}" target="_blank" rel="noopener" data-name="${name}" data-url="${url}">
      <span class="icon">${renderIcon(icon)}</span>
      <span class="name">${name}</span>
    </a>
  `).join('');
}

function renderIcon(icon) {
  if (!icon) return '🔗';
  if (icon.startsWith('data:image') || icon.startsWith('http')) {
    return `<img src="${icon}" style="width:16px;height:16px;object-fit:contain;">`;
  }
  return icon;
}

/* ================================================================
   面板控制
================================================================ */

function filterByCat(cat) {
  loadBookmarks().then(() => {
    if (currentCat === cat && panel.classList.contains('show')) {
      closePanel();
      return;
    }
    
    currentCat = cat;
    document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.cat-tab[data-cat="${cat}"]`)?.classList.add('active');
    
    panel.classList.remove('hidden');
    panel.classList.add('show');
    renderBookmarks();
  });
}

function closePanel() {
  panel.classList.remove('show');
  panel.classList.add('hidden');
}

function toggleBookmarks() {
  const quickLinks = document.getElementById('quickLinks');
  const divider = document.querySelector('.divider');
  
  quickLinks.classList.toggle('hidden');
  divider.classList.toggle('collapsed');
  
  if (panel.classList.contains('show')) closePanel();
}

/* ================================================================
   搜索过滤
================================================================ */

searchInput.addEventListener('input', function() {
  const query = this.value.toLowerCase().trim();
  
  if (!query) {
    document.querySelectorAll('.card').forEach(c => c.style.display = '');
    return;
  }
  
  loadBookmarks().then(() => {
    currentCat = 'all';
    panel.classList.add('show');
    renderBookmarks();
    
    requestAnimationFrame(() => {
      document.querySelectorAll('.card').forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const url = (card.dataset.url || '').toLowerCase();
        card.style.display = (name.includes(query) || url.includes(query)) ? '' : 'none';
      });
    });
  });
});

searchInput.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    searchInput.value = '';
    searchInput.dispatchEvent(new Event('input'));
    searchInput.blur();
  }
});

/* ================================================================
   全局事件
================================================================ */

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && panel.classList.contains('show')) {
    closePanel();
  }
});

document.addEventListener('click', e => {
  const catWrap = document.getElementById('catWrap') || document.getElementById('categoryBar');
  if (panel.classList.contains('show') && !panel.contains(e.target) && !catWrap.contains(e.target)) {
    closePanel();
  }
});
