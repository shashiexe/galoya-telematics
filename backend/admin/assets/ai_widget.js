/**
 * Track360 AI Analyst Floating Widget
 * Enterprise Telematics Intelligence powered by Google Gemini
 */
(function() {
  if (document.getElementById('track360-ai-trigger')) return;

  // 1. Inject Floating Button & Modal HTML
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
            <div class="status"><span>●</span> Online · Gemini 3.8 Flash</div>
          </div>
        </div>
        <div class="ctrl-btns">
          <button class="ctrl-btn" id="ai-expand-btn" title="Expand / Contract">⤢</button>
          <button class="ctrl-btn" id="ai-close-btn" title="Close">✕</button>
        </div>
      </div>

      <div class="ai-chips-bar">
        <button class="ai-chip" data-q="Ada dawase field duty summary 1k table ekak widiyata denna">🌾 Today's Duty Summary</button>
        <button class="ai-chip" data-q="Ada dawase wadiyenma distance giya top 5 officers la kawda?">🚗 Top Distance Today</button>
        <button class="ai-chip" data-q="Anthimata gaththa field visit photos saha ewaye plots monawada?">📸 Recent Plot Photos</button>
        <button class="ai-chip" data-q="Me sathiye trigger una SOS alerts monawada? status kohomada?">⚠️ SOS Alerts History</button>
        <button class="ai-chip" data-q="Section 1 saha Section 2 athara adha dawase active officers la compare karanna">📊 Compare Sections</button>
      </div>

      <div class="ai-messages-area" id="ai-messages">
        <div class="ai-msg bot">
          <strong>👋 Ayubowan! I am your Track360 AI Analyst.</strong><br>
          You can ask me <em>any</em> ad-hoc question or custom telematics report in <strong>Singlish, Sinhala, or English</strong>.<br><br>
          <em>Examples:</em><br>
          • <em>"Lasantha ada dawase visit karapu plots monawada?"</em><br>
          • <em>"Section 1 eke paya 6kata wada wada karapu aya kawda?"</em><br>
          • <em>"Giya sathiye wadiyenma battery drop una phones monawada?"</em>
        </div>
      </div>

      <form class="ai-input-bar" id="ai-form">
        <input type="text" id="ai-input" placeholder="Ask any question or request a report in Singlish..." autocomplete="off">
        <button type="submit" id="ai-send-btn">Ask AI</button>
      </form>
    </div>
  `;
  document.body.appendChild(container);

  var trigger = document.getElementById('track360-ai-trigger');
  var modal = document.getElementById('track360-ai-modal');
  var closeBtn = document.getElementById('ai-close-btn');
  var expandBtn = document.getElementById('ai-expand-btn');
  var form = document.getElementById('ai-form');
  var input = document.getElementById('ai-input');
  var messages = document.getElementById('ai-messages');
  var sendBtn = document.getElementById('ai-send-btn');

  // Toggle Modal
  trigger.addEventListener('click', function() {
    var isOpen = modal.style.display === 'flex';
    modal.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) {
      input.focus();
      messages.scrollTop = messages.scrollHeight;
    }
  });

  closeBtn.addEventListener('click', function() {
    modal.style.display = 'none';
  });

  expandBtn.addEventListener('click', function() {
    modal.classList.toggle('expanded');
    expandBtn.textContent = modal.classList.contains('expanded') ? '⤡' : '⤢';
  });

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
        appendBotResponse(data.answer, data.sql, data.data);
      } else {
        appendMessage('⚠️ ' + (data.message || 'Error communicating with AI engine.'), 'bot');
      }
    })
    .catch(function(err) {
      var el = document.getElementById('ai-temp-thinking');
      if (el) el.remove();
      sendBtn.disabled = false;
      appendMessage('⚠️ Network or server error: ' + err.message, 'bot');
    });
  }

  function appendMessage(text, role) {
    var div = document.createElement('div');
    div.className = 'ai-msg ' + role;
    div.textContent = text;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function appendBotResponse(rawMarkdown, sql, queryData) {
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
  }

  // Simple Markdown to HTML formatter supporting Bold, Lists, Headers, and Tables
  function formatMarkdown(md) {
    if (!md) return '';

    var lines = md.split('\n');
    var html = [];
    var inTable = false;
    var tableRows = [];

    function flushTable() {
      if (!inTable) return;
      if (tableRows.length > 0) {
        var tHtml = '<table><thead><tr>';
        var headers = tableRows[0];
        headers.forEach(function(h) {
          tHtml += '<th>' + escapeHtml(h.trim()) + '</th>';
        });
        tHtml += '</tr></thead><tbody>';

        for (var r = 1; r < tableRows.length; r++) {
          tHtml += '<tr>';
          tableRows[r].forEach(function(c) {
            tHtml += '<td>' + escapeHtml(c.trim()) + '</td>';
          });
          tHtml += '</tr>';
        }
        tHtml += '</tbody></table>';
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

  function inlineFormat(str) {
    str = escapeHtml(str);
    // Bold: **text**
    str = str.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Code: `code`
    str = str.replace(/`(.*?)`/g, '<code style="background:#0f172a;padding:1px 5px;border-radius:4px;font-family:monospace;color:#38bdf8">$1</code>');
    return str;
  }

  function escapeHtml(str) {
    return str
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
