import { API_BASE } from './api-base.js';
import { ecosystemStrip, isHardFailure } from './ecosystem.js';
document.addEventListener('DOMContentLoaded', () => {
  const uploadArea = document.getElementById('uploadArea');
  const fileInput = document.getElementById('fileInput');
  const analysisPanel = document.getElementById('analysisPanel');
  const imageModal = document.getElementById('imageModal');
  const modalImg = document.getElementById('modalImg');
  const modalClose = document.getElementById('modalClose');
  const panelStatus = document.getElementById('panelStatus');

  let previewSrc = '';

  // The 02 - ANALYSIS bay header carries a one-word state readout.
  function setStatus(word, state) {
    if (!panelStatus) return;
    panelStatus.textContent = word;
    panelStatus.dataset.state = state;
  }

  uploadArea.addEventListener('click', () => fileInput.click());

  uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.classList.add('drag-over');
  });

  uploadArea.addEventListener('dragleave', () => {
    uploadArea.classList.remove('drag-over');
  });

  uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('drag-over');
    const files = e.dataTransfer.files;
    if (files.length > 0) handleFile(files[0]);
  });

  fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) handleFile(e.target.files[0]);
  });

  function handleFile(file) {
    if (!file.type.startsWith('image/')) {
      alert('Please upload an image file');
      return;
    }

    const reader = new FileReader();
    reader.onload = async (e) => {
      previewSrc = e.target.result;
      showAnalyzing();

      try {
        // Only send image and context to backend; backend handles all API calls and signal generation
        const symbolOverride = document.getElementById('symbolOverride')?.value.trim();
        const response = await fetch(`${API_BASE}/chart/analyze`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            image: previewSrc,
            market: document.getElementById('marketSelect')?.value || 'stocks',
            ...(symbolOverride ? { symbol: symbolOverride } : {}),
          })
        });
        if (!response.ok) {
          const failed = new Error('Backend analysis failed');
          failed.status = response.status;
          throw failed;
        }
        const result = await response.json();
        displayResults(result.analysis, {
          isDemo: result.is_demo === true,
          disclaimer: result.disclaimer || null
        });
      } catch (error) {
        showError(error.message, error.status ?? null);
      }
    };
    reader.readAsDataURL(file);
  }

  function showAnalyzing() {
    setStatus('READING\u2026', 'busy');
    analysisPanel.innerHTML = '<div class="spinner"></div><h3>Analyzing</h3><p>Reading the chart and computing the analysis from market data.</p>';
  }

  function showError(message, status) {
    setStatus('FAILED', 'bad');
    analysisPanel.innerHTML = `
      <div class="analysis-icon" style="color:var(--red)">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9.5 16.5H2.5L12 3z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="0.2" fill="currentColor"/></svg>
      </div>
      <h3>Analysis failed</h3>
      <p style="color:var(--red)">${message}</p>
      <button class="primary-btn" onclick="location.reload()">Try again</button>
    `;
    if (isHardFailure(status)) {
      analysisPanel.appendChild(ecosystemStrip());
    }
  }

  function getSignalStyle(signal) {
    const s = signal.toLowerCase();
    if (s.includes('sell')) {
      return { tint: 'var(--red-soft)', edge: 'var(--red)', accent: 'var(--red)' };
    }
    if (s.includes('buy')) {
      return { tint: 'var(--green-soft)', edge: 'var(--green)', accent: 'var(--green)' };
    }
    // hold / neutral / anything unrecognised: amber, the attention colour
    return { tint: 'var(--signal-soft)', edge: 'var(--signal)', accent: 'var(--signal)' };
  }

  function displayResults(analysis, meta = {}) {
    // Regression: the real backend response (both the placeholder and the
    // real chart-analysis path) only ever sends signal/confidence/trend/
    // patterns/supports/resistances/summary -- it never sent entryZone/
    // stopLoss/takeProfits/riskReward/timestamp, which this render function
    // has always required. That made every real upload throw ("Cannot read
    // properties of undefined (reading 'join')") regardless of which
    // backend path served it. Backfill sensible defaults derived from the
    // fields that are actually present, without changing the backend
    // contract.
    analysis = {
      entryZone: analysis.supports?.[0] ?? 'N/A',
      stopLoss: analysis.supports?.[1] ?? analysis.supports?.[0] ?? 'N/A',
      takeProfits: analysis.resistances ?? [],
      riskReward: 'N/A',
      timestamp: new Date().toLocaleString(),
      ...analysis,
    };
    const style = getSignalStyle(analysis.signal);
    setStatus(meta.isDemo ? 'DEMO' : 'COMPLETE', meta.isDemo ? 'warn' : 'good');
    const demoBanner = meta.isDemo
      ? `<div class="demo-banner" role="status">
          <strong>Demo result — not real analysis</strong>
          <span>${meta.disclaimer || 'This output is a fixed placeholder for UI development and is not generated from your chart or live market data. Do not use it to make trading decisions.'}</span>
        </div>`
      : '';
    const signalCard = `
      <div class="signal-card" style="background:${style.tint}">
        <span class="readout-label">Signal</span>
        <div class="signal-value" style="color:${style.accent}">${analysis.signal}</div>
        <div class="progress"><span style="background:${style.accent};width:${analysis.confidence}%"></span></div>
        <div class="conf-line">CONFIDENCE <span style="color:${style.accent}">${analysis.confidence}%</span></div>
      </div>
    `;

    const previewHtml = previewSrc
      ? `<div class="preview-box"><img src="${previewSrc}" class="preview-thumb" id="inlinePreview" alt="Chart preview"></div>`
      : '';

    const signalBlock = previewHtml
      ? `<div class="signal-row">${previewHtml}${signalCard}</div>`
      : `<div style="margin-bottom:14px">${signalCard}</div>`;

    const trendColor = analysis.trend === 'Bullish' ? 'var(--green)'
      : analysis.trend === 'Bearish' ? 'var(--red)' : 'var(--text)';

    analysisPanel.innerHTML = `
      <div class="fade-in">
        ${demoBanner}
        ${signalBlock}

        <div class="analysis-results">
          <div class="result-box">
            <label>Market trend</label>
            <div class="value mono" style="color:${trendColor};font-size:18px">${analysis.trend}</div>
          </div>
          <div class="result-box">
            <label>Trade setup</label>
            <div class="setup-row"><span class="k">Entry</span><span class="v" style="color:var(--text)">${analysis.entryZone}</span></div>
            <div class="setup-row"><span class="k">Stop loss</span><span class="v" style="color:var(--red)">${analysis.stopLoss}</span></div>
            <div class="setup-row"><span class="k">Take profit</span><span class="v" style="color:var(--green)">${analysis.takeProfits.join(' / ')}</span></div>
            <div class="setup-row"><span class="k">Risk/reward</span><span class="v">${analysis.riskReward}</span></div>
          </div>
          <div class="result-box" style="grid-column:1/-1">
            <label>Detected patterns</label>
            <div class="tags">${analysis.patterns.map(p => `<span class="tag">${p}</span>`).join('')}</div>
          </div>
          <div class="result-box">
            <label>Support</label>
            <div class="tags">${analysis.supports.map(v => `<span class="tag green">${v}</span>`).join('')}</div>
          </div>
          <div class="result-box">
            <label>Resistance</label>
            <div class="tags">${analysis.resistances.map(v => `<span class="tag red">${v}</span>`).join('')}</div>
          </div>
          <div class="result-box" style="grid-column:1/-1">
            <label>Summary</label>
            <div class="summary">${analysis.summary}</div>
            <div class="meta">ANALYZED ${analysis.timestamp}</div>
          </div>
        </div>

        <button class="primary-btn" id="analyzeAgain">Analyze another chart</button>
      </div>
    `;

    const resetBtn = document.getElementById('analyzeAgain');
    resetBtn.addEventListener('click', () => {
      setStatus('READY', 'idle');
      analysisPanel.innerHTML = `
        <div class="analysis-icon">
          <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
        </div>
        <h3>No analysis yet</h3>
        <p>Drop a chart in the upload bay and the read appears here.</p>
      `;
      fileInput.value = '';
      previewSrc = '';
    });

    const inlinePreview = document.getElementById('inlinePreview');
    if (inlinePreview) {
      inlinePreview.addEventListener('click', () => openModal(previewSrc));
    }
  }

  function openModal(src) {
    if (!src) return;
    modalImg.src = src;
    imageModal.classList.add('open');
    imageModal.setAttribute('aria-hidden', 'false');
  }

  modalClose.addEventListener('click', closeModal);
  imageModal.addEventListener('click', (e) => {
    if (e.target === imageModal) closeModal();
  });

  function closeModal() {
    imageModal.classList.remove('open');
    imageModal.setAttribute('aria-hidden', 'true');
    modalImg.removeAttribute('src');
  }
});
