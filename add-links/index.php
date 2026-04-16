<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Component Links</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px 20px;
        }
        h1 { color: #00d4ff; margin-bottom: 20px; font-size: 1.6rem; }
        .component-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 20px;
        }
        .component-selector button {
            padding: 8px 16px;
            border: 2px solid #333;
            background: #16213e;
            color: #ccc;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .component-selector button.active {
            border-color: #00d4ff;
            color: #00d4ff;
            background: #1a1a3e;
        }
        .component-selector button:hover { border-color: #00d4ff88; }
        .card {
            background: #16213e;
            border-radius: 12px;
            padding: 30px;
            width: 100%;
            max-width: 650px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }
        .header-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .component-title {
            font-size: 0.85rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .product-counter {
            font-size: 0.85rem;
            color: #00d4ff;
            font-weight: bold;
        }
        .progress-bar-outer {
            width: 100%;
            height: 6px;
            background: #0f3460;
            border-radius: 3px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .progress-bar-inner {
            height: 100%;
            background: linear-gradient(90deg, #00d4ff, #0090ff);
            border-radius: 3px;
            transition: width 0.3s;
        }
        .search-text {
            background: #0f3460;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 1.15rem;
            color: #fff;
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            word-break: break-word;
        }
        .search-text span { flex: 1; }
        .copy-btn {
            background: #00d4ff;
            border: none;
            color: #1a1a2e;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.8rem;
            white-space: nowrap;
            margin-left: 12px;
            transition: all 0.2s;
        }
        .copy-btn:hover { background: #33e0ff; }
        .copy-btn.copied { background: #00e676; }
        .extra-info {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 16px;
            padding: 0 4px;
        }
        label {
            display: block;
            font-size: 0.85rem;
            color: #aaa;
            margin-bottom: 4px;
            margin-top: 14px;
        }
        input[type="text"], input[type="url"] {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #333;
            border-radius: 8px;
            background: #0f3460;
            color: #e0e0e0;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus { border-color: #00d4ff; }
        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }
        .btn-row button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            transition: all 0.2s;
        }
        .save-btn { background: #00d4ff; color: #1a1a2e; }
        .save-btn:hover { background: #33e0ff; }
        .skip-btn { background: #333; color: #ccc; }
        .skip-btn:hover { background: #444; }
        .save-btn:disabled { background: #555; color: #888; cursor: not-allowed; }
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(80px);
            background: #00e676;
            color: #1a1a2e;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: bold;
            opacity: 0;
            transition: all 0.3s;
            z-index: 999;
        }
        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        .toast.error { background: #ff5252; color: #fff; }
        .done-msg {
            text-align: center;
            padding: 40px 0;
            font-size: 1.2rem;
            color: #00e676;
        }
        .stats {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 0.8rem;
            color: #888;
        }
        .stats span { color: #00d4ff; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Component Link Editor</h1>

    <div class="component-selector" id="componentSelector"></div>

    <div class="stats" id="stats"></div>

    <div class="card" id="card">
        <div class="header-info">
            <div class="component-title" id="componentTitle">Loading...</div>
            <div class="product-counter" id="productCounter"></div>
        </div>
        <div class="progress-bar-outer">
            <div class="progress-bar-inner" id="progressBar" style="width: 0%"></div>
        </div>

        <div class="search-text" id="searchTextBox">
            <span id="searchText">Loading...</span>
            <button class="copy-btn" id="copyBtn" onclick="copySearchText()">COPY</button>
        </div>
        <div class="extra-info" id="extraInfo"></div>

        <label for="imageUrl">Image URL</label>
        <input type="url" id="imageUrl" placeholder="Paste image URL here..." />

        <label for="link">Product Link</label>
        <input type="url" id="link" placeholder="Paste product link here..." />

        <div class="btn-row">
            <button class="skip-btn" onclick="skipProduct()">SKIP</button>
            <button class="save-btn" id="saveBtn" onclick="saveProduct()">SAVE & NEXT</button>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const COMPONENTS = ['case', 'cooler', 'cpu', 'gpu', 'motherboard', 'psu', 'ram', 'storage'];
        let currentComponent = localStorage.getItem('addlinks_component') || 'case';
        let currentIndex = 0;
        let data = [];
        let totalCount = 0;
        let filledCount = 0;

        // Build component selector buttons
        const selectorEl = document.getElementById('componentSelector');
        COMPONENTS.forEach(c => {
            const btn = document.createElement('button');
            btn.textContent = c.toUpperCase();
            btn.dataset.component = c;
            btn.onclick = () => switchComponent(c);
            selectorEl.appendChild(btn);
        });

        function switchComponent(comp) {
            currentComponent = comp;
            localStorage.setItem('addlinks_component', comp);
            document.querySelectorAll('.component-selector button').forEach(b => {
                b.classList.toggle('active', b.dataset.component === comp);
            });
            loadComponent();
        }

        async function loadComponent() {
            try {
                const resp = await fetch(`../data/components/${currentComponent}.json?_=${Date.now()}`);
                data = await resp.json();
                totalCount = data.length;
                filledCount = data.filter(d => d.image_url && d.link).length;

                // Find first item without image_url or link
                currentIndex = data.findIndex(d => !d.image_url || !d.link);
                if (currentIndex === -1) currentIndex = data.length; // all done

                renderProduct();
            } catch (err) {
                showToast('Error loading data: ' + err.message, true);
            }
        }

        function renderProduct() {
            // Update stats
            filledCount = data.filter(d => d.image_url && d.link).length;
            document.getElementById('stats').innerHTML =
                `Filled: <span>${filledCount}</span> / ${totalCount} &nbsp;|&nbsp; Remaining: <span>${totalCount - filledCount}</span>`;

            // Update selector active state
            document.querySelectorAll('.component-selector button').forEach(b => {
                b.classList.toggle('active', b.dataset.component === currentComponent);
            });

            if (currentIndex >= data.length) {
                document.getElementById('card').innerHTML =
                    '<div class="done-msg">All products in this component have links! &#127881;</div>';
                return;
            }

            const item = data[currentIndex];
            const searchQuery = `${item.brand} ${item.model} ${item.name}`;

            document.getElementById('componentTitle').textContent = currentComponent.toUpperCase();
            document.getElementById('productCounter').textContent = `#${currentIndex + 1} of ${totalCount}`;

            const pct = totalCount > 0 ? (filledCount / totalCount * 100) : 0;
            document.getElementById('progressBar').style.width = pct + '%';

            document.getElementById('searchText').textContent = searchQuery;

            // Show extra info (all other fields)
            const skipFields = ['name', 'brand', 'model', 'image_url', 'link'];
            const extras = Object.entries(item)
                .filter(([k]) => !skipFields.includes(k))
                .map(([k, v]) => `${k}: ${v}`)
                .join(' &bull; ');
            document.getElementById('extraInfo').innerHTML = extras;

            document.getElementById('imageUrl').value = item.image_url || '';
            document.getElementById('link').value = item.link || '';

            // Reset copy button
            document.getElementById('copyBtn').textContent = 'COPY';
            document.getElementById('copyBtn').classList.remove('copied');
        }

        function copySearchText() {
            const text = document.getElementById('searchText').textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.getElementById('copyBtn');
                btn.textContent = 'COPIED!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = 'COPY';
                    btn.classList.remove('copied');
                }, 1500);
            });
        }

        async function saveProduct() {
            const imageUrl = document.getElementById('imageUrl').value.trim();
            const link = document.getElementById('link').value.trim();

            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = 'SAVING...';

            try {
                const resp = await fetch('save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        component: currentComponent,
                        index: currentIndex,
                        image_url: imageUrl,
                        link: link
                    })
                });
                const result = await resp.json();

                if (result.success) {
                    // Update local data
                    data[currentIndex].image_url = imageUrl;
                    data[currentIndex].link = link;
                    showToast('Saved! Moving to next...');
                    moveToNext();
                } else {
                    showToast(result.error || 'Save failed', true);
                }
            } catch (err) {
                showToast('Error: ' + err.message, true);
            }

            saveBtn.disabled = false;
            saveBtn.textContent = 'SAVE & NEXT';
        }

        function skipProduct() {
            moveToNext();
        }

        function moveToNext() {
            // Find next unfilled item after current
            let nextIdx = -1;
            for (let i = currentIndex + 1; i < data.length; i++) {
                if (!data[i].image_url || !data[i].link) {
                    nextIdx = i;
                    break;
                }
            }
            // If not found, wrap around from beginning
            if (nextIdx === -1) {
                for (let i = 0; i < currentIndex; i++) {
                    if (!data[i].image_url || !data[i].link) {
                        nextIdx = i;
                        break;
                    }
                }
            }

            if (nextIdx === -1) {
                currentIndex = data.length; // all done
            } else {
                currentIndex = nextIdx;
            }

            renderProduct();
            // Focus image input for fast workflow
            const imgInput = document.getElementById('imageUrl');
            if (imgInput) imgInput.focus();
        }

        function showToast(msg, isError = false) {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast show' + (isError ? ' error' : '');
            setTimeout(() => toast.className = 'toast', 2000);
        }

        // Keyboard shortcut: Ctrl+Enter to save
        document.addEventListener('keydown', e => {
            if (e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                saveProduct();
            }
        });

        // Init
        switchComponent(currentComponent);
    </script>
</body>
</html>
