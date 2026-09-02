import { API_BASE } from './api-base.js';
import { ecosystemStrip, isHardFailure } from './ecosystem.js';
document.addEventListener('DOMContentLoaded', () => {
  const uploadArea = document.getElementById('uploadArea');
  const fileInput = document.getElementById('fileInput');
  const analysisPanel = document.getElementById('analysisPanel');
  const imageModal = document.getElementById('imageModal');
  const modalImg = document.getElementById('modalImg');
  const modalClose = document.getElementById('modalClose');

  let previewSrc = '';

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
    analysisPanel.innerHTML = '<div class="spinner"></div><h3>Analyzing...</h3><p>Reading structure and computing the analysis</p>';
  }

  function showError(message, status) {
    analysisPanel.innerHTML = `
      <div class="analysis-icon" style="color:var(--red)">❌</div>
      <h3>Analysis Failed</h3>
      <p style="color:var(--red)">${message}</p>
      <button class="primary-btn" onclick="location.reload()">Try Again</button>
    `;
    if (isHardFailure(status)) {
      analysisPanel.appendChild(ecosystemStrip());
    }
  }

  function getSignalStyle(signal) {
    const s = signal.toLowerCase();
    if (s.includes('strong buy')) {
      return {
        bg: 'linear-gradient(135deg, rgba(34,211,238,.35), rgba(34,211,238,.1))',
        border: 'rgba(34,211,238,.45)',
        accent: 'var(--accent)'
      };
    }
    if (s.includes('buy')) {
      return {
        bg: 'linear-gradient(135deg, rgba(34,197,94,.35), rgba(34,197,94,.1))',
        border: 'rgba(34,197,94,.4)',
        accent: 'var(--green)'
      };
    }
    if (s.includes('strong sell') || s.includes('sell')) {
      return {
        bg: 'linear-gradient(135deg, rgba(239,68,68,.35), rgba(239,68,68,.1))',
        border: 'rgba(239,68,68,.45)',
        accent: 'var(--red)'
      };
    }
    if (s.includes('hold') || s.includes('neutral')) {
      return {
        bg: 'linear-gradient(135deg, rgba(250,204,21,.35), rgba(250,204,21,.1))',
        border: 'rgba(250,204,21,.45)',
        accent: '#fbbf24'
      };
    }
    return {
      bg: 'linear-gradient(135deg, rgba(34,211,238,.35), rgba(34,211,238,.1))',
      border: 'rgba(34,211,238,.45)',
      accent: 'var(--accent)'
    };
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
    const demoBanner = meta.isDemo
      ? `<div class="demo-banner" role="status">
          <strong>Demo result — not real analysis.</strong>
          <span>${meta.disclaimer || 'This output is a fixed placeholder for UI development and is not generated from your chart or live market data. Do not use it to make trading decisions.'}</span>
        </div>`
      : '';
    const signalCard = `
      <div class="signal-card" style="background:${style.bg};border:1px solid ${style.border};display:flex;flex-direction:column;justify-content:center;height:100%">
        <div class="signal-value" style="color:${style.accent};margin-top:0">${analysis.signal}</div>
        <div class="progress" style="margin-top:16px"><span id="progressFill" style="background:${style.accent}"></span></div>
        <p style="margin-top:6px;color:${style.accent}">${analysis.confidence}%</p>
      </div>
    `;

    const previewHtml = previewSrc
      ? `<div class="preview-box" style="display:flex;align-items:center;justify-content:center;min-height:100%"><img src="${previewSrc}" class="preview-thumb" id="inlinePreview" alt="Chart preview"></div>`
      : '';

    const signalBlock = previewHtml
      ? `<div class="signal-row" style="align-items:stretch">${previewHtml}${signalCard}</div>`
      : signalCard;

    analysisPanel.innerHTML = `
      <div class="fade-in">
        ${demoBanner}
        ${signalBlock}

        <div class="analysis-results stagger-1" style="margin-top:16px">
          <div class="result-box" style="background:${analysis.trend === 'Bullish' ? 'var(--greenSoft)' : analysis.trend === 'Bearish' ? 'var(--redSoft)' : 'rgba(148,163,184,.08)'};border-color:${analysis.trend === 'Bullish' ? 'rgba(34,197,94,.3)' : analysis.trend === 'Bearish' ? 'rgba(239,68,68,.3)' : 'var(--border)'};padding:20px">
            <label style="font-size:13px;text-transform:uppercase;letter-spacing:0.8px;color:var(--muted);margin-bottom:10px">📊 Market Trend</label>
            <div class="value" style="color:${analysis.trend === 'Bullish' ? 'var(--green)' : analysis.trend === 'Bearish' ? 'var(--red)' : 'var(--text)'};font-size:22px;font-weight:800">${analysis.trend}</div>
          </div>
          <div class="result-box" style="padding:20px">
            <label style="font-size:13px;text-transform:uppercase;letter-spacing:0.8px;color:var(--muted);margin-bottom:12px">⚡ Trade Setup</label>
            <div class="value" style="font-size:15px;line-height:1.8">
              <div style="margin-bottom:6px"><span style="color:var(--muted);font-weight:600">Entry:</span> <span style="color:var(--accent)">${analysis.entryZone}</span></div>
              <div style="margin-bottom:6px"><span style="color:var(--muted);font-weight:600">Stop Loss:</span> <span style="color:var(--red)">${analysis.stopLoss}</span></div>
              <div style="margin-bottom:6px"><span style="color:var(--muted);font-weight:600">Take Profit:</span> <span style="color:var(--green)">${analysis.takeProfits.join(' · ')}</span></div>
              <div><span style="color:var(--muted);font-weight:600">Risk/Reward:</span> <span style="color:#8b5cf6;font-weight:700">${analysis.riskReward}</span></div>
            </div>
          </div>
        </div>

        <div class="result-box stagger-2" style="margin-top:12px">
          <label>Detected Patterns</label>
          <div class="tags">${analysis.patterns.map(p => `<span class="tag">${p}</span>`).join('')}</div>
        </div>

        <div class="analysis-results stagger-3" style="margin-top:12px">
          <div class="result-box">
            <label>Support Levels</label>
            <div class="tags">${analysis.supports.map(s => `<span class="tag green">${s}</span>`).join('')}</div>
          </div>
          <div class="result-box">
            <label>Resistance Levels</label>
            <div class="tags">${analysis.resistances.map(r => `<span class="tag red">${r}</span>`).join('')}</div>
          </div>
        </div>

        <div class="result-box stagger-4" style="margin-top:12px">
          <label>Analysis Summary</label>
          <div class="summary">${analysis.summary}</div>
          <div class="meta">Analyzed on ${analysis.timestamp}</div>
        </div>

        <button class="primary-btn stagger-5" id="analyzeAgain">Analyze Another Chart</button>
      </div>
    `;

    const fill = document.getElementById('progressFill');
    requestAnimationFrame(() => {
      fill.style.width = `${analysis.confidence}%`;
    });

    const resetBtn = document.getElementById('analyzeAgain');
    resetBtn.addEventListener('click', () => {
      analysisPanel.innerHTML = `
        <div class="analysis-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
        </div>
        <h3>No Analysis Yet</h3>
        <p>Upload a trading chart image to get AI‑powered signals and detailed technical analysis.</p>
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
    modalImg.src = '';
  }
});
