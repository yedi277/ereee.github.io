/* ================================================================
   书签管理后台 - 主逻辑
================================================================ */

const STORAGE_KEY = 'navBookmarks';
let bookmarks = [];
let currentCat = 'all';
let parsedBookmarks = null;

// 初始化
function init() {
  loadData();
  renderTabs();
  renderList();
}

// 数据持久化
function loadData() {
  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved) {
    bookmarks = JSON.parse(saved);
  } else {
    bookmarks = [{ cat: '默认分类', icon: '📁', items: [] }];
    saveData();
  }
}

function saveData() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(bookmarks));
}

// 渲染分类标签
function renderTabs() {
  const tabs = document.getElementById('categoryTabs');
  const total = getTotalCount();
  
  let html = `<div class="tab ${currentCat === 'all' ? 'active' : ''}" onclick="filterByCat('all')">全部<span class="count">${total}</span></div>`;
  
  bookmarks.forEach((b, i) => {
    html += `<div class="tab ${currentCat === b.cat ? 'active' : ''}" onclick="filterByCat('${b.cat}')" style="display:flex;align-items:center;gap:4px;position:relative;">
      <span>${b.icon} ${b.cat}<span class="count">${b.items.length}</span></span>
      <button class="tab-delete-btn" onclick="event.stopPropagation();deleteCategory(${i})">×</button>
    </div>`;
  });
  
  tabs.innerHTML = html;
}

function getTotalCount() {
  return bookmarks.reduce((sum, b) => sum + b.items.length, 0);
}

function filterByCat(cat) {
  currentCat = cat;
  renderTabs();
  renderList();
}

// 渲染书签列表
function openBookmark(url) {
  window.open(url, '_blank');
}

function renderList() {
  const list = document.getElementById('bookmarkList');
  const items = [];
  
  bookmarks.forEach((cat, catIndex) => {
    cat.items.forEach((item, itemIndex) => {
      if (currentCat === 'all' || currentCat === cat.cat) {
        items.push({
          cat: cat.cat,
          catIcon: cat.icon,
          catIndex: catIndex,
          name: item[0],
          url: item[1],
          icon: item[2],
          itemIndex: itemIndex
        });
      }
    });
  });
  
  if (items.length === 0) {
    list.innerHTML = `<div class="empty"><div class="empty-icon">📭</div><p>暂无书签</p></div>`;
    return;
  }
  
  list.innerHTML = items.map(item => `
    <div class="bookmark-item" data-cat="${item.catIndex}" data-item="${item.itemIndex}">
      <span class="drag-handle">⋮⋮</span>
      ${renderIcon(item.icon)}
      <div class="info">
        <div class="name" onclick="openBookmark('${escapeUrl(item.url)}')">${item.name}</div>
        <div class="url" onclick="openBookmark('${escapeUrl(item.url)}')">${item.url}</div>
      </div>
      <span class="cat-tag">${item.catIcon} ${item.cat}</span>
      <div class="actions">
        <button class="btn btn-primary" onclick="editBookmark(${item.catIndex}, ${item.itemIndex})">编辑</button>
        <button class="btn btn-danger" onclick="deleteBookmark(${item.catIndex}, ${item.itemIndex})">删除</button>
      </div>
    </div>
  `).join('');
  
  initDrag();
}

