#!/usr/bin/env node
// 冒烟测试：模拟最小浏览器环境，验证前端模块图加载与初始路由执行无运行时错误
import path from 'path';
import { pathToFileURL, fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const makeEl = () => ({
    innerHTML: '', value: '', textContent: '', style: {}, dataset: {},
    classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
    addEventListener() {}, removeEventListener() {},
    querySelector: () => makeEl(), querySelectorAll: () => [],
    appendChild() {}, insertAdjacentElement() {}, remove() {}, focus() {}, select() {},
    closest: () => null, setAttribute() {},
});
const els = {};

globalThis.document = {
    getElementById: (id) => (els[id] ??= makeEl()),
    querySelector: () => makeEl(),
    querySelectorAll: () => [],
    createElement: () => makeEl(),
    addEventListener() {},
    body: Object.assign(makeEl(), { style: {} }),
};
globalThis.window = { addEventListener() {}, location: { hash: '', protocol: 'http:' }, isSecureContext: false, confirm: () => true };
globalThis.location = window.location;
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
globalThis.performance = { now: () => 0 };
globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({}), text: async () => '' });
globalThis.QRCode = class { constructor() {} static CorrectLevel = { M: 1 }; };
globalThis.FileReader = class { readAsText() {} };
globalThis.AbortController = class { constructor() { this.signal = {}; } abort() {} };
globalThis.prompt = () => null;

try {
    await import(pathToFileURL(path.resolve(__dirname, '..', 'public', 'js', 'app.js')).href);
    // 允许异步页面渲染微任务执行
    await new Promise(r => setTimeout(r, 50));
    console.log('✓ 模块图加载与初始路由执行无异常');
} catch (e) {
    console.log('✗ 运行时错误: ' + e.message + '\n' + (e.stack || '').split('\n').slice(0, 4).join('\n'));
    process.exit(1);
}
