import './app-header.js';
import { API_BASE } from './api-base.js';
import { ecosystemStrip, isHardFailure } from './ecosystem.js';

document.addEventListener('DOMContentLoaded', () => {
  const uploadArea = document.getElementById('uploadArea');
  const fileInput = document.getElementById('fileInput');
  const analysisPanel = document.getElementById('analysisPanel');
  const stageEmpty = document.getElementById('stageEmpty');
  const stageImage = document.getElementById('stageImage');
  const stageImg = document.getElementById('stageImg');
  const imageModal = document.getElementById('imageModal');
  const modalImg = document.getElementById('modalImg');
  const modalClose = document.getElementById('modalClose');
  const panelStatus = document.getElementById('panelStatus');

  // 1x1 transparent placeholder — an <img> must always hold a valid src.
  const BLANK = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

  let previewSrc = '';

  // The SIGNAL READOUT header carries a one-word state readout.
  function setStatus(word, state) {
    if (!panelStatus) return;
    panelStatus.textContent = word;
    panelStatus.dataset.state = state;
  }

  // Instrument at rest — mirrors the markup shipped in index.html so the
  // "Analyze another chart" reset restores the exact same idle state.
  const IDLE_HTML = `
    <div class="idle">
      <span class="ro-label">Signal</span>
      <div class="ro-signal">&ndash;&ndash;&ndash;&ndash;</div>
      <div class="segbar"><b></b></div>
      <div class="ro-conf">CONFIDENCE &ndash;&ndash;%</div>
      <div class="ro-rows">
        <div class="ro-row"><span>Symbol</span><b>&ndash;&ndash;</b></div>
        <div class="ro-row"><span>Trend</span><b>&ndash;&ndash;</b></div>
        <div class="ro-row"><span>Entry</span><b>&ndash;&ndash;</b></div>
        <div class="ro-row"><span>Stop</span><b>&ndash;&ndash;</b></div>
        <div class="ro-row"><span>Targets</span><b>&ndash;&ndash;</b></div>
      </div>
      <p class="ro-note">The readout is armed. Drop a chart on the stage and the values light up.</p>
    </div>
  `;

  uploadArea.addEventListener('click', () => fileInput.click());
  uploadArea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput.click();
    }
  });

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
      showError('That file is not an image — drop a PNG or JPG chart screenshot.', 400);
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
          disclaimer: result.disclaimer || null,
          symbol: result.symbol_detected || null,
        });
      } catch (error) {
        showError(error.message, error.status ?? null);
      }
    };
    reader.readAsDataURL(file);
  }

  function showChartOnStage() {
    stageImg.src = previewSrc;
    stageEmpty.hidden = true;
    stageImage.hidden = false;
  }

  function resetStage() {
    uploadArea.classList.remove('scanning');
    stageImage.hidden = true;
    stageEmpty.hidden = false;
    stageImg.src = BLANK;
  }

  function showAnalyzing() {
    showChartOnStage();
    uploadArea.classList.add('scanning');
    setStatus('READING…', 'busy');
    analysisPanel.innerHTML = `
      <div class="idle">
        <div class="spinner"></div>
        <span class="ro-label">Reading the chart</span>
        <p class="ro-note">Identifying the symbol, pulling the market data behind it, computing the structural read.</p>
      </div>
    `;
  }

  function showError(message, status) {
    uploadArea.classList.remove('scanning');
    setStatus('FAILED', 'bad');
    analysisPanel.innerHTML = `
      <div class="fade-in">
        <span class="ro-label">Signal</span>
        <div class="ro-signal tone-red">FAULT</div>
        <p class="err-text">${message}</p>
        <button class="primary-btn" id="tryAgain">Try again</button>
      </div>
    `;
    document.getElementById('tryAgain').addEventListener('click', () => location.reload());
    if (isHardFailure(status)) {
      analysisPanel.appendChild(ecosystemStrip());
    }
  }

  function accentFor(signal) {
    const s = signal.toLowerCase();
    if (s.includes('sell')) return 'red';
    if (s.includes('buy')) return 'green';
    // hold / neutral / anything unrecognised: amber, the attention colour
    return 'amber';
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
    uploadArea.classList.remove('scanning');
    setStatus(meta.isDemo ? 'DEMO' : 'COMPLETE', meta.isDemo ? 'warn' : 'good');
    const accent = accentFor(analysis.signal);
    const trendTone = analysis.trend === 'Bullish' ? 'green'
      : analysis.trend === 'Bearish' ? 'red' : 'text';
    const demoBanner = meta.isDemo
      ? `<div class="demo-banner" role="status">
          <strong>Demo result — not real analysis</strong>
          <span>${meta.disclaimer || 'This output is a fixed placeholder for UI development and is not generated from your chart or live market data. Do not use it to make trading decisions.'}</span>
        </div>`
      : '';

    analysisPanel.innerHTML = `
      <div class="fade-in">
        ${demoBanner}
        <span class="ro-label">Signal</span>
        <div class="ro-signal tone-${accent}">${analysis.signal}</div>
        <div class="segbar tone-${accent}"><b></b></div>
        <div class="ro-conf">CONFIDENCE <span class="tone-${accent}">${analysis.confidence}%</span></div>

        <div class="ro-rows">
          ${meta.symbol ? `<div class="ro-row"><span>Symbol</span><b>${meta.symbol}</b></div>` : ''}
          <div class="ro-row"><span>Trend</span><b class="tone-${trendTone}">${analysis.trend}</b></div>
          <div class="ro-row"><span>Entry</span><b>${analysis.entryZone}</b></div>
          <div class="ro-row"><span>Stop</span><b class="tone-red">${analysis.stopLoss}</b></div>
          <div class="ro-row"><span>Targets</span><b class="tone-green">${analysis.takeProfits.join(' / ')}</b></div>
          <div class="ro-row"><span>Risk/reward</span><b>${analysis.riskReward}</b></div>
        </div>

        <div class="ro-section">
          <span class="ro-label">Detected patterns</span>
          <div class="tags">${analysis.patterns.map(p => `<span class="tag">${p}</span>`).join('')}</div>
        </div>
        <div class="ro-section">
          <span class="ro-label">Support</span>
          <div class="tags">${analysis.supports.map(v => `<span class="tag green">${v}</span>`).join('')}</div>
        </div>
        <div class="ro-section">
          <span class="ro-label">Resistance</span>
          <div class="tags">${analysis.resistances.map(v => `<span class="tag red">${v}</span>`).join('')}</div>
        </div>
        <div class="ro-section">
          <span class="ro-label">Summary</span>
          <div class="summary">${analysis.summary}</div>
          <div class="meta">ANALYZED ${analysis.timestamp}</div>
        </div>

        <button class="primary-btn" id="analyzeAgain">Analyze another chart</button>
      </div>
    `;

    // the confidence bar's width is data: set through the CSSOM (a style attribute would need 'unsafe-inline')
    const confidence = Math.max(0, Math.min(100, Number(analysis.confidence) || 0));
    analysisPanel.querySelector('.segbar b').style.width = `${confidence}%`;

    const resetBtn = document.getElementById('analyzeAgain');
    resetBtn.addEventListener('click', () => {
      resetStage();
      setStatus('READY', 'idle');
      analysisPanel.innerHTML = IDLE_HTML;
      fileInput.value = '';
      previewSrc = '';
    });
  }

  // Zooming the staged chart must not reopen the file picker.
  stageImg.addEventListener('click', (e) => {
    e.stopPropagation();
    openModal(previewSrc);
  });

  let modalReturn = null;

  function openModal(src) {
    if (!src) return;
    modalReturn = document.activeElement;
    modalImg.src = src;
    imageModal.classList.add('open');
    imageModal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('dot-scroll-locked'); // the same lock the header drawer uses: the page behind does not scroll
    modalClose.focus();
  }

  modalClose.addEventListener('click', closeModal);
  imageModal.addEventListener('click', (e) => {
    if (e.target === imageModal) closeModal();
  });

  function closeModal() {
    imageModal.classList.remove('open');
    imageModal.setAttribute('aria-hidden', 'true');
    modalImg.src = BLANK;
    document.documentElement.classList.remove('dot-scroll-locked');
    if (modalReturn && modalReturn.focus) modalReturn.focus();
    modalReturn = null;
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && imageModal.classList.contains('open')) closeModal();
  });

  // The symbol and market fields sit inside the stage column: typing there must not reach the stage's own click handler.
  document.querySelector('.stage-foot')?.addEventListener('click', (e) => e.stopPropagation());
});