function escapeUrl(url) {
  return url.replace(/'/g, "\\'");
}

function renderIcon(icon) {
  if (!icon) return '<span class="icon">🔗</span>';
  if (icon.startsWith('data:image') || icon.startsWith('http')) {
    return `<span class="icon"><img src="${icon}" alt="" style="width:20px;height:20px;object-fit:contain;vertical-align:middle;"></span>`;
  }
  return `<span class="icon">${icon}</span>`;
}

// 拖拽排序
function initDrag() {
  const items = document.querySelectorAll('.bookmark-item');
  items.forEach(item => {
    item.draggable = true;
    item.addEventListener('dragstart', handleDragStart);
    item.addEventListener('dragover', handleDragOver);
    item.addEventListener('drop', handleDrop);
    item.addEventListener('dragend', handleDragEnd);
  });
}

let draggedItem = null;

function handleDragStart(e) {
  draggedItem = this;
  this.classList.add('dragging');
}

function handleDragOver(e) {
  e.preventDefault();
}

function handleDrop(e) {
  e.preventDefault();
  if (this === draggedItem) return;
  
  const fromCat = parseInt(draggedItem.dataset.cat);
  const fromItem = parseInt(draggedItem.dataset.item);
  const toCat = parseInt(this.dataset.cat);
  const toItem = parseInt(this.dataset.item);
  
  const [moved] = bookmarks[fromCat].items.splice(fromItem, 1);
  bookmarks[toCat].items.splice(toItem, 0, moved);
  
  saveData();
  renderList();
}

function handleDragEnd() {
  this.classList.remove('dragging');
}

// 添加/编辑书签弹窗
function showAddModal() {
  document.getElementById('modalTitle').textContent = '添加书签';
  document.getElementById('editIndex').value = '';
  document.getElementById('bookmarkName').value = '';
  document.getElementById('bookmarkUrl').value = '';
  document.getElementById('bookmarkIcon').value = '🔗';
  
  fillCatSelect();
  document.getElementById('bookmarkModal').classList.add('show');
}

function editBookmark(catIndex, itemIndex) {
  const cat = bookmarks[catIndex];
  const item = cat.items[itemIndex];
  
  document.getElementById('modalTitle').textContent = '编辑书签';
  document.getElementById('editIndex').value = `${catIndex},${itemIndex}`;
  document.getElementById('bookmarkName').value = item[0];
  document.getElementById('bookmarkUrl').value = item[1];
  document.getElementById('bookmarkIcon').value = item[2];
  
  fillCatSelect(cat.cat);
  document.getElementById('bookmarkModal').classList.add('show');
}

function fillCatSelect(selected) {
  const select = document.getElementById('bookmarkCat');
  select.innerHTML = bookmarks.map(b => 
    `<option value="${b.cat}" ${b.cat === selected ? 'selected' : ''}>${b.icon} ${b.cat}</option>`
  ).join('');
}

function saveBookmark(e) {
  e.preventDefault();
  
  const editIndex = document.getElementById('editIndex').value;
  const catName = document.getElementById('bookmarkCat').value;
  const name = document.getElementById('bookmarkName').value.trim();
  const url = document.getElementById('bookmarkUrl').value.trim();
  const icon = document.getElementById('bookmarkIcon').value.trim() || '🔗';
  
  const newItem = [name, url, icon];
  
  if (editIndex) {
    const [oldCatIndex, oldItemIndex] = editIndex.split(',').map(Number);
    const oldCat = bookmarks[oldCatIndex].cat;
    
    if (oldCat === catName) {
      bookmarks[oldCatIndex].items[oldItemIndex] = newItem;
    } else {
      bookmarks[oldCatIndex].items.splice(oldItemIndex, 1);
      const newCatIndex = bookmarks.findIndex(b => b.cat === catName);
      bookmarks[newCatIndex].items.push(newItem);
    }
  } else {
    const catIndex = bookmarks.findIndex(b => b.cat === catName);
    bookmarks[catIndex].items.push(newItem);
  }
  
  saveData();
  closeModal();
  renderTabs();
  renderList();
}

function deleteBookmark(catIndex, itemIndex) {
  if (!confirm('确定删除这个书签吗？')) return;
  
  bookmarks[catIndex].items.splice(itemIndex, 1);
  saveData();
  renderTabs();
  renderList();
}

function closeModal() {
  document.getElementById('bookmarkModal').classList.remove('show');
}

// 分类管理
function showAddCatModal() {
  document.getElementById('catName').value = '';
  document.getElementById('catIcon').value = '📁';
  document.getElementById('catModal').classList.add('show');
}

function closeCatModal() {
  document.getElementById('catModal').classList.remove('show');
}

function saveCategory(e) {
  e.preventDefault();
  
  const name = document.getElementById('catName').value.trim();
  const icon = document.getElementById('catIcon').value.trim() || '📁';
  
  if (bookmarks.some(b => b.cat === name)) {
    alert('分类已存在！');
    return;
  }
  
  bookmarks.push({ cat: name, icon: icon, items: [] });
  saveData();
  closeCatModal();
  renderTabs();
  renderList();
}

function deleteCategory(catIndex) {
  const cat = bookmarks[catIndex];
  const itemCount = cat.items.length;
  
  if (!confirm(`确定删除分类"${cat.cat}"吗？\n该分类下有 ${itemCount} 个书签，删除后无法恢复。`)) return;
  
  bookmarks.splice(catIndex, 1);
  
  if (currentCat === cat.cat) {
    currentCat = 'all';
  }
  
  saveData();
  renderTabs();
  renderList();
}

// 导出功能
function exportData() {
  const jsCode = JSON.stringify(bookmarks, null, 2);
  document.getElementById('exportContent').textContent = `const bookmarks = ${jsCode};`;
  document.getElementById('exportModal').classList.add('show');
}

function closeExportModal() {
  document.getElementById('exportModal').classList.remove('show');
}

function copyExport() {
  const content = document.getElementById('exportContent').textContent;
  navigator.clipboard.writeText(content).then(() => {
    alert('已复制到剪贴板！');
  });
}

function downloadBookmarksJS() {
  const jsCode = JSON.stringify(bookmarks, null, 2);
  const content = `const bookmarks = ${jsCode};`;
  const blob = new Blob([content], { type: 'text/javascript;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'bookmarks.js';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function exportHTML() {
  const choice = confirm('导出 HTML 书签时是否包含图标？\n\n确定 = 包含图标\n取消 = 不包含图标');
  const html = generateBookmarkHTML(choice);
  const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `bookmarks_${new Date().toISOString().split('T')[0]}.html`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function generateBookmarkHTML(withIcon) {
  const now = new Date().toLocaleString('zh-CN');
  let html = `<!DOCTYPE NETSCAPE-Bookmark-file-1>
<!-- This is an automatically generated file. -->
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>书签</TITLE>
<H1>书签</H1>
<DL><p>
<DT><H3 ADD_DATE="${Math.floor(Date.now() / 1000)}">个人网址导航</H3>
<DL><p>
`;
  
  bookmarks.forEach(cat => {
    const catTime = Math.floor(Date.now() / 1000);
    html += ` <DT><H3 ADD_DATE="${catTime}">${cat.cat}</H3>\n <DL><p>\n`;
    
    cat.items.forEach(([name, url, icon]) => {
      let iconAttr = '';
      if (withIcon && icon) {
        if (icon.startsWith('http://') || icon.startsWith('https://') || icon.startsWith('data:image')) {
          iconAttr = ` ICON="${icon}"`;
        } else if (icon.length <= 2) {
          const emojiDataUri = `data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text x="50" y="50" font-size="80" text-anchor="middle" dominant-baseline="middle">${encodeURIComponent(icon)}</text></svg>`;
          iconAttr = ` ICON="${emojiDataUri}"`;
        }
      }
      html += ` <DT><A HREF="${url}" ADD_DATE="${catTime}"${iconAttr}>${name}</A>\n`;
    });
    
    html += ` </DL><p>\n`;
  });
  
  html += ` </DL><p>
</DL><p>
<!-- 导出时间: ${now} -->
</html>`;
  
  return html;
}

// 导入功能
function showImportModal() {
  document.getElementById('importModal').classList.add('show');
  document.getElementById('importPreview').style.display = 'none';
  document.getElementById('importFile').value = '';
  parsedBookmarks = null;
}

function closeImportModal() {
  document.getElementById('importModal').classList.remove('show');
  parsedBookmarks = null;
}

function handleFileSelect(event) {
  const file = event.target.files[0];
  if (!file) return;
  
  const reader = new FileReader();
  reader.onload = function(e) {
    parseBookmarkHTML(e.target.result);
  };
  reader.readAsText(file, 'UTF-8');
}

function parseBookmarkHTML(html) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(html, 'text/html');
  
  parsedBookmarks = [];
  
  function parseDL(dl, folderName = '未分类') {
    const items = dl.querySelectorAll(':scope > dt');
    let currentFolder = folderName;
    
    items.forEach(dt => {
      const h3 = dt.querySelector(':scope > h3');
      const a = dt.querySelector(':scope > a');
      const subDL = dt.querySelector(':scope > dl');
      
      if (h3) {
        currentFolder = h3.textContent.trim() || '未分类';
        if (subDL) parseDL(subDL, currentFolder);
      } else if (a) {
        const name = truncateName(a.textContent.trim());
        const url = a.href;
        const icon = extractIcon(a.getAttribute('icon'), url);
        
        if (name && url && url !== 'about:blank') {
          let cat = parsedBookmarks.find(c => c.cat === folderName);
          if (!cat) {
            cat = { cat: folderName, icon: '📁', items: [] };
            parsedBookmarks.push(cat);
          }
          cat.items.push([name, url, icon]);
        }
      }
    });
  }
  
  const rootDL = doc.querySelector('dl') || doc.body;
  parseDL(rootDL, '导入的书签');
  
  if (parsedBookmarks.length === 0) {
    const allLinks = doc.querySelectorAll('a');
    const cat = { cat: '导入的书签', icon: '📁', items: [] };
    
    allLinks.forEach(a => {
      const name = truncateName(a.textContent.trim());
      const url = a.href;
      const icon = extractIcon(a.getAttribute('icon'), url);
      
      if (name && url && url !== 'about:blank') {
        cat.items.push([name, url, icon]);
      }
    });
    
    if (cat.items.length > 0) {
      parsedBookmarks.push(cat);
    }
  }
  
  showImportPreview();
}

function truncateName(name, maxLen = 20) {
  if (!name) return '';
  name = name.trim();
  return name.length <= maxLen ? name : name.substring(0, maxLen) + '...';
}

function extractIcon(iconAttr, url) {
  if (!iconAttr || iconAttr.trim() === '') {
    return getFaviconFromUrl(url) || '🔗';
  }
  
  const icon = iconAttr.trim();
  if (icon.startsWith('data:image') || icon.startsWith('http://') || icon.startsWith('https://')) {
    return icon;
  }
  
  if (/^[\u{1F300}-\u{1F9FF}]|[\u{2600}-\u{26FF}]|[\u{2700}-\u{27BF}]$/u.test(icon)) {
    return icon;
  }
  
  return getFaviconFromUrl(url) || '🔗';
}

function getFaviconFromUrl(url) {
  const iconMap = {
    'baidu.com': '🔍', 'google.com': '🌐', 'github.com': '🐙',
    'youtube.com': '▶️', 'bilibili.com': '📺', 'taobao.com': '🛍️',
    'jd.com': '📦', 'weibo.com': '🐦', 'zhihu.com': '💬',
    'qq.com': '🐧', '163.com': '📧', 'aliyun.com': '☁️',
    'steam.com': '🎮', 'douyu.com': '🐠', 'acfun.cn': '📺'
  };
  
  try {
    const domain = new URL(url).hostname;
    for (let [key, icon] of Object.entries(iconMap)) {
      if (domain.includes(key)) return icon;
    }
    return '🔗';
  } catch {
    return '🔗';
  }
}

function getExistingUrls() {
  const urls = new Set();
  bookmarks.forEach(cat => {
    cat.items.forEach(item => {
      urls.add(normalizeUrl(item[1]));
    });
  });
  return urls;
}

function normalizeUrl(url) {
  try {
    const u = new URL(url);
    return u.hostname + u.pathname.replace(/\/$/, '');
  } catch {
    return url.toLowerCase().replace(/\/$/, '');
  }
}

function showImportPreview() {
  const existingUrls = getExistingUrls();
  
  let totalCount = 0, newCount = 0, duplicateCount = 0;
  let html = '';
  
  parsedBookmarks.forEach(cat => {
    const newItems = cat.items.filter(item => !existingUrls.has(normalizeUrl(item[1])));
    const dupItems = cat.items.filter(item => existingUrls.has(normalizeUrl(item[1])));
    
    totalCount += cat.items.length;
    newCount += newItems.length;
    duplicateCount += dupItems.length;
    
    html += `<div class="preview-folder">
      <div class="preview-folder-name">📁 ${cat.cat} <span class="count">(${cat.items.length}个书签)</span></div>
      <div class="preview-items">
        ${newItems.map(item => `
          <div class="preview-item">
            ${renderIcon(item[2])}
            <span class="name">${item[0]}</span>
            <span class="url">${item[1]}</span>
          </div>
        `).join('')}
        ${dupItems.map(item => `
          <div class="preview-item existing">
            ${renderIcon(item[2])}
            <span class="name">${item[0]}</span>
            <span class="url">${item[1]}</span>
          </div>
        `).join('')}
      </div>
    </div>`;
  });
  
  document.getElementById('previewContent').innerHTML = html;
  document.getElementById('importStats').innerHTML = 
    `共 ${totalCount} 个书签 | ✅ 新增 ${newCount} 个 | 🟡 重复 ${duplicateCount} 个`;
  document.getElementById('importPreview').style.display = 'block';
  
  const targetSelect = document.getElementById('targetCategory');
  targetSelect.innerHTML = bookmarks.map(b => 
    `<option value="${b.cat}">${b.icon} ${b.cat}</option>`
  ).join('');
  
  document.getElementById('importCategory').onchange = function() {
    document.getElementById('existingCatSelect').style.display = 
      this.value === 'existing' ? 'block' : 'none';
  };
}

function confirmImport() {
  if (!parsedBookmarks || parsedBookmarks.length === 0) {
    alert('没有可导入的书签！');
    return;
  }
  
  const importMode = document.getElementById('importCategory').value;
  const skipDuplicate = document.getElementById('skipDuplicate').checked;
  const existingUrls = getExistingUrls();
  
  let imported = 0;
  
  if (importMode === 'new') {
    parsedBookmarks.forEach(parsedCat => {
      let targetCat = bookmarks.find(b => b.cat === parsedCat.cat);
      
      if (!targetCat) {
        targetCat = { cat: parsedCat.cat, icon: parsedCat.icon, items: [] };
        bookmarks.push(targetCat);
      }
      
      parsedCat.items.forEach(item => {
        if (skipDuplicate && existingUrls.has(normalizeUrl(item[1]))) return;
        targetCat.items.push(item);
        imported++;
      });
    });
  } else {
    const targetCatName = document.getElementById('targetCategory').value;
    const targetCat = bookmarks.find(b => b.cat === targetCatName);
    
    if (!targetCat) {
      alert('请选择目标分类！');
      return;
    }
    
    parsedBookmarks.forEach(parsedCat => {
      parsedCat.items.forEach(item => {
        if (skipDuplicate && existingUrls.has(normalizeUrl(item[1]))) return;
        targetCat.items.push(item);
        imported++;
      });
    });
  }
  
  saveData();
  closeImportModal();
  renderTabs();
  renderList();
  
  alert(`导入完成！成功导入 ${imported} 个书签。`);
}

// 清空数据
function clearAllData() {
  if (!confirm('⚠️ 确定要清空所有书签数据吗？\n\n此操作不可撤销！')) return;
  if (!confirm('再次确认：真的要删除所有书签吗？')) return;
  
  bookmarks = [];
  saveData();
  renderTabs();
  renderList();
  alert('已清空所有书签数据！');
}

// 事件绑定
document.addEventListener('DOMContentLoaded', function() {
  const importZone = document.getElementById('importZone');
  
  if (importZone) {
    importZone.addEventListener('dragover', function(e) {
      e.preventDefault();
      this.classList.add('dragover');
    });
    
    importZone.addEventListener('dragleave', function(e) {
      e.preventDefault();
      this.classList.remove('dragover');
    });
    
    importZone.addEventListener('drop', function(e) {
      e.preventDefault();
      this.classList.remove('dragover');
      
      const file = e.dataTransfer.files[0];
      if (file && (file.name.endsWith('.html') || file.name.endsWith('.htm'))) {
        const reader = new FileReader();
        reader.onload = function(e) {
          parseBookmarkHTML(e.target.result);
        };
        reader.readAsText(file, 'UTF-8');
      } else {
        alert('请选择 HTML 格式的书签文件！');
      }
    });
  }
});

// 点击弹窗外部关闭
document.querySelectorAll('.modal').forEach(modal => {
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.remove('show');
    }
  });
});

// 初始化
init();
