(function () {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const outlineEl = document.getElementById('outline');
  const statusEl = document.getElementById('save-status');
  const exportPanel = document.getElementById('export-panel');
  const exportEl = document.getElementById('export-text');
  const exportCloseButton = document.getElementById('export-close-button');
  const addRootButton = document.getElementById('add-root-button');
  const filePickerOpenButton = document.getElementById('file-picker-open-button');
  const filePickerPanel = document.getElementById('file-picker-panel');
  const filePickerList = document.getElementById('file-picker-list');
  const filePickerCurrent = document.getElementById('file-picker-current');
  const filePickerCloseButton = document.getElementById('file-picker-close-button');
  const fileCreateButton = document.getElementById('file-create-button');
  const exportButton = document.getElementById('export-button');
  const menuToggleButton = document.getElementById('menu-toggle-button');
  const menuPanel = document.getElementById('topbar-menu-panel');
  const activeFileStorageKey = 'outlineEditor.activeFileId';

  const state = {
    files: [],
    activeFileId: null,
    nodes: new Map(),
    children: new Map(),
    rootIds: [],
    activeNodeId: null,
    savingTimers: new Map(),
    pointer: null,
    filePickerLastFocus: null
  };

  function setStatus(text, mode) {
    statusEl.textContent = text;
    statusEl.dataset.mode = mode || '';
  }

  function readStoredActiveFileId() {
    try {
      const value = window.localStorage.getItem(activeFileStorageKey);
      return value && /^\d+$/.test(value) ? Number(value) : null;
    } catch (error) {
      return null;
    }
  }

  function rememberActiveFileId(fileId) {
    if (fileId === null || fileId === undefined) {
      return;
    }

    try {
      window.localStorage.setItem(activeFileStorageKey, String(fileId));
    } catch (error) {
      console.warn('Cannot save active file id.', error);
    }
  }

  function forgetActiveFileId() {
    try {
      window.localStorage.removeItem(activeFileStorageKey);
    } catch (error) {
      console.warn('Cannot remove active file id.', error);
    }
  }

  async function apiGet(path) {
    const response = await fetch(path, { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  function activeFilePayload(extra) {
    rememberActiveFileId(state.activeFileId);
    return Object.assign({ file_id: state.activeFileId }, extra || {});
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

  function fileParts(file) {
    const rawName = String(file.name || '').trim() || '無題';
    const parts = rawName.split(/[\/／]+/).map((part) => part.trim()).filter(Boolean);

    if (parts.length <= 1) {
      return {
        folder: 'フォルダなし',
        name: parts[0] || rawName
      };
    }

    return {
      folder: parts.slice(0, -1).join(' / '),
      name: parts[parts.length - 1]
    };
  }

  function activeFileName() {
    const activeFile = state.files.find((file) => file.id === state.activeFileId);
    return activeFile ? String(activeFile.name) : '';
  }

  function groupedFiles() {
    const groups = new Map();

    for (const file of state.files) {
      const parts = fileParts(file);
      if (!groups.has(parts.folder)) {
        groups.set(parts.folder, []);
      }
      groups.get(parts.folder).push({ file, parts });
    }

    return [...groups.entries()].sort(([folderA], [folderB]) => {
      if (folderA === 'フォルダなし') {
        return -1;
      }
      if (folderB === 'フォルダなし') {
        return 1;
      }
      return folderA.localeCompare(folderB, 'ja');
    });
  }

  function renderFilePicker() {
    const fragment = document.createDocumentFragment();
    const currentName = activeFileName();
    filePickerCurrent.textContent = currentName ? `現在: ${currentName}` : '';
    filePickerOpenButton.textContent = currentName ? `ファイル: ${currentName}` : 'ファイル切替';

    for (const [folder, entries] of groupedFiles()) {
      const group = document.createElement('section');
      group.className = 'file-group';

      const heading = document.createElement('h3');
      heading.className = 'file-group-title';
      heading.textContent = folder;
      group.append(heading);

      const list = document.createElement('div');
      list.className = 'file-group-list';

      entries.sort((a, b) => {
        return a.parts.name.localeCompare(b.parts.name, 'ja') || a.file.id - b.file.id;
      });

      for (const entry of entries) {
        const button = document.createElement('button');
        const isActive = entry.file.id === state.activeFileId;
        button.type = 'button';
        button.className = 'file-picker-item';
        button.dataset.fileId = String(entry.file.id);
        button.setAttribute('aria-current', isActive ? 'true' : 'false');
        button.disabled = state.files.length === 0;

        const name = document.createElement('span');
        name.className = 'file-picker-name';
        name.textContent = entry.parts.name;

        const meta = document.createElement('span');
        meta.className = 'file-picker-meta';
        meta.textContent = isActive ? '選択中' : entry.file.updated_at || '';

        button.append(name, meta);
        list.append(button);
      }

      group.append(list);
      fragment.append(group);
    }

    if (state.files.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'file-picker-empty';
      empty.textContent = 'ファイルがありません。';
      fragment.append(empty);
    }

    filePickerList.replaceChildren(fragment);
  }

  function hasFileId(files, id) {
    return files.some((file) => file.id === id);
  }

  function applyTreeResponse(data, keepActive) {
    state.files = data.files || [];
    state.activeFileId = data.active_file_id || null;
    rebuildState(data.nodes || []);
    state.activeNodeId = keepActive && state.activeNodeId !== null && state.nodes.has(state.activeNodeId)
      ? state.activeNodeId
      : (state.rootIds[0] || null);
    renderFilePicker();
    renderTree();
    rememberActiveFileId(state.activeFileId);
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

    const menuId = `node-menu-${id}`;
    const menuButton = makeIconButton('…', 'メニュー', (event) => {
      event.stopPropagation();
      toggleNodeMenu(id);
    });
    menuButton.classList.add('node-menu-button');
    menuButton.setAttribute('aria-haspopup', 'menu');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-controls', menuId);

    const menu = document.createElement('div');
    menu.id = menuId;
    menu.className = 'node-menu-panel';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;

    menu.append(
      makeNodeMenuButton('←', '親に戻す', () => outdentNode(id)),
      makeNodeMenuButton('→', '子にする', () => indentNode(id)),
      makeNodeMenuButton('↑', '上に移動', () => moveUpNode(id)),
      makeNodeMenuButton('↓', '下に移動', () => moveDownNode(id))
    );

    actions.append(menuButton, menu);
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

  function makeNodeMenuButton(label, title, handler) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'node-menu-item';
    button.textContent = label;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.setAttribute('role', 'menuitem');
    button.addEventListener('click', async (event) => {
      event.stopPropagation();
      closeNodeMenus();
      await handler();
    });
    return button;
  }

  function closeNodeMenus(exceptId) {
    for (const panel of outlineEl.querySelectorAll('.node-menu-panel')) {
      const row = panel.closest('.node');
      const id = row ? Number(row.dataset.id) : null;
      if (exceptId !== undefined && id === exceptId) {
        continue;
      }
      panel.hidden = true;
      row?.querySelector('.node-menu-button')?.setAttribute('aria-expanded', 'false');
    }
  }

  function toggleNodeMenu(id) {
    const row = outlineEl.querySelector(`.node[data-id="${id}"]`);
    const panel = row?.querySelector('.node-menu-panel');
    const button = row?.querySelector('.node-menu-button');
    if (!row || !panel || !button) {
      return;
    }

    const shouldOpen = panel.hidden;
    closeNodeMenus(id);
    panel.hidden = !shouldOpen;
    button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    state.activeNodeId = id;
    renderActiveOnly();

    if (shouldOpen) {
      panel.querySelector('.node-menu-item')?.focus();
    }
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
      let data = await apiGet('api/tree.php');
      const storedFileId = readStoredActiveFileId();

      if (storedFileId !== null && hasFileId(data.files || [], storedFileId)) {
        if (storedFileId !== data.active_file_id) {
          data = await apiPost('api/file_switch.php', { id: storedFileId });
        }
      } else if (storedFileId !== null) {
        forgetActiveFileId();
      }

      applyTreeResponse(data, false);
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('読み込み失敗', 'error');
      console.error(error);
    }
  }

  async function createNode(parentId, position, text) {
    const data = await apiPost('api/node_create.php', activeFilePayload({
      parent_id: parentId,
      position,
      text: text || ''
    }));
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

  async function moveUpNode(id) {
    const node = state.nodes.get(id);
    if (!node) {
      return;
    }

    const siblings = getChildren(node.parent_id);
    const index = siblings.indexOf(id);
    if (index > 0) {
      // 同じ階層の上の兄弟と入れ替える
      await moveNode(id, node.parent_id, index - 1);
    } else if (node.parent_id !== null) {
      // 親の上の階層（親の直前）に移動する
      const parent = state.nodes.get(node.parent_id);
      if (parent) {
        const parentSiblings = getChildren(parent.parent_id);
        const parentIndex = parentSiblings.indexOf(parent.id);
        await moveNode(id, parent.parent_id, parentIndex);
      }
    }
  }

  async function moveDownNode(id) {
    const node = state.nodes.get(id);
    if (!node) {
      return;
    }

    const siblings = getChildren(node.parent_id);
    const index = siblings.indexOf(id);
    if (index >= 0 && index < siblings.length - 1) {
      // 同じ階層の下の兄弟と入れ替える
      await moveNode(id, node.parent_id, index + 1);
    } else if (node.parent_id !== null) {
      // 親の上の階層（親の直後）に移動する
      const parent = state.nodes.get(node.parent_id);
      if (parent) {
        const parentSiblings = getChildren(parent.parent_id);
        const parentIndex = parentSiblings.indexOf(parent.id);
        await moveNode(id, parent.parent_id, parentIndex + 1);
      }
    }
  }

  async function moveNode(id, parentId, position) {
    try {
      setStatus('保存中', 'saving');
      const data = await apiPost('api/node_move.php', activeFilePayload({ id, parent_id: parentId, position }));
      rebuildState(data.nodes);
      state.activeNodeId = id;
      renderTree();
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('保存失敗', 'error');
      console.error(error);
    }
  }

  function saveTimerKey(fileId, id) {
    return `${fileId}:${id}`;
  }

  async function saveNodeTextNow(id, fileId, text) {
    await apiPost('api/node_update.php', { file_id: fileId, id, text });
  }

  function scheduleSaveText(id, text) {
    const node = state.nodes.get(id);
    const fileId = state.activeFileId;
    const timerKey = saveTimerKey(fileId, id);
    if (node) {
      node.text = text;
    }

    if (state.savingTimers.has(timerKey)) {
      clearTimeout(state.savingTimers.get(timerKey));
    }

    setStatus('未保存', 'dirty');
    state.savingTimers.set(timerKey, setTimeout(async () => {
      try {
        setStatus('保存中', 'saving');
        await saveNodeTextNow(id, fileId, text);
        setStatus('保存済み', 'saved');
      } catch (error) {
        setStatus('保存失敗', 'error');
        console.error(error);
      } finally {
        state.savingTimers.delete(timerKey);
      }
    }, 500));
  }

  async function flushNodeText(id, text) {
    const fileId = state.activeFileId;
    const timerKey = saveTimerKey(fileId, id);
    const node = state.nodes.get(id);
    if (node) {
      node.text = text;
    }

    if (state.savingTimers.has(timerKey)) {
      clearTimeout(state.savingTimers.get(timerKey));
      state.savingTimers.delete(timerKey);
    }

    setStatus('保存中', 'saving');
    try {
      await saveNodeTextNow(id, fileId, text);
    } catch (error) {
      setStatus('保存失敗', 'error');
      throw error;
    }
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
      await apiPost('api/node_update.php', activeFilePayload({ id, collapsed }));
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
      const data = await apiPost('api/node_delete.php', activeFilePayload({ id }));
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
    if (isOpen) {
      closeNodeMenus();
    }
  }

  function toggleMenu() {
    setMenuOpen(menuPanel.hidden);
  }

  function setFilePickerOpen(isOpen) {
    filePickerPanel.hidden = !isOpen;
    document.body.classList.toggle('has-modal', isOpen);

    if (isOpen) {
      state.filePickerLastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      setMenuOpen(false);
      closeNodeMenus();
      renderFilePicker();
      const activeButton = filePickerList.querySelector(`.file-picker-item[data-file-id="${state.activeFileId}"]`);
      const firstButton = filePickerList.querySelector('.file-picker-item');
      (activeButton || firstButton || filePickerCloseButton).focus();
      return;
    }

    if (state.filePickerLastFocus && state.filePickerLastFocus.offsetParent !== null) {
      state.filePickerLastFocus.focus();
    } else {
      menuToggleButton.focus();
    }
    state.filePickerLastFocus = null;
  }

  function openFilePicker() {
    setFilePickerOpen(true);
  }

  function closeFilePicker() {
    setFilePickerOpen(false);
  }

  async function switchFile(id) {
    if (id === state.activeFileId) {
      setMenuOpen(false);
      return;
    }

    try {
      setStatus('読み込み中', 'saving');
      const data = await apiPost('api/file_switch.php', { id });
      applyTreeResponse(data, false);
      setMenuOpen(false);
      setStatus('保存済み', 'saved');
    } catch (error) {
      renderFilePicker();
      setStatus('切り替え失敗', 'error');
      console.error(error);
    }
  }

  async function createFile() {
    const name = window.prompt('ファイル名', '無題');
    if (name === null) {
      return;
    }

    try {
      setStatus('作成中', 'saving');
      const data = await apiPost('api/file_create.php', { name });
      applyTreeResponse(data, false);
      setMenuOpen(false);
      setFilePickerOpen(true);
      setStatus('保存済み', 'saved');
    } catch (error) {
      setStatus('作成失敗', 'error');
      console.error(error);
    }
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

    if (event.isComposing || event.keyCode === 229) {
      return;
    }

    const id = Number(text.dataset.id);
    state.activeNodeId = id;

    if (event.key === 'Enter') {
      event.preventDefault();
      await flushNodeText(id, text.textContent || '');
      await createNodeAfter(id);
    } else if (event.key === 'Tab') {
      event.preventDefault();
      await flushNodeText(id, text.textContent || '');
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
      await flushNodeText(id, '');
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
    setMenuOpen(false);
  });

  filePickerOpenButton.addEventListener('click', openFilePicker);

  fileCreateButton.addEventListener('click', createFile);

  filePickerCloseButton.addEventListener('click', closeFilePicker);

  filePickerPanel.addEventListener('click', async (event) => {
    if (event.target === filePickerPanel) {
      closeFilePicker();
      return;
    }

    const button = event.target.closest('.file-picker-item');
    if (!button) {
      return;
    }
    await switchFile(Number(button.dataset.fileId));
    closeFilePicker();
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
    if (!event.target.closest('.node-actions')) {
      closeNodeMenus();
    }

    if (menuPanel.hidden || event.target.closest('.topbar-menu')) {
      return;
    }
    setMenuOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }

    const openNodeMenuButton = outlineEl.querySelector('.node-menu-panel:not([hidden])')?.closest('.node')?.querySelector('.node-menu-button');
    if (openNodeMenuButton) {
      closeNodeMenus();
      openNodeMenuButton.focus();
      return;
    }

    if (!menuPanel.hidden) {
      setMenuOpen(false);
      menuToggleButton.focus();
      return;
    }

    if (!filePickerPanel.hidden) {
      closeFilePicker();
    }
  });

  loadTree();
})();
