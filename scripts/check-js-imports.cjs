#!/usr/bin/env node
// 核对 public/js 所有模块的命名导入与目标模块的实际导出是否匹配
const fs = require('fs');
const path = require('path');

const base = path.join(__dirname, '..', 'public', 'js');
const files = [];
(function walk(dir) {
    for (const f of fs.readdirSync(dir)) {
        const full = path.join(dir, f);
        if (fs.statSync(full).isDirectory()) walk(full);
        else if (f.endsWith('.js')) files.push(full);
    }
})(base);

// 收集每个模块的命名导出
const exportsByFile = {};
for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    const names = [...src.matchAll(/export\s+(?:async\s+)?(?:function|const|let|class)\s+([A-Za-z_$][\w$]*)/g)].map(m => m[1]);
    exportsByFile[file] = new Set(names);
}

// 核对每个文件的 import { ... } from './xxx.js'
let errors = 0;
for (const file of files) {
    const src = fs.readFileSync(file, 'utf8');
    for (const m of src.matchAll(/import\s*\{([^}]+)\}\s*from\s*['"](\.[^'"]+)['"]/g)) {
        const imported = m[1].split(',').map(s => s.trim().split(/\s+as\s+/)[0]).filter(Boolean);
        const targetPath = path.resolve(path.dirname(file), m[2]);
        const targetExports = exportsByFile[targetPath];
        if (!targetExports) { console.log(`✗ ${path.relative(base, file)}: 目标模块不存在 ${m[2]}`); errors++; continue; }
        for (const name of imported) {
            if (!targetExports.has(name)) {
                console.log(`✗ ${path.relative(base, file)}: '${name}' 不存在于 ${path.relative(base, targetPath)}（实际导出: ${[...targetExports].join(', ')}）`);
                errors++;
            }
        }
    }
}

console.log(errors === 0 ? '✓ 全部命名导入与导出匹配' : `共 ${errors} 处不匹配`);
process.exit(errors === 0 ? 0 : 1);
