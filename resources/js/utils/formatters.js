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

export function isArbTrade(row) {
    const wallet = row.trader_wallet || row.copied_from_wallet || row.address || '';
    return wallet.startsWith('arb:');
}

export function traderLabel(row) {
    if (isArbTrade(row)) return 'Arb Scanner';
    const name = row.trader_name || row.name || '';
    if (!name || name.startsWith('0x') || name.length > 20) {
        const addr = row.trader_wallet || row.address || '';
        return addr ? addr.slice(0, 8) + '...' : '-';
    }
    return name;
}

export function marketUrl(row) {
    if (row.market_slug) return `https://polymarket.com/event/${row.market_slug}`;
    return null;
}

export function timeAgo(ts) {
    if (!ts) return '';
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return fmtDate(ts);
}

export function traderUrl(row) {
    if (isArbTrade(row)) return null;
    const slug = row.trader_slug || row.profile_slug;
    if (slug) return `https://polymarket.com/@${slug}`;
    const addr = row.trader_wallet || row.address;
    if (addr) return `https://polymarket.com/portfolio/${addr}`;
    return null;
}
