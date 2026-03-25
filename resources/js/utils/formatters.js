export function fmtUsd(v) {
    if (v === null || v === undefined) return '-';
    const abs = Math.abs(v).toFixed(2);
    return v >= 0 ? `+$${abs}` : `-$${abs}`;
}

export function pnlClass(v) {
    if (v > 0) return 'text-green-400';
    if (v < 0) return 'text-red-400';
    return 'text-gray-300';
}

export function fmtDate(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' +
           d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

export function shortId(id) {
    return id ? id.slice(0, 8) + '...' + id.slice(-6) : '-';
}

export function traderLabel(row) {
    const name = row.trader_name || row.name || '';
    if (!name || name.startsWith('0x') || name.length > 20) {
        const addr = row.trader_wallet || row.address || '';
        return addr ? addr.slice(0, 8) + '...' : '-';
    }
    return name;
}

export function traderUrl(row) {
    const slug = row.trader_slug || row.profile_slug;
    if (slug) return `https://polymarket.com/@${slug}`;
    const addr = row.trader_wallet || row.address;
    if (addr) return `https://polymarket.com/portfolio/${addr}`;
    return null;
}
