(function () {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const outlineEl = document.getElementById('outline');
  const statusEl = document.getElementById('save-status');
  const exportPanel = document.getElementById('export-panel');
  const exportEl = document.getElementById('export-text');
  const exportCloseButton = document.getElementById('export-close-button');
  const addRootButton = document.getElementById('add-root-button');
  const exportButton = document.getElementById('export-button');
  const menuToggleButton = document.getElementById('menu-toggle-button');
  const menuPanel = document.getElementById('topbar-menu-panel');

  const state = {
    nodes: new Map(),
    children: new Map(),
    rootIds: [],
    activeNodeId: null,
    savingTimers: new Map(),
    pointer: null
  };

  function setStatus(text, mode) {
    statusEl.textContent = text;
    statusEl.dataset.mode = mode || '';
  }

  async function apiGet(path) {
    const response = await fetch(path, { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  async function apiPost(path, body) {
    const response = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(body)
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  function childKey(parentId) {
    return parentId === null ? 'root' : String(parentId);
  }

  function rebuildState(nodes) {
    state.nodes = new Map();
    state.children = new Map();
    state.rootIds = [];

    for (const node of nodes) {
      state.nodes.set(node.id, node);
      const key = childKey(node.parent_id);
      if (!state.children.has(key)) {
        state.children.set(key, []);
      }
      state.children.get(key).push(node.id);
    }

    for (const ids of state.children.values()) {
      ids.sort((a, b) => {
        const nodeA = state.nodes.get(a);
        const nodeB = state.nodes.get(b);
        return nodeA.position - nodeB.position || nodeA.id - nodeB.id;
      });
    }

    state.rootIds = state.children.get('root') || [];
  }

  function getChildren(parentId) {
    return state.children.get(childKey(parentId)) || [];
  }

  function hasChildren(id) {
    return getChildren(id).length > 0;
  }

  function renderTree() {
    const fragment = document.createDocumentFragment();

    for (const id of state.rootIds) {
      renderNode(fragment, id, 0);
    }

    outlineEl.replaceChildren(fragment);

    if (state.activeNodeId !== null) {
      focusNode(state.activeNodeId);
    }
  }

  function renderNode(parentEl, id, depth) {
    const node = state.nodes.get(id);
    if (!node) {
      return;
    }

    const row = document.createElement('div');
    row.className = 'node';
    row.dataset.id = String(id);
    row.style.setProperty('--depth', String(depth));

    if (state.activeNodeId === id) {
      row.classList.add('is-active');
    }

    const toggle = document.createElement('button');
    toggle.className = 'toggle-button';
    toggle.type = 'button';
    toggle.dataset.id = String(id);

    if (hasChildren(id)) {
      toggle.textContent = node.collapsed ? '▸' : '▾';
      toggle.setAttribute('aria-label', node.collapsed ? '展開' : '折りたたみ');
    } else {
      toggle.textContent = '•';
      toggle.disabled = true;
      toggle.setAttribute('aria-hidden', 'true');
    }

    const text = document.createElement('div');
    text.className = 'node-text';
    text.dataset.id = String(id);
    text.contentEditable = 'true';
    text.spellcheck = false;
    text.textContent = node.text;
    text.setAttribute('role', 'textbox');
    text.setAttribute('aria-label', 'ノード本文');

    const actions = document.createElement('div');
    actions.className = 'node-actions';

    const addButton = makeIconButton('+', '下に追加', () => createNodeAfter(id));
    const indentButton = makeIconButton('→', '子にする', () => indentNode(id));
    const outdentButton = makeIconButton('←', '親に戻す', () => outdentNode(id));

    actions.append(addButton, indentButton, outdentButton);
    row.append(toggle, text, actions);
    parentEl.append(row);

    if (!node.collapsed) {
      for (const childId of getChildren(id)) {
        renderNode(parentEl, childId, depth + 1);
      }
    }
  }

  function makeIconButton(label, title, handler) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'icon-button';
    button.textContent = label;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.addEventListener('click', handler);
    return button;
  }

  function focusNode(id) {
    const text = outlineEl.querySelector(`.node-text[data-id="${id}"]`);
    if (!text) {
      return;
    }

    text.focus();
    placeCaretAtEnd(text);
  }

  function placeCaretAtEnd(el) {
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function visibleIds() {
    const ids = [];
    const walk = (id) => {
      ids.push(id);
      const node = state.nodes.get(id);
      if (!node || node.collapsed) {
        return;
      }
      for (const childId of getChildren(id)) {
        walk(childId);
      }
    };

    for (const id of state.rootIds) {
      walk(id);
    }
    return ids;
  }

  function focusVisibleOffset(id, offset) {
    const ids = visibleIds();
    const index = ids.indexOf(id);
    if (index === -1) {
      return;
    }
    const nextId = ids[index + offset];
    if (nextId !== undefined) {
      state.activeNodeId = nextId;
      renderTree();
    }
  }

  async function loadTree() {
    try {
      setStatus('読み込み中', 'saving');
      const data = await apiGet('api/tree.php');
      rebuildState(data.nodes);
      state.activeNodeId = state.rootIds[0] || null;
      renderTree();
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('読み込み失敗', 'error');
      console.error(error);
    }
  }

  async function createNode(parentId, position, text) {
    const data = await apiPost('api/node_create.php', {
      parent_id: parentId,
      position,
      text: text || ''
    });
    rebuildState(data.nodes);
    state.activeNodeId = data.node.id;
    renderTree();
    setStatus('保存済み', 'saved');
  }

  async function createNodeAfter(id) {
    const node = state.nodes.get(id);
    if (!node) {
      await createNode(null, state.rootIds.length, '');
      return;
    }

    const siblings = getChildren(node.parent_id);
    const position = siblings.indexOf(id) + 1;
    setStatus('保存中', 'saving');
    await createNode(node.parent_id, position, '');
  }

  async function indentNode(id) {
    const node = state.nodes.get(id);
    if (!node) {
      return;
    }

    const siblings = getChildren(node.parent_id);
    const index = siblings.indexOf(id);
    if (index <= 0) {
      return;
    }

    const newParentId = siblings[index - 1];
    const newPosition = getChildren(newParentId).length;
    await moveNode(id, newParentId, newPosition);
  }

  async function outdentNode(id) {
    const node = state.nodes.get(id);
    if (!node || node.parent_id === null) {
      return;
    }

    const parent = state.nodes.get(node.parent_id);
    if (!parent) {
      return;
    }

    const newParentId = parent.parent_id;
    const parentSiblings = getChildren(newParentId);
    const newPosition = parentSiblings.indexOf(parent.id) + 1;
    await moveNode(id, newParentId, newPosition);
  }

  async function moveNode(id, parentId, position) {
    try {
      setStatus('保存中', 'saving');
      const data = await apiPost('api/node_move.php', { id, parent_id: parentId, position });
      rebuildState(data.nodes);
      state.activeNodeId = id;
      renderTree();
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('保存失敗', 'error');
      console.error(error);
    }
  }

  function scheduleSaveText(id, text) {
    const node = state.nodes.get(id);
    if (node) {
      node.text = text;
    }

    if (state.savingTimers.has(id)) {
      clearTimeout(state.savingTimers.get(id));
    }

    setStatus('未保存', 'dirty');
    state.savingTimers.set(id, setTimeout(async () => {
      try {
        setStatus('保存中', 'saving');
        await apiPost('api/node_update.php', { id, text });
        setStatus('保存済み', 'saved');
      } catch (error) {
        setStatus('保存失敗', 'error');
        console.error(error);
      } finally {
        state.savingTimers.delete(id);
      }
    }, 500));
  }

  async function toggleNode(id) {
    const node = state.nodes.get(id);
    if (!node || !hasChildren(id)) {
      return;
    }

    const collapsed = node.collapsed ? 0 : 1;
    node.collapsed = collapsed;
    renderTree();

    try {
      await apiPost('api/node_update.php', { id, collapsed });
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('保存失敗', 'error');
      console.error(error);
    }
  }

  async function deleteNode(id) {
    const ids = visibleIds();
    const index = ids.indexOf(id);
    const nextFocus = ids[Math.max(0, index - 1)] || null;

    try {
      setStatus('保存中', 'saving');
      const data = await apiPost('api/node_delete.php', { id });
      rebuildState(data.nodes);
      state.activeNodeId = nextFocus && state.nodes.has(nextFocus) ? nextFocus : (state.rootIds[0] || null);
      renderTree();
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('保存失敗', 'error');
      console.error(error);
    }
  }

  function exportMarkdown() {
    const lines = [];
    const walk = (id, depth) => {
      const node = state.nodes.get(id);
      if (!node) {
        return;
      }
      const text = node.text.trim() || '(空)';
      lines.push(`${'  '.repeat(depth)}- ${text}`);
      for (const childId of getChildren(id)) {
        walk(childId, depth + 1);
      }
    };

    for (const id of state.rootIds) {
      walk(id, 0);
    }

    exportEl.value = lines.join('\n');
    exportPanel.hidden = exportEl.value.length === 0;
    exportEl.focus();
    exportEl.select();
  }

  function closeExportPanel() {
    exportPanel.hidden = true;
  }

  function setMenuOpen(isOpen) {
    menuPanel.hidden = !isOpen;
    menuToggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  function toggleMenu() {
    setMenuOpen(menuPanel.hidden);
  }

  outlineEl.addEventListener('focusin', (event) => {
    const text = event.target.closest('.node-text');
    if (!text) {
      return;
    }
    state.activeNodeId = Number(text.dataset.id);
    renderActiveOnly();
  });

  function renderActiveOnly() {
    for (const row of outlineEl.querySelectorAll('.node')) {
      row.classList.toggle('is-active', Number(row.dataset.id) === state.activeNodeId);
    }
  }

  outlineEl.addEventListener('input', (event) => {
    const text = event.target.closest('.node-text');
    if (!text) {
      return;
    }

    scheduleSaveText(Number(text.dataset.id), text.textContent || '');
  });

  outlineEl.addEventListener('click', (event) => {
    const toggle = event.target.closest('.toggle-button');
    if (!toggle || toggle.disabled) {
      return;
    }
    toggleNode(Number(toggle.dataset.id));
  });

  outlineEl.addEventListener('keydown', async (event) => {
    const text = event.target.closest('.node-text');
    if (!text) {
      return;
    }

    const id = Number(text.dataset.id);
    state.activeNodeId = id;

    if (event.key === 'Enter') {
      event.preventDefault();
      await createNodeAfter(id);
    } else if (event.key === 'Tab') {
      event.preventDefault();
      if (event.shiftKey) {
        await outdentNode(id);
      } else {
        await indentNode(id);
      }
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusVisibleOffset(id, -1);
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusVisibleOffset(id, 1);
    } else if (event.key === 'Backspace' && (text.textContent || '') === '') {
      event.preventDefault();
      await deleteNode(id);
    }
  });

  outlineEl.addEventListener('pointerdown', (event) => {
    const row = event.target.closest('.node');
    if (!row || event.target.closest('button')) {
      return;
    }

    state.pointer = {
      id: Number(row.dataset.id),
      x: event.clientX,
      y: event.clientY
    };
  });

  outlineEl.addEventListener('pointerup', async (event) => {
    if (!state.pointer) {
      return;
    }

    const dx = event.clientX - state.pointer.x;
    const dy = event.clientY - state.pointer.y;
    const id = state.pointer.id;
    state.pointer = null;

    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.4) {
      return;
    }

    if (dx > 0) {
      await indentNode(id);
    } else {
      await outdentNode(id);
    }
  });

  outlineEl.addEventListener('pointercancel', () => {
    state.pointer = null;
  });

  addRootButton.addEventListener('click', async () => {
    setStatus('保存中', 'saving');
    await createNode(null, state.rootIds.length, '');
  });

  menuToggleButton.addEventListener('click', (event) => {
    event.stopPropagation();
    toggleMenu();
  });

  exportButton.addEventListener('click', () => {
    exportMarkdown();
    setMenuOpen(false);
  });

  exportCloseButton.addEventListener('click', closeExportPanel);

  document.addEventListener('click', (event) => {
    if (menuPanel.hidden || event.target.closest('.topbar-menu')) {
      return;
    }
    setMenuOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || menuPanel.hidden) {
      return;
    }
    setMenuOpen(false);
    menuToggleButton.focus();
  });

  loadTree();
})();
