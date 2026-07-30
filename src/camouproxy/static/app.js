document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const tabButtons = document.querySelectorAll('.tab-btn');
    const actionsNormal = document.getElementById('actions-normal');
    const actionsNetflix = document.getElementById('actions-netflix');

    const proxyInput = document.getElementById('proxy-input');
    const btnTestProxy = document.getElementById('btn-test-proxy');
    const toggleBlockMedia = document.getElementById('toggle-block-media');

    const btnLaunchNormal = document.getElementById('btn-launch-normal');
    const btnLaunchNetflix = document.getElementById('btn-launch-netflix');
    const btnClearCookies = document.getElementById('btn-clear-cookies');

    const statusBanner = document.getElementById('status-banner');
    const statusMessage = document.getElementById('status-message');

    // Metadata Elements
    const metaBadge = document.getElementById('meta-status-badge');
    const metaIp = document.getElementById('meta-ip');
    const metaCountry = document.getElementById('meta-country');
    const metaCity = document.getElementById('meta-city');
    const metaIsp = document.getElementById('meta-isp');
    const metaTimezone = document.getElementById('meta-timezone');
    const metaLatency = document.getElementById('meta-latency');

    // History Table Elements
    const historyTableBody = document.getElementById('history-table-body');
    const btnRefreshHistory = document.getElementById('btn-refresh-history');

    let currentMode = 'normal';
    let currentMetadata = null;

    // Helper: Flag Emoji
    function getFlagEmoji(code) {
        if (!code || code.length !== 2) return '🌐';
        const codePoints = code
            .toUpperCase()
            .split('')
            .map(char => 127397 + char.charCodeAt(0));
        return String.fromCodePoint(...codePoints);
    }

    // Helper: Show Status Message
    function showStatus(msg, type = 'info') {
        statusBanner.className = `status-banner status-${type}`;
        statusMessage.textContent = msg;
        statusBanner.classList.remove('hidden');
    }

    // Helper: Set Button Loading State
    function setBtnLoading(btn, isLoading, loadingText = 'Processing...') {
        const textSpan = btn.querySelector('.btn-text') || btn.querySelector('span');
        const spinnerSpan = btn.querySelector('.spinner');
        if (isLoading) {
            btn.disabled = true;
            if (textSpan) textSpan.dataset.original = textSpan.textContent;
            if (textSpan) textSpan.textContent = loadingText;
            if (spinnerSpan) spinnerSpan.classList.remove('hidden');
        } else {
            btn.disabled = false;
            if (textSpan && textSpan.dataset.original) textSpan.textContent = textSpan.dataset.original;
            if (spinnerSpan) spinnerSpan.classList.add('hidden');
        }
    }

    // Tab Switching
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            tabButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentMode = btn.dataset.tab;

            if (currentMode === 'normal') {
                actionsNormal.classList.remove('hidden');
                actionsNetflix.classList.add('hidden');
            } else {
                actionsNormal.classList.add('hidden');
                actionsNetflix.classList.remove('hidden');
            }
            showStatus(`Switched to Mode: ${currentMode === 'normal' ? 'Normal (General Browser)' : 'Netflix (Auto-Trial Finder)'}`, 'info');
        });
    });

    // Update Metadata Display
    function updateMetadataUI(meta) {
        currentMetadata = meta;
        if (!meta) {
            metaBadge.textContent = 'Not Tested';
            metaBadge.className = 'badge badge-neutral';
            metaIp.textContent = '---.---.---.---';
            metaCountry.textContent = '--';
            metaCity.textContent = '--';
            metaIsp.textContent = '--';
            metaTimezone.textContent = '--';
            metaLatency.textContent = '-- ms';
            return;
        }

        metaBadge.textContent = 'Connected & Active';
        metaBadge.className = 'badge badge-success';
        metaIp.textContent = meta.ip || 'N/A';
        const flag = getFlagEmoji(meta.country_code);
        metaCountry.textContent = `${flag} ${meta.country || meta.country_code || 'Unknown'}`;
        metaCity.textContent = meta.city || 'Unknown';
        metaIsp.textContent = meta.isp || 'Unknown';
        metaTimezone.textContent = meta.timezone || 'UTC';
        metaLatency.textContent = `${meta.latency_ms ?? 0} ms`;
    }

    // Test Proxy Endpoint Call
    async function testProxy() {
        const proxyStr = proxyInput.value.trim();
        if (!proxyStr) {
            showStatus('Please enter a valid proxy string!', 'error');
            return null;
        }

        setBtnLoading(btnTestProxy, true, 'Testing Proxy...');
        showStatus('Connecting through proxy & detecting IP metadata...', 'info');

        try {
            const resp = await fetch('/api/test-proxy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ proxy: proxyStr })
            });

            const data = await resp.json();
            if (!resp.ok) {
                throw new Error(data.detail || 'Proxy test failed');
            }

            updateMetadataUI(data.metadata);
            showStatus(`Proxy Verified! IP: ${data.metadata.ip} (${data.metadata.country || ''}) - Latency: ${data.metadata.latency_ms}ms`, 'success');
            loadHistory();
            return data.metadata;
        } catch (err) {
            updateMetadataUI(null);
            showStatus(`Error: ${err.message}`, 'error');
            return null;
        } finally {
            setBtnLoading(btnTestProxy, false);
        }
    }

    btnTestProxy.addEventListener('click', testProxy);

    // Launch Browser Endpoint Call
    async function launchBrowser(modeOverride) {
        const proxyStr = proxyInput.value.trim();
        if (!proxyStr) {
            showStatus('Please enter a proxy string before launching!', 'error');
            return;
        }

        const targetMode = modeOverride || currentMode;
        const blockMedia = toggleBlockMedia.checked;

        const launchBtn = targetMode === 'netflix' ? btnLaunchNetflix : btnLaunchNormal;
        setBtnLoading(launchBtn, true, 'Launching...');
        showStatus(`Initiating Camoufox session in ${targetMode.toUpperCase()} mode...`, 'info');

        try {
            const resp = await fetch(`/api/launch?mode=${targetMode}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    proxy: proxyStr,
                    block_media: blockMedia,
                    mode: targetMode
                })
            });

            const data = await resp.json();
            if (!resp.ok) {
                throw new Error(data.detail || 'Launch failed');
            }

            if (data.metadata) {
                updateMetadataUI(data.metadata);
            }
            showStatus(`🚀 ${data.message}! Check anti-detect browser window.`, 'success');
            loadHistory();
        } catch (err) {
            showStatus(`Launch Error: ${err.message}`, 'error');
        } finally {
            setBtnLoading(launchBtn, false);
        }
    }

    btnLaunchNormal.addEventListener('click', () => launchBrowser('normal'));
    btnLaunchNetflix.addEventListener('click', () => launchBrowser('netflix'));

    // Clear Cookies Button Action
    btnClearCookies.addEventListener('click', () => {
        window.open('https://www.netflix.com/clearcookies', '_blank');
        showStatus('Opened https://netflix.com/clearcookies in new tab.', 'info');
    });

    // Load History Endpoint Call
    async function loadHistory() {
        try {
            const resp = await fetch('/api/history');
            if (!resp.ok) throw new Error('Failed to fetch history');

            const items = await resp.json();
            if (!items || items.length === 0) {
                historyTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No proxy history recorded yet.</td>
                    </tr>
                `;
                return;
            }

            historyTableBody.innerHTML = items.map(item => {
                const flag = getFlagEmoji(item.country_code);
                const rawProxyEscaped = item.raw_proxy ? item.raw_proxy.replace(/"/g, '&quot;') : '';
                return `
                    <tr>
                        <td><span class="proxy-code" title="${rawProxyEscaped}">${item.raw_proxy || 'N/A'}</span></td>
                        <td><strong>${item.ip || 'Unknown'}</strong></td>
                        <td>${flag} ${item.country || item.country_code || 'Unknown'}</td>
                        <td>${item.isp || 'N/A'}</td>
                        <td><small class="text-muted">${item.last_used || 'Just now'}</small></td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-primary btn-quick-launch" data-proxy="${rawProxyEscaped}">
                                    🚀 Quick Launch
                                </button>
                                <button class="btn btn-sm btn-danger btn-delete-history" data-id="${item.id}">
                                    🗑️ Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            // Attach event listeners for Quick Launch and Delete
            document.querySelectorAll('.btn-quick-launch').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const proxy = e.target.closest('.btn-quick-launch').dataset.proxy;
                    proxyInput.value = proxy;
                    testProxy().then(meta => {
                        if (meta) {
                            launchBrowser(currentMode);
                        }
                    });
                });
            });

            document.querySelectorAll('.btn-delete-history').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const id = e.target.closest('.btn-delete-history').dataset.id;
                    if (confirm('Delete this proxy record from history?')) {
                        try {
                            const delResp = await fetch(`/api/history/${id}`, { method: 'DELETE' });
                            if (delResp.ok) {
                                showStatus(`Proxy record #${id} deleted.`, 'info');
                                loadHistory();
                            }
                        } catch (err) {
                            showStatus(`Delete error: ${err.message}`, 'error');
                        }
                    }
                });
            });
        } catch (err) {
            historyTableBody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">Error loading history: ${err.message}</td>
                </tr>
            `;
        }
    }

    btnRefreshHistory.addEventListener('click', loadHistory);

    // Initial Load
    loadHistory();
});
