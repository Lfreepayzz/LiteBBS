const pending = new Map();

function loadAsset({type, url}, root) {
    if (typeof url !== 'string' || !/^\/plugin-assets\/(?:_common\/assets|[a-z][a-z0-9_-]+\/[A-Za-z0-9_./-]+)\.(css|js)\?v=[A-Za-z0-9_-]+$/.test(url)) {
        throw new TypeError('Invalid extension asset URL.');
    }
    const source = new URL(url, root.baseURI);
    if (source.origin !== root.location.origin || source.pathname !== url.split('?')[0] || !source.pathname.endsWith('.' + type) || !['css', 'js'].includes(type)) {
        throw new TypeError('Invalid extension asset type.');
    }
    const key = source.href;
    if (pending.has(key)) return pending.get(key);
    const existing = Array.from(root.querySelectorAll('link[rel="stylesheet"], script[src]'))
        .some(node => (node.href || node.src) === key);
    if (existing) return Promise.resolve();

    const node = root.createElement(type === 'css' ? 'link' : 'script');
    const loaded = new Promise((resolve, reject) => {
        node.addEventListener('load', resolve, {once: true});
        node.addEventListener('error', () => {
            pending.delete(key);
            node.remove();
            reject(new Error('Extension asset failed to load.'));
        }, {once: true});
        if (type === 'css') {
            node.rel = 'stylesheet';
            node.href = key;
        } else {
            node.src = key;
        }
        root.head.append(node);
    });
    pending.set(key, loaded);
    return loaded;
}

export async function loadExtensionAssets(assets = []) {
    for (const asset of assets) await loadAsset(asset, document);
}
