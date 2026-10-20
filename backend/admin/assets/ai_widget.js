/**
 * Track360 AI Analyst Floating Widget
 * Enterprise Telematics Intelligence
 */
(function() {
  if (document.getElementById('track360-ai-trigger')) return;

  var WELCOME_HTML = `
    <div class="ai-msg bot">
      <strong>👋 Ayubowan! I am your Track360 AI Analyst.</strong><br>
      You can ask me <em>any</em> ad-hoc question or custom telematics report for <strong>Today, Yesterday, This Week, or This Month</strong> in <strong>Singlish, Sinhala, or English</strong>.<br><br>
      <em>Examples:</em><br>
      • <em>"Me mase wadiyenma distance giya top 5 officers la kawda?"</em><br>
      • <em>"Ada gaththa field photos tika pennanna"</em><br>
      • <em>"Nimesh visit karapu plots saha photos monawada?"</em><br>
      • <em>"Section 1 saha Section 2 athara me mase performance compare karanna"</em>
    </div>
  `;

  // 1. Inject Floating Button, Modal HTML, and Photo Lightbox
  var container = document.createElement('div');
  container.innerHTML = `
    <div id="track360-ai-trigger" title="Ask Track360 AI Analyst for any report or telematics summary">
      <span class="ai-pulse-dot"></span>
      <span>✨ AI Analyst</span>
    </div>

    <div id="track360-ai-modal">
      <div class="ai-modal-header">
        <div class="title-area">
          <div class="avatar">✨</div>
          <div>
            <h4>Track360 AI Analyst</h4>
            <div class="status" id="ai-status-indicator">
              <span id="ai-status-dot" style="color:#22c55e">●</span>
              <span id="ai-status-text">Online</span>
            </div>
          </div>
        </div>
        <div class="ctrl-btns">
          <button class="ctrl-btn" id="ai-clear-btn" title="Clear Chat History">🗑️</button>
          <button class="ctrl-btn" id="ai-expand-btn" title="Expand / Contract">⤢</button>
          <button class="ctrl-btn" id="ai-close-btn" title="Close">✕</button>
        </div>
      </div>

      <div class="ai-chips-bar">
        <button class="ai-chip" data-q="Me mase mulu distance eka saha officers lage monthly performance analysis 1k table ekak widiyata denna">📅 This Month's Performance</button>
        <button class="ai-chip" data-q="Ada gaththa field photos tika table ekak widiyata pennanna">📸 Today's Field Photos</button>
        <button class="ai-chip" data-q="Ada dawase field duty summary 1k table ekak widiyata denna">🌾 Today's Duty Summary</button>
        <button class="ai-chip" data-q="Ada dawase wadiyenma distance giya top 5 officers la kawda?">🚗 Top Distance Today</button>
        <button class="ai-chip" data-q="Me mase visit karapu plots saha field photos summary 1k denna">🌾 Monthly Plot Visits</button>
        <button class="ai-chip" data-q="Me mase sections athara distance saha field visits compare karanna">📊 Compare Sections (Month)</button>
      </div>

      <div class="ai-messages-area" id="ai-messages">
        ${WELCOME_HTML}
      </div>

      <form class="ai-input-bar" id="ai-form">
        <input type="text" id="ai-input" placeholder="Ask any question or request a report in Singlish..." autocomplete="off">
        <button type="submit" id="ai-send-btn">Ask AI</button>
      </form>
    </div>

    <!-- Image Lightbox Overlay -->
    <div id="ai-lightbox-overlay">
      <button id="ai-lightbox-close" title="Close">✕</button>
      <img id="ai-lightbox-img" src="" alt="Full Preview">
      <div id="ai-lightbox-bar">
        <span id="ai-lightbox-title">Field Inspection Photo</span>
        <a id="ai-lightbox-dl" class="ai-btn-sm" href="#" target="_blank" download style="text-decoration:none">📥 View / Download</a>
      </div>
    </div>
  `;
  document.body.appendChild(container);

  var trigger = document.getElementById('track360-ai-trigger');
  var modal = document.getElementById('track360-ai-modal');
  var closeBtn = document.getElementById('ai-close-btn');
  var expandBtn = document.getElementById('ai-expand-btn');
  var clearBtn = document.getElementById('ai-clear-btn');
  var form = document.getElementById('ai-form');
  var input = document.getElementById('ai-input');
  var messages = document.getElementById('ai-messages');
  var sendBtn = document.getElementById('ai-send-btn');

  // Lightbox elements
  var lbOverlay = document.getElementById('ai-lightbox-overlay');
  var lbImg = document.getElementById('ai-lightbox-img');
  var lbTitle = document.getElementById('ai-lightbox-title');
  var lbDl = document.getElementById('ai-lightbox-dl');
  var lbClose = document.getElementById('ai-lightbox-close');

  // Online / Offline Status Checker
  function updateOnlineStatus() {
    var dot = document.getElementById('ai-status-dot');
    var txt = document.getElementById('ai-status-text');
    if (!dot || !txt) return;

    if (!navigator.onLine) {
      dot.style.color = '#ef4444';
      txt.textContent = 'Offline';
      txt.style.color = '#ef4444';
      return;
    }

    // Show initial Online state immediately based on browser connectivity
    dot.style.color = '#22c55e';
    txt.textContent = 'Online';
    txt.style.color = '#22c55e';

    // Verify server endpoint reachability
    fetch('ai_assistant.php?action=status_check', { method: 'GET', cache: 'no-store' })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.status === 'ok') {
          dot.style.color = '#22c55e';
          txt.textContent = 'Online';
          txt.style.color = '#22c55e';
        } else {
          dot.style.color = '#f59e0b';
          txt.textContent = 'Attention';
          txt.style.color = '#f59e0b';
        }
      })
      .catch(function() {
        if (!navigator.onLine) {
          dot.style.color = '#ef4444';
          txt.textContent = 'Offline';
          txt.style.color = '#ef4444';
        }
      });
  }
  window.addEventListener('online', updateOnlineStatus);
  window.addEventListener('offline', updateOnlineStatus);
  updateOnlineStatus();

  // Chat History Management (localStorage)
  function saveChatHistory() {
    try {
      localStorage.setItem('track360_ai_chat_v2', messages.innerHTML);
    } catch(e) {}
  }

  function loadChatHistory() {
    try {
      var saved = localStorage.getItem('track360_ai_chat_v2');
      if (saved && saved.trim() !== '') {
        messages.innerHTML = saved;
        messages.scrollTop = messages.scrollHeight;
      }
    } catch(e) {}
  }
  loadChatHistory();

  // Clear Chat History
  clearBtn.addEventListener('click', function() {
    if (confirm('Clear chat history and start a fresh session?')) {
      localStorage.removeItem('track360_ai_chat_v2');
      messages.innerHTML = WELCOME_HTML;
      input.value = '';
    }
  });

  // Toggle Modal
  trigger.addEventListener('click', function() {
    var isOpen = modal.style.display === 'flex';
    modal.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) {
      input.focus();
      messages.scrollTop = messages.scrollHeight;
      updateOnlineStatus();
    }
  });

  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });

  expandBtn.addEventListener('click', function() {
    modal.classList.toggle('expanded');
    expandBtn.textContent = modal.classList.contains('expanded') ? '⤡' : '⤢';
  });

  // Lightbox Close Handlers
  function closeLightbox() {
    lbOverlay.classList.remove('active');
    lbImg.src = '';
  }
  lbClose.addEventListener('click', closeLightbox);
  lbOverlay.addEventListener('click', function(e) {
    if (e.target === lbOverlay) closeLightbox();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && lbOverlay.classList.contains('active')) {
      closeLightbox();
    }
  });

  window.openAILightbox = function(url, title) {
    lbImg.src = url;
    lbTitle.textContent = title || 'Field Inspection Photo';
    lbDl.href = url;
    lbOverlay.classList.add('active');
  };

  // Suggestion Chips
  document.querySelectorAll('.ai-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
      var q = chip.getAttribute('data-q');
      if (q) {
        input.value = q;
        submitQuestion(q);
      }
    });
  });

  // Form Submit
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var q = input.value.trim();
    if (q) {
      submitQuestion(q);
    }
  });

  function submitQuestion(q) {
    appendMessage(q, 'user');
    input.value = '';
    sendBtn.disabled = true;

    // Show Thinking indicator
    var thinkingDiv = document.createElement('div');
    thinkingDiv.className = 'ai-msg bot';
    thinkingDiv.id = 'ai-temp-thinking';
    thinkingDiv.innerHTML = '<div class="ai-thinking"><span></span><span></span><span></span> Analyzing telematics database...</div>';
    messages.appendChild(thinkingDiv);
    messages.scrollTop = messages.scrollHeight;

    var fd = new FormData();
    fd.append('action', 'chat');
    fd.append('prompt', q);

    fetch('ai_assistant.php', {
      method: 'POST',
      body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      var el = document.getElementById('ai-temp-thinking');
      if (el) el.remove();
      sendBtn.disabled = false;

      if (data.status === 'success') {
        appendBotResponse(data.answer, data.data);
      } else {
        appendMessage('⚠️ ' + (data.message || 'Error communicating with AI engine.'), 'bot');
      }
      saveChatHistory();
    })
    .catch(function(err) {
      var el = document.getElementById('ai-temp-thinking');
      if (el) el.remove();
      sendBtn.disabled = false;
      appendMessage('⚠️ Network or server error: ' + err.message, 'bot');
      saveChatHistory();
    });
  }

  function appendMessage(text, role) {
    var div = document.createElement('div');
    div.className = 'ai-msg ' + role;
    div.textContent = text;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    saveChatHistory();
  }

  function appendBotResponse(rawMarkdown, queryData) {
    var div = document.createElement('div');
    div.className = 'ai-msg bot';

    var html = formatMarkdown(rawMarkdown);
    div.innerHTML = html;

    // Check if table exists in generated HTML and add CSV Export button
    var table = div.querySelector('table');
    var actionBox = document.createElement('div');
    actionBox.className = 'ai-table-actions';

    if (table) {
      var exportBtn = document.createElement('button');
      exportBtn.className = 'ai-btn-sm';
      exportBtn.innerHTML = '📥 Export to CSV (Excel)';
      exportBtn.onclick = function() {
        exportTableToCSV(table, 'Track360_Report_' + new Date().toISOString().slice(0,10) + '.csv');
      };
      actionBox.appendChild(exportBtn);
    }

    var copyBtn = document.createElement('button');
    copyBtn.className = 'ai-btn-sm';
    copyBtn.style.background = '#334155';
    copyBtn.innerHTML = '📋 Copy Text';
    copyBtn.onclick = function() {
      navigator.clipboard.writeText(rawMarkdown).then(function() {
        copyBtn.innerHTML = '✅ Copied!';
        setTimeout(function() { copyBtn.innerHTML = '📋 Copy Text'; }, 2000);
      });
    };
    actionBox.appendChild(copyBtn);

    div.appendChild(actionBox);
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    saveChatHistory();
  }

  // Simple Markdown to HTML formatter supporting Bold, Lists, Headers, Tables & Photos
  function formatMarkdown(md) {
    if (!md) return '';

    var lines = md.split('\n');
    var html = [];
    var inTable = false;
    var tableRows = [];

    function flushTable() {
      if (!inTable) return;
      if (tableRows.length > 0) {
        var tHtml = '<div style="overflow-x:auto;margin:8px 0"><table><thead><tr>';
        var headers = tableRows[0];
        headers.forEach(function(h) {
          tHtml += '<th>' + renderCellContent(h.trim()) + '</th>';
        });
        tHtml += '</tr></thead><tbody>';

        for (var r = 1; r < tableRows.length; r++) {
          tHtml += '<tr>';
          tableRows[r].forEach(function(c) {
            tHtml += '<td>' + renderCellContent(c.trim()) + '</td>';
          });
          tHtml += '</tr>';
        }
        tHtml += '</tbody></table></div>';
        html.push(tHtml);
      }
      tableRows = [];
      inTable = false;
    }

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].trim();

      // Check for Markdown table line
      if (line.startsWith('|') && line.endsWith('|')) {
        var cells = line.split('|').slice(1, -1);
        // Skip separator line (e.g. |---|---|)
        if (cells.every(function(c) { return /^[:\-\s]+$/.test(c); })) {
          continue;
        }
        inTable = true;
        tableRows.push(cells);
        continue;
      } else {
        flushTable();
      }

      // Standalone Markdown Image: ![alt](url)
      var imgMatch = line.match(/^!\[(.*?)\]\((.*?)\)$/);
      if (imgMatch) {
        var alt = escapeHtml(imgMatch[1]);
        var url = escapeHtml(imgMatch[2]);
        var cleanUrlJs = imgMatch[2].replace(/'/g, "\\'");
        var cleanAltJs = imgMatch[1].replace(/'/g, "\\'");
        html.push(`
          <div class="ai-photo-card" onclick="openAILightbox('${cleanUrlJs}', '${cleanAltJs}')">
            <img src="${url}" alt="${alt}" class="ai-msg-photo" loading="lazy">
            <div class="ai-photo-caption">📷 ${alt} <span style="font-size:10px;opacity:0.75;float:right">🔍 Zoom</span></div>
          </div>
        `);
        continue;
      }

      if (line.startsWith('### ')) {
        html.push('<h5 style="margin:10px 0 4px;color:#38bdf8">' + inlineFormat(line.slice(4)) + '</h5>');
      } else if (line.startsWith('## ') || line.startsWith('# ')) {
        html.push('<h4 style="margin:12px 0 6px;color:#38bdf8">' + inlineFormat(line.replace(/^#+\s*/, '')) + '</h4>');
      } else if (line.startsWith('- ') || line.startsWith('* ')) {
        html.push('<div style="margin-left:14px;position:relative"><span style="position:absolute;left:-12px;color:#38bdf8">•</span> ' + inlineFormat(line.slice(2)) + '</div>');
      } else if (line === '') {
        html.push('<div style="height:6px"></div>');
      } else {
        html.push('<div>' + inlineFormat(line) + '</div>');
      }
    }
    flushTable();

    return html.join('');
  }

  function renderCellContent(str) {
    // Check if cell contains markdown image
    var imgPattern = /!\[(.*?)\]\((.*?)\)/g;
    if (imgPattern.test(str)) {
      return str.replace(/!\[(.*?)\]\((.*?)\)/g, function(m, alt, url) {
        var cleanUrlJs = url.replace(/'/g, "\\'");
        var cleanAltJs = alt.replace(/'/g, "\\'");
        return `<span class="ai-thumb-wrap" onclick="openAILightbox('${cleanUrlJs}', '${cleanAltJs}')" title="${escapeHtml(alt)}"><img src="${escapeHtml(url)}" class="ai-chat-thumb" loading="lazy" onerror="this.onerror=null;this.parentElement.innerHTML='📷'"/></span>`;
      });
    }
    return inlineFormat(str);
  }

  function inlineFormat(str) {
    // Escape HTML first
    var s = escapeHtml(str);

    // Bold: **text**
    s = s.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

    // Code: `code`
    s = s.replace(/`(.*?)`/g, '<code style="background:#0f172a;padding:1px 5px;border-radius:4px;font-family:monospace;color:#38bdf8">$1</code>');

    // Inline image match if any
    s = s.replace(/!\[(.*?)\]\((.*?)\)/g, function(m, alt, url) {
      var cleanUrlJs = url.replace(/'/g, "\\'");
      var cleanAltJs = alt.replace(/'/g, "\\'");
      return `<span class="ai-thumb-wrap" onclick="openAILightbox('${cleanUrlJs}', '${cleanAltJs}')" title="${alt}"><img src="${url}" class="ai-chat-thumb" loading="lazy"/></span>`;
    });

    return s;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // Export Table to CSV
  function exportTableToCSV(table, filename) {
    var csv = [];
    var rows = table.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
      var row = [], cols = rows[i].querySelectorAll('td, th');
      for (var j = 0; j < cols.length; j++) {
        var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
        data = data.replace(/"/g, '""');
        row.push('"' + data + '"');
      }
      csv.push(row.join(','));
    }
    var csvFile = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(csvFile);
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }
})();
