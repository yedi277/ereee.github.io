/* ================================================================
   个人网址导航 - 主逻辑 (v2.0 - 优化交互体验)
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
let searchDebounceTimer = null;

const panel = document.getElementById('bookmarks');
const searchInput = document.getElementById('search');
const engineInput = document.getElementById('engine-input');

/* ================================================================
   工具函数
================================================================ */

// 防抖函数
function debounce(func, delay) {
  let timer;
  return function(...args) {
    clearTimeout(timer);
    timer = setTimeout(() => func.apply(this, args), delay);
  };
}

// Toast 提示
function showToast(message, duration = 2000) {
  let toast = document.querySelector('.toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.className = 'toast';
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.classList.add('show');
  
  setTimeout(() => {
    toast.classList.remove('show');
  }, duration);
}

// 高亮搜索匹配
function highlightText(text, query) {
  if (!query) return text;
  try {
    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<mark>$1</mark>');
  } catch (e) {
    return text;
  }
}

/* ================================================================
   搜索引擎
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
    document.querySelector('.engine-tabs .active')?.classList.remove('active');
    this.classList.add('active');
    currentEngine = this.dataset.engine;
    showToast(`已切换到：${this.textContent}`);
  });
});

// 回车搜索
engineInput.addEventListener('keydown', e => {
  if (e.key === 'Enter') doSearch();
});

/* ================================================================
   书签加载与渲染
================================================================ */

let allBookmarkItems = [];
const VIRTUAL_SCROLL_THRESHOLD = 100; // 超过100个书签启用虚拟滚动
let isVirtualScroll = false;
let virtualScrollContainer = null;

function loadBookmarks() {
  if (bookmarksLoaded) return Promise.resolve();
  if (bookmarksPromise) return bookmarksPromise;
  
  panel.innerHTML = '<div style="padding:12px;color:var(--muted);">加载中...</div>';
  
  bookmarksPromise = new Promise(resolve => {
    const script = document.createElement('script');
    script.src = 'js/bookmarks.js';
    script.onload = () => {
      bookmarksLoaded = true;
      // 扁平化所有书签
      allBookmarkItems = bookmarks.flatMap(b => 
        (b.items || []).map(item => ({ ...item, cat: b.cat, icon: b.icon }))
      );
      initCategoryBar();
      resolve();
    };
    script.onerror = () => {
      panel.innerHTML = '<div style="padding:12px;color:#dc3545;">加载失败</div>';
      showToast('书签加载失败，请刷新重试');
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

function renderBookmarks(query = '') {
  let items = currentCat === 'all' 
    ? allBookmarkItems
    : allBookmarkItems.filter(item => item.cat === currentCat);
  
  // 搜索过滤
  if (query) {
    const q = query.toLowerCase();
    items = items.filter(item => 
      (item.name || '').toLowerCase().includes(q) || 
      (item.url || '').toLowerCase().includes(q)
    );
  }
  
  if (items.length === 0) {
    panel.innerHTML = '<div style="padding:20px;color:var(--muted);text-align:center;width:100%;">暂无书签，<a href="/sq.html" style="color:var(--accent);">去添加</a></div>';
    return;
  }
  
  // 超过阈值使用虚拟滚动
  if (items.length > VIRTUAL_SCROLL_THRESHOLD) {
    initVirtualScroll(items, query);
  } else {
    renderDirectly(items, query);
  }
}

// 直接渲染（少量书签）
function renderDirectly(items, query) {
  isVirtualScroll = false;
  panel.style.overflowY = 'auto';
  panel.style.height = 'auto';
  
  panel.innerHTML = items.map((item, idx) => {
    const [name, url, icon] = Array.isArray(item) ? item : [item.name, item.url, item.icon];
    const highlightedName = highlightText(name || '', query);
    return `
      <a class="card" href="${url}" target="_blank" rel="noopener" data-name="${name}" data-url="${url}" loading="lazy">
        <span class="icon">${renderIcon(icon)}</span>
        <span class="name">${highlightedName}</span>
      </a>
    `;
  }).join('');
}

// 虚拟滚动（大量书签）
function initVirtualScroll(items, query) {
  isVirtualScroll = true;
  const ITEM_HEIGHT = 32;
  const CONTAINER_HEIGHT = 400;
  const visibleCount = Math.ceil(CONTAINER_HEIGHT / ITEM_HEIGHT) + 5;
  
  panel.style.height = CONTAINER_HEIGHT + 'px';
  panel.style.overflowY = 'auto';
  panel.style.position = 'relative';
  
  const totalHeight = items.length * ITEM_HEIGHT;
  const spacer = document.createElement('div');
  spacer.style.height = totalHeight + 'px';
  spacer.style.position = 'relative';
  
  panel.innerHTML = '';
  panel.appendChild(spacer);
  
  function renderVisible() {
    const scrollTop = panel.scrollTop;
    const startIdx = Math.floor(scrollTop / ITEM_HEIGHT);
    const endIdx = Math.min(startIdx + visibleCount, items.length);
    
    // 移除旧节点
    const oldCards = panel.querySelectorAll('.card');
    oldCards.forEach(card => card.remove());
    
    // 渲染可见区域
    for (let i = startIdx; i < endIdx; i++) {
      const item = items[i];
      const [name, url, icon] = Array.isArray(item) ? item : [item.name, item.url, item.icon];
      const highlightedName = highlightText(name || '', query);
      
      const card = document.createElement('a');
      card.className = 'card';
      card.href = url;
      card.target = '_blank';
      card.rel = 'noopener';
      card.dataset.name = name;
      card.dataset.url = url;
      card.style.position = 'absolute';
      card.style.top = (i * ITEM_HEIGHT) + 'px';
      card.style.width = '30%';
      card.style.height = ITEM_HEIGHT + 'px';
      card.innerHTML = `
        <span class="icon">${renderIcon(icon)}</span>
        <span class="name">${highlightedName}</span>
      `;
      
      spacer.appendChild(card);
    }
  }
  
  panel.addEventListener('scroll', renderVisible);
  renderVisible();
}

function renderIcon(icon) {
  if (!icon) return '🔗';
  if (icon.startsWith('data:image') || icon.startsWith('http')) {
    return `<img src="${icon}" loading="lazy" style="width:16px;height:16px;object-fit:contain;" alt="" onerror="this.style.display='none'">`;
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
    
    // 显示当前分类
    if (cat !== 'all') {
      const catName = bookmarks.find(b => b.cat === cat)?.cat || cat;
      showToast(`分类：${catName}`);
    }
  });
}

function closePanel() {
  panel.classList.remove('show');
  setTimeout(() => panel.classList.add('hidden'), 250);
}

function toggleBookmarks() {
  const quickLinks = document.getElementById('quickLinks');
  const divider = document.querySelector('.divider');
  
  quickLinks.classList.toggle('hidden');
  divider.classList.toggle('collapsed');
  
  if (panel.classList.contains('show')) closePanel();
}

/* ================================================================
   搜索过滤（带防抖）
================================================================ */

const debouncedSearch = debounce(function(query) {
  if (!query) {
    document.querySelectorAll('.card').forEach(c => {
      c.style.display = '';
      const nameSpan = c.querySelector('.name');
      if (nameSpan) nameSpan.innerHTML = nameSpan.textContent;
    });
    return;
  }
  
  loadBookmarks().then(() => {
    currentCat = 'all';
    panel.classList.add('show');
    renderBookmarks(query);
    
    requestAnimationFrame(() => {
      document.querySelectorAll('.card').forEach(card => {
        const name = (card.dataset.name || '').toLowerCase();
        const url = (card.dataset.url || '').toLowerCase();
        card.style.display = (name.includes(query) || url.includes(query)) ? '' : 'none';
      });
    });
  });
}, 300);

searchInput.addEventListener('input', function() {
  const query = this.value.toLowerCase().trim();
  debouncedSearch(query);
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

/* ================================================================
   暗色模式
================================================================ */

const darkToggle = document.getElementById('darkToggle');

function applyTheme(dark) {
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  darkToggle.textContent = dark ? '☀️' : '🌙';
  darkToggle.setAttribute('title', dark ? '切换亮色模式' : '切换暗色模式');
  localStorage.setItem('theme', dark ? 'dark' : 'light');
  showToast(dark ? '已切换至暗色模式' : '已切换至亮色模式', 1500);
}

darkToggle.addEventListener('click', () => {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  applyTheme(!isDark);
});

// 初始化：读取本地存储或系统偏好
(function initTheme() {
  const saved = localStorage.getItem('theme');
  if (saved) {
    applyTheme(saved === 'dark');
  } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    applyTheme(true);
  } else {
    applyTheme(false);
  }
})();

// 监听系统主题变化
if (window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
    if (!localStorage.getItem('theme')) {
      applyTheme(e.matches);
    }
  });
}

/* ================================================================
   天气模块
================================================================ */

const weatherWidget = document.getElementById('weatherWidget');
const weatherPopup  = document.getElementById('weatherPopup');
const weatherTemp   = document.getElementById('weatherTemp');

// 获取用户 IP 所在城市并查询天气
function fetchWeather() {
  weatherTemp.textContent = '…';
  showWeatherPopup('<div class="w-loading">加载中…</div>');

  fetch('https://wttr.in/?format=j1')
    .then(r => r.json())
    .then(data => {
      const current = data.current_condition[0];
      const city    = data.nearest_area?.[0]?.areaName?.[0]?.value || '未知';
      const temp    = current.temp_C + '°C';
      const desc    = current.weatherDesc?.[0]?.value || '';
      const icon    = getWeatherEmoji(desc);
      const feels   = current.FeelsLikeC + '°C';
      const humidity= current.humidity + '%';
      const wind    = current.windspeedKmph + ' km/h';
      const uv      = current.UVIndex;
      const vis     = current.visibility + ' km';
      const sunrise = data.weather?.[0]?.astronomy?.[0]?.sunrise || '';
      const sunset  = data.weather?.[0]?.astronomy?.[0]?.sunset  || '';

      weatherTemp.textContent = icon + ' ' + temp;

      const html = `
        <div class="w-city">${city}</div>
        <div style="font-size:2rem;margin:4px 0;">${icon}</div>
        <div style="font-size:1.2rem;font-weight:600;">${temp}</div>
        <div class="w-desc">${desc}</div>
        <div style="margin-top:8px;line-height:1.7;text-align:left;">
          体感温度&nbsp;&nbsp;${feels}<br>
          湿度&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${humidity}<br>
          风速&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${wind}<br>
          能见度&nbsp;&nbsp;&nbsp;${vis}<br>
          紫外线&nbsp;&nbsp;&nbsp;${uv}<br>
          日出&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${sunrise}<br>
          日落&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;${sunset}
        </div>
        <a href="https://wttr.in/" target="_blank" rel="noopener">查看完整天气 →</a>
      `;
      showWeatherPopup(html);
    })
    .catch(() => {
      weatherTemp.textContent = '--°';
      showWeatherPopup('<div class="w-error">加载失败，请重试</div>');
    });
}

function showWeatherPopup(html) {
  weatherPopup.innerHTML = html;
  weatherPopup.classList.add('show');
}

function hideWeatherPopup() {
  weatherPopup.classList.remove('show');
}

function getWeatherEmoji(desc) {
  const d = desc.toLowerCase();
  if (d.includes('sun') || d.includes('clear'))          return '☀️';
  if (d.includes('partly'))                                return '⛅';
  if (d.includes('cloud') && d.includes('overcast'))      return '☁️';
  if (d.includes('cloud'))                                 return '🌥️';
  if (d.includes('rain') && d.includes('drizzle'))       return '🌦️';
  if (d.includes('rain') || d.includes('shower'))         return '🌧️';
  if (d.includes('thunder') || d.includes('storm'))       return '⛈️';
  if (d.includes('snow') || d.includes('sleet'))          return '🌨️';
  if (d.includes('fog') || d.includes('mist'))            return '🌫️';
  return '🌡️';
}

// 点击右上角天气 → 弹出/关闭详情
weatherWidget.addEventListener('click', e => {
  e.stopPropagation();
  if (weatherPopup.classList.contains('show')) {
    hideWeatherPopup();
  } else {
    // 已有温度数据则直接显示，否则重新拉取
    if (weatherTemp.textContent && weatherTemp.textContent !== '--°' && weatherTemp.textContent !== '…') {
      if (!weatherPopup.innerHTML || weatherPopup.querySelector('.w-loading')) {
        fetchWeather();
      } else {
        showWeatherPopup(weatherPopup.innerHTML);
      }
    } else {
      fetchWeather();
    }
  }
});

// 点击其他地方关闭
document.addEventListener('click', e => {
  if (weatherPopup.classList.contains('show') &&
      !weatherPopup.contains(e.target) &&
      !weatherWidget.contains(e.target)) {
    hideWeatherPopup();
  }
});

// ESC 关闭
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && weatherPopup.classList.contains('show')) {
    hideWeatherPopup();
  }
});

// 初始化（延迟加载，不阻塞页面）
setTimeout(() => {
  fetch('https://wttr.in/?format=j1')
    .then(r => r.json())
    .then(data => {
      const current = data.current_condition[0];
      const desc    = current.weatherDesc?.[0]?.value || '';
      weatherTemp.textContent = getWeatherEmoji(desc) + ' ' + current.temp_C + '°C';
    })
    .catch(() => {
      // 静默失败，保持 --° 显示
    });
}, 1500);

/* ================================================================
   图片加载错误处理
================================================================ */
document.addEventListener('DOMContentLoaded', function() {
  document.body.addEventListener('error', function(e) {
    if (e.target.tagName === 'IMG' && e.target.classList.contains('ql-icon')) {
      e.target.style.display = 'none';
    }
  }, true);
});

/* ================================================================
   页面加载完成提示
================================================================ */
window.addEventListener('load', function() {
  console.log('✅ 个人网址导航 v2.0 加载完成');
});
